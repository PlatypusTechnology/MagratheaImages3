<?php
namespace MagratheaImages3\R2;

use Magrathea2\Config;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Images\PathManager;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;

/**
 * Orchestrates one run of the push or reconcile job: owns the Sentry
 * check-in for that run and delegates the actual work to `BackupControl`
 * (state) and `R2Client` (the wire). Callable from `backup.php`, or directly
 * from a test with fakes injected for both collaborators.
 */
class BackupRunner {

	private const CONTENT_TYPES = [
		"jpg" => "image/jpeg",
		"jpeg" => "image/jpeg",
		"png" => "image/png",
		"webp" => "image/webp",
		"bmp" => "image/bmp",
		"wbmp" => "image/vnd.wap.wbmp",
		"gif" => "image/gif",
		"svg" => "image/svg+xml",
	];

	private ?BackupControl $control;
	private ?R2Client $client;

	// Both collaborators are built lazily, on first actual use: constructing a
	// BackupRunner must never build a real R2Client (which stands up an
	// AsyncAws\S3\S3Client) when the caller only needed the disabled-config
	// short-circuit in Push()/Reconcile().
	public function __construct(?BackupControl $control = null, ?R2Client $client = null) {
		$this->control = $control;
		$this->client = $client;
	}

	private function Control(): BackupControl {
		return $this->control ??= new BackupControl();
	}

	private function Client(): R2Client {
		return $this->client ??= new R2Client();
	}

	private static function MonitorSlug(string $job): string {
		return "mi3-".Config::Instance()->GetEnvironment()."-backup-".$job;
	}

	private static function CheckInStart(string $job, string $schedule, int $marginMinutes, int $maxRuntimeMinutes): ?string {
		if(!function_exists("Sentry\\captureCheckIn")) return null;
		$monitorConfig = new MonitorConfig(MonitorSchedule::crontab($schedule), $marginMinutes, $maxRuntimeMinutes);
		return \Sentry\captureCheckIn(self::MonitorSlug($job), CheckInStatus::inProgress(), null, $monitorConfig);
	}

	private static function CheckInFinish(string $job, ?string $checkInId, bool $ok, float $duration): void {
		if(!function_exists("Sentry\\captureCheckIn")) return;
		$status = $ok ? CheckInStatus::ok() : CheckInStatus::error();
		\Sentry\captureCheckIn(self::MonitorSlug($job), $status, $duration, null, $checkInId);
	}

	private static function ReportFailures(string $job, array $failures): void {
		if(empty($failures) || !function_exists("Sentry\\captureMessage")) return;
		$sample = array_slice($failures, 0, 10);
		\Sentry\withScope(function($scope) use ($job, $failures, $sample) {
			$scope->setExtra("failure_count", count($failures));
			$scope->setExtra("sample", $sample);
			\Sentry\captureMessage("R2 backup ".$job.": ".count($failures)." failure(s) this run");
		});
	}

	private static function ContentTypeFor(Images $image): string {
		if(!empty($image->file_type)) return $image->file_type;
		$ext = strtolower((string)$image->extension);
		return self::CONTENT_TYPES[$ext] ?? "application/octet-stream";
	}

	/**
	 * Job 1: uploads every pending row (never backed up, attempts under the
	 * cap), oldest first. Never throws for a single bad row -- a missing file
	 * or a failed upload is recorded on that row and the run continues.
	 */
	public function Push(?int $limit = null, bool $verbose = false): array {
		if(!R2Config::IsEnabled()) {
			if($verbose) echo "R2 backup disabled, nothing to do.\n";
			return ["enabled" => false];
		}
		$batchSize = $limit ?? R2Config::GetBatchSize();
		$maxAttempts = R2Config::GetMaxAttempts();
		$checkInId = self::CheckInStart("push", "7 * * * *", 15, 30);
		$start = microtime(true);

		$images = $this->Control()->GetPending($batchSize, $maxAttempts);
		$succeeded = 0;
		$failed = 0;
		$failures = [];
		foreach($images as $image) {
			try {
				$this->PushOne($image);
				$succeeded++;
				if($verbose) echo "OK   #".$image->id."\n";
			} catch(\Throwable $ex) {
				$failed++;
				$failures[] = ["id" => $image->id, "error" => $ex->getMessage()];
				$this->Control()->MarkFailure($image, $ex->getMessage());
				if($verbose) echo "FAIL #".$image->id.": ".$ex->getMessage()."\n";
			}
		}
		self::ReportFailures("push", $failures);

		$duration = microtime(true) - $start;
		self::CheckInFinish("push", $checkInId, $failed === 0, $duration);

		return [
			"enabled" => true,
			"total" => count($images),
			"succeeded" => $succeeded,
			"failed" => $failed,
		];
	}

	private function PushOne(Images $image): void {
		$localPath = PathManager::GetRawFolder($image->folder).$image->filename;
		$key = R2Client::ObjectKey($image->folder, $image->filename);
		$etag = $this->Client()->PutFile($localPath, $key, self::ContentTypeFor($image), (string)$image->uuid);
		$this->Control()->MarkSuccess($image, $etag);
	}

	/**
	 * Job 2: compares the bucket's actual contents against the DB and fixes
	 * up both directions -- deletes R2 orphans, resets drifted rows so the
	 * next push re-uploads them. Guarded against acting on a bad read: see
	 * the aborts below, all of which stop before any delete or state change.
	 */
	public function Reconcile(bool $dryRun = false): array {
		if(!R2Config::IsEnabled()) {
			return ["enabled" => false];
		}
		$checkInId = self::CheckInStart("reconcile", "23 4 * * *", 60, 60);
		$start = microtime(true);

		try {
			$result = $this->ReconcileOnce($dryRun);
			self::CheckInFinish("reconcile", $checkInId, true, microtime(true) - $start);
			return $result;
		} catch(R2Exception $ex) {
			self::ReportFailures("reconcile", [["error" => $ex->getMessage()]]);
			self::CheckInFinish("reconcile", $checkInId, false, microtime(true) - $start);
			return ["enabled" => true, "aborted" => true, "reason" => $ex->getMessage()];
		}
	}

	private function ReconcileOnce(bool $dryRun): array {
		$dbKeys = $this->Control()->GetAllKeys();
		if(empty($dbKeys)) {
			throw new R2Exception("aborting: images query returned zero rows");
		}

		try {
			$r2Keys = $this->Client()->ListAllKeys();
		} catch(\Throwable $ex) {
			throw new R2Exception("aborting: listing failed: ".$ex->getMessage());
		}

		$r2KeySet = array_flip($r2Keys);

		$orphanKeys = [];
		foreach($r2Keys as $key) {
			if(!isset($dbKeys[$key])) $orphanKeys[] = $key;
		}
		$deleteCap = R2Config::GetDeleteCap();
		// "Whichever is smaller" of the flat cap and 5% of what's actually in the
		// bucket -- a bad query that makes everything look orphaned should never
		// be able to delete more than this, no matter how large delete_cap is set.
		$threshold = min($deleteCap, (int)floor(count($r2Keys) * 0.05));
		if(count($orphanKeys) > $threshold) {
			throw new R2Exception(
				"aborting: ".count($orphanKeys)." orphan(s) exceeds the delete cap (".$deleteCap.
				") or 5% of ".count($r2Keys)." listed object(s)"
			);
		}

		$backedUpKeys = $this->Control()->GetBackedUpKeys();
		$driftedIds = [];
		foreach($backedUpKeys as $key => $id) {
			if(!isset($r2KeySet[$key])) $driftedIds[] = $id;
		}

		if($dryRun) {
			return [
				"enabled" => true,
				"dry_run" => true,
				"orphans" => count($orphanKeys),
				"drifted" => count($driftedIds),
			];
		}

		if(!empty($orphanKeys)) $this->Client()->DeleteKeys($orphanKeys);
		$reset = $this->Control()->ResetForRetry($driftedIds);

		return [
			"enabled" => true,
			"dry_run" => false,
			"orphans_deleted" => count($orphanKeys),
			"drifted_reset" => $reset,
		];
	}

}
