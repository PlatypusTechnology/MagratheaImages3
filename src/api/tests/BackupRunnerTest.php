<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\R2\BackupRunner;
use MagratheaImages3\R2\BackupControl;
use MagratheaImages3\R2\R2Client;
use MagratheaImages3\R2\R2Config;
use MagratheaImages3\R2\R2Exception;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Images\PathManager;

include_once(__DIR__ . "/../_inc.php");

/**
 * Records what it was asked to do instead of touching S3; R2Client isn't an
 * interface, so this overrides the wire-facing methods and skips the parent
 * constructor entirely (no real S3Client, no config needed).
 */
class FakeR2Client extends R2Client {
	public array $putCalls = [];
	public array $deletedKeys = [];
	public array $listKeysToReturn = [];
	public ?\Throwable $listException = null;
	/** @var array<string,true> keys that should fail PutFile with an R2Exception */
	public array $failKeys = [];

	public function __construct() {
	}

	public function PutFile(string $localPath, string $key, string $contentType, string $imageUuid): string {
		$this->putCalls[] = compact("localPath", "key", "contentType", "imageUuid");
		// Mirrors the real R2Client's contract (missing file -> R2Exception) so
		// BackupRunner's per-row error handling can be exercised without a real
		// S3Client; the file-existence check itself is R2ClientTest's job.
		if(!file_exists($localPath)) {
			throw new R2Exception("local file does not exist: ".$localPath);
		}
		if(isset($this->failKeys[$key])) {
			throw new R2Exception("simulated failure for ".$key);
		}
		return "fake-etag-".$key;
	}

	public function ListAllKeys(): array {
		if($this->listException) throw $this->listException;
		return $this->listKeysToReturn;
	}

	public function DeleteKeys(array $keys): void {
		$this->deletedKeys = array_merge($this->deletedKeys, $keys);
	}
}

/**
 * Records mark-success/mark-failure/reset calls instead of touching the DB.
 */
class FakeBackupControl extends BackupControl {
	public array $pendingToReturn = [];
	public array $allKeysToReturn = [];
	public array $backedUpKeysToReturn = [];
	public array $successes = [];
	public array $failures = [];
	public array $resetIds = [];

	public function __construct() {
	}

	public function GetPending(int $limit, int $maxAttempts): array {
		return $this->pendingToReturn;
	}

	public function MarkSuccess(Images $image, string $etag): void {
		$this->successes[] = ["id" => $image->id, "etag" => $etag];
	}

	public function MarkFailure(Images $image, string $error): void {
		$this->failures[] = ["id" => $image->id, "error" => $error];
	}

	public function GetAllKeys(): array {
		return $this->allKeysToReturn;
	}

	public function GetBackedUpKeys(): array {
		return $this->backedUpKeysToReturn;
	}

	public function ResetForRetry(array $ids): int {
		$this->resetIds = $ids;
		return count($ids);
	}
}

class BackupRunnerTest extends TestCase
{
	private string $scratchDir;
	private string $mediaDir;

	// Captured once and always restored verbatim in tearDown -- Config is a
	// process-wide singleton, so mutating "the current environment's medias_path"
	// without restoring the exact original array would leak into every other
	// test in the suite.
	private static ?array $originalConfigs = null;

	public function setUp(): void
	{
		$this->scratchDir = sys_get_temp_dir()."/backuprunner-test-".uniqid();
		mkdir($this->scratchDir, 0755, true);
		R2Config::SetConfigRootForTests($this->scratchDir);
		file_put_contents(
			$this->scratchDir."/r2.conf",
			"[scripttest]\n\tenabled = true\n\taccount_id = \"acc\"\n\tbucket = \"test-bucket\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n"
		);

		$this->mediaDir = $this->scratchDir."/medias";
		mkdir($this->mediaDir."/folder1/raw", 0755, true);

		if(self::$originalConfigs === null) {
			self::$originalConfigs = \Magrathea2\Config::Instance()->GetConfig();
		}
	}

	public function tearDown(): void
	{
		\Magrathea2\Config::Instance()->SetConfig(self::$originalConfigs);
		R2Config::SetConfigRootForTests(null);
		$this->rrmdir($this->scratchDir);
	}

	// PathManager::GetRawFolder() reads medias_path from magrathea.conf, which
	// points at a real (non-writable-by-us) path outside the test sandbox.
	// PushOne() only ever reads that path, so pointing medias_path at a scratch
	// dir here keeps file_exists()/PutFile() well-defined without touching the
	// project's real media store. Always paired with the tearDown restore above.
	private function UseScratchMediasPath(): void
	{
		$configs = self::$originalConfigs;
		$configs["scripttest"]["medias_path"] = $this->mediaDir;
		\Magrathea2\Config::Instance()->SetConfig($configs);
	}

	private function rrmdir(string $dir): void
	{
		foreach(glob($dir."/*") ?: [] as $path) {
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		@rmdir($dir);
	}

	private function makeImage(int $id, string $filename, string $folder = "folder1"): Images
	{
		$image = new Images();
		$image->id = $id;
		$image->uuid = "uuid-".$id;
		$image->folder = $folder;
		$image->filename = $filename;
		$image->extension = "jpg";
		$image->file_type = "image/jpeg";
		return $image;
	}

	public function testPushReturnsDisabledWhenNotConfigured()
	{
		R2Config::SetConfigRootForTests($this->scratchDir."-empty");
		$runner = new BackupRunner(new FakeBackupControl(), new FakeR2Client());
		$result = $runner->Push();
		$this->assertSame(["enabled" => false], $result);
	}

	public function testPushUploadsPendingImagesAndMarksSuccess()
	{
		file_put_contents($this->mediaDir."/folder1/raw/1_a.jpg", "content-a");
		file_put_contents($this->mediaDir."/folder1/raw/2_b.jpg", "content-b");
		$this->UseScratchMediasPath();

		$control = new FakeBackupControl();
		$control->pendingToReturn = [
			$this->makeImage(1, "1_a.jpg"),
			$this->makeImage(2, "2_b.jpg"),
		];
		$client = new FakeR2Client();

		$runner = new BackupRunner($control, $client);
		$result = $runner->Push();

		$this->assertSame(2, $result["total"]);
		$this->assertSame(2, $result["succeeded"]);
		$this->assertSame(0, $result["failed"]);
		$this->assertCount(2, $control->successes);
		$this->assertSame("folder1/raw/1_a.jpg", $client->putCalls[0]["key"]);
	}

	public function testPushMarksFailureWhenLocalFileMissing()
	{
		$this->UseScratchMediasPath();

		$control = new FakeBackupControl();
		$control->pendingToReturn = [$this->makeImage(9, "9_missing.jpg")];
		$client = new FakeR2Client();

		$runner = new BackupRunner($control, $client);
		$result = $runner->Push();

		$this->assertSame(1, $result["failed"]);
		$this->assertSame(0, $result["succeeded"]);
		$this->assertCount(1, $control->failures);
		$this->assertSame(9, $control->failures[0]["id"]);
	}

	public function testPushMarksFailureWhenUploadThrows()
	{
		file_put_contents($this->mediaDir."/folder1/raw/1_a.jpg", "content-a");
		$this->UseScratchMediasPath();

		$control = new FakeBackupControl();
		$control->pendingToReturn = [$this->makeImage(1, "1_a.jpg")];
		$client = new FakeR2Client();
		$client->failKeys["folder1/raw/1_a.jpg"] = true;

		$runner = new BackupRunner($control, $client);
		$result = $runner->Push();

		$this->assertSame(1, $result["failed"]);
		$this->assertStringContainsString("simulated failure", $control->failures[0]["error"]);
	}

	public function testReconcileAbortsWhenDbKeysEmpty()
	{
		$control = new FakeBackupControl();
		$control->allKeysToReturn = [];
		$client = new FakeR2Client();

		$runner = new BackupRunner($control, $client);
		$result = $runner->Reconcile(true);

		$this->assertTrue($result["aborted"]);
		$this->assertStringContainsString("zero rows", $result["reason"]);
	}

	public function testReconcileAbortsWhenListingThrows()
	{
		$control = new FakeBackupControl();
		$control->allKeysToReturn = ["folder1/raw/1_a.jpg" => 1];
		$client = new FakeR2Client();
		$client->listException = new \RuntimeException("network blip");

		$runner = new BackupRunner($control, $client);
		$result = $runner->Reconcile(true);

		$this->assertTrue($result["aborted"]);
		$this->assertStringContainsString("listing failed", $result["reason"]);
	}

	public function testReconcileAbortsWhenOrphansExceedCap()
	{
		// delete_cap defaults to 100, and 5% of a 10-key listing is 0 -- either
		// way, a single orphan here must trip the guardrail.
		$control = new FakeBackupControl();
		$control->allKeysToReturn = ["folder1/raw/known.jpg" => 1];
		$client = new FakeR2Client();
		$client->listKeysToReturn = array_merge(
			["folder1/raw/known.jpg"],
			array_map(fn($i) => "folder1/raw/orphan$i.jpg", range(1, 9))
		);

		$runner = new BackupRunner($control, $client);
		$result = $runner->Reconcile(true);

		$this->assertTrue($result["aborted"]);
		$this->assertStringContainsString("exceeds the delete cap", $result["reason"]);
		$this->assertEmpty($client->deletedKeys);
	}

	/**
	 * `$count` known, already-in-R2-and-DB keys ("folder1/raw/known{i}.jpg" => i),
	 * padding the listing so a single real orphan/drift below stays comfortably
	 * under the "5% of total objects" guardrail rather than tripping it.
	 */
	private function manyKnownKeys(int $count): array
	{
		$keys = [];
		for($i = 1; $i <= $count; $i++) {
			$keys["folder1/raw/known$i.jpg"] = $i;
		}
		return $keys;
	}

	public function testReconcileDryRunDeletesNothing()
	{
		$known = $this->manyKnownKeys(50);

		$control = new FakeBackupControl();
		$control->allKeysToReturn = $known;
		$control->backedUpKeysToReturn = $known;
		$client = new FakeR2Client();
		$client->listKeysToReturn = array_merge(array_keys($known), ["folder1/raw/orphan.jpg"]);

		$runner = new BackupRunner($control, $client);
		$result = $runner->Reconcile(true);

		$this->assertTrue($result["dry_run"]);
		$this->assertSame(1, $result["orphans"]);
		$this->assertEmpty($client->deletedKeys);
		$this->assertEmpty($control->resetIds);
	}

	public function testReconcileArmedDeletesOrphansAndResetsDrift()
	{
		$known = $this->manyKnownKeys(50);
		$goneId = 9999;
		$dbKeys = $known + ["folder1/raw/gone.jpg" => $goneId];

		$control = new FakeBackupControl();
		$control->allKeysToReturn = $dbKeys;
		// "gone.jpg" is marked backed up in the DB but is no longer in R2 -> drift.
		$control->backedUpKeysToReturn = $dbKeys;
		$client = new FakeR2Client();
		$client->listKeysToReturn = array_merge(array_keys($known), ["folder1/raw/orphan.jpg"]);

		$runner = new BackupRunner($control, $client);
		$result = $runner->Reconcile(false);

		$this->assertFalse($result["dry_run"]);
		$this->assertSame(1, $result["orphans_deleted"]);
		$this->assertSame(["folder1/raw/orphan.jpg"], $client->deletedKeys);
		$this->assertSame([$goneId], $control->resetIds);
	}
}
