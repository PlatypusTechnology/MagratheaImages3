<?php
namespace MagratheaImages3\R2;

use Magrathea2\DB\Database;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Images\ImagesControl;

/**
 * All backup-state reads and writes live here, split across two tables kept
 * off the core `images` table: `images_backup_status` (one row per image,
 * current state only, upserted lazily on first attempt) and
 * `images_backup_attempts` (append-only, one row per push attempt, never
 * updated or deleted by this class). State writes go through targeted
 * `PrepareAndExecute()` calls -- never `$image->Save()`, which would rewrite
 * every column and can clobber a concurrent change -- and never
 * `$image->Update()`, whose dirty-field path formats any `datetime`-typed
 * field down to just its date part (`MagratheaModel::FormatDateValue()`),
 * silently dropping the time.
 */
class BackupControl {

	private ImagesControl $images;

	public function __construct() {
		$this->images = new ImagesControl();
	}

	/**
	 * Rows due for a push attempt: never backed up, and not yet out of retries.
	 * A LEFT JOIN is required here (and nowhere else in this class) because a
	 * never-attempted image has no `images_backup_status` row at all yet, and
	 * must still show up as a push candidate.
	 * @return Images[]
	 */
	public function GetPending(int $limit, int $maxAttempts): array {
		$sql = "SELECT images.* FROM images".
			" LEFT JOIN images_backup_status s ON s.image_id = images.id".
			" WHERE s.image_id IS NULL OR (s.backed_up_at IS NULL AND s.backup_attempts < ".intval($maxAttempts).")".
			" ORDER BY images.id ASC LIMIT ".intval($limit);
		return $this->images->RunQuery($sql);
	}

	public function MarkSuccess(Images $image, string $etag): void {
		Database::Instance()->PrepareAndExecute(
			"INSERT INTO images_backup_status (image_id, backed_up_at, backup_etag, backup_attempts, backup_error)".
			" VALUES (?, ?, ?, 0, NULL)".
			" ON DUPLICATE KEY UPDATE backed_up_at = VALUES(backed_up_at), backup_etag = VALUES(backup_etag),".
			" backup_attempts = 0, backup_error = NULL",
			["int", "datetime", "string"],
			[$image->id, date("Y-m-d H:i:s"), $etag]
		);
		$this->RecordAttempt($image->id, true, null, $etag);
	}

	public function MarkFailure(Images $image, string $error): void {
		$truncated = substr($error, 0, 255);
		Database::Instance()->PrepareAndExecute(
			"INSERT INTO images_backup_status (image_id, backup_attempts, backup_error)".
			" VALUES (?, 1, ?)".
			" ON DUPLICATE KEY UPDATE backup_attempts = backup_attempts + 1, backup_error = VALUES(backup_error)",
			["int", "string"],
			[$image->id, $truncated]
		);
		$this->RecordAttempt($image->id, false, $truncated, null);
	}

	private function RecordAttempt(int $imageId, bool $succeeded, ?string $error, ?string $etag): void {
		Database::Instance()->PrepareAndExecute(
			"INSERT INTO images_backup_attempts (image_id, attempted_at, succeeded, error, etag) VALUES (?, ?, ?, ?, ?)",
			["int", "datetime", "int", "string", "string"],
			[$imageId, date("Y-m-d H:i:s"), $succeeded ? 1 : 0, $error, $etag]
		);
	}

	/**
	 * Every image's object key, regardless of backup state -- this is the
	 * "expected" set an R2 listing is compared against to find orphans.
	 * @return array<string,int> object key => image id
	 */
	public function GetAllKeys(): array {
		return $this->KeyMapFromSql("SELECT id, folder, filename FROM images");
	}

	/**
	 * Object keys for rows currently marked as backed up -- the set checked
	 * against an R2 listing to find drift (a row says it's backed up, but the
	 * object isn't actually there).
	 * @return array<string,int> object key => image id
	 */
	public function GetBackedUpKeys(): array {
		return $this->KeyMapFromSql(
			"SELECT images.id, images.folder, images.filename FROM images".
			" INNER JOIN images_backup_status s ON s.image_id = images.id".
			" WHERE s.backed_up_at IS NOT NULL"
		);
	}

	private function KeyMapFromSql(string $sql): array {
		$rows = $this->images->QueryResult($sql);
		$map = [];
		foreach($rows as $row) {
			$key = R2Client::ObjectKey($row["folder"], $row["filename"]);
			$map[$key] = intval($row["id"]);
		}
		return $map;
	}

	/**
	 * Resets `backed_up_at` to NULL for the given ids, so the next push
	 * re-uploads them. Used to self-heal drift found during reconcile. Only
	 * ever called with ids already known to have a status row (drift can only
	 * be detected on a row already marked backed up), so no upsert is needed.
	 */
	public function ResetForRetry(array $ids): int {
		$ids = array_values(array_unique(array_map("intval", $ids)));
		if(empty($ids)) return 0;
		$placeholders = implode(",", array_fill(0, count($ids), "?"));
		$types = array_fill(0, count($ids), "int");
		return Database::Instance()->PrepareAndExecute(
			"UPDATE images_backup_status SET backed_up_at = NULL WHERE image_id IN (".$placeholders.")",
			$types,
			$ids
		);
	}

	/**
	 * Counts and most-recent timestamp for the admin status view.
	 */
	public function GetStats(): array {
		$db = Database::Instance();
		$maxAttempts = R2Config::GetMaxAttempts();
		$backedUp = intval($db->QueryOne("SELECT COUNT(*) FROM images_backup_status WHERE backed_up_at IS NOT NULL") ?? 0);
		$exhausted = intval($db->QueryOne(
			"SELECT COUNT(*) FROM images_backup_status WHERE backed_up_at IS NULL AND backup_attempts >= ".$maxAttempts
		) ?? 0);
		$total = intval($db->QueryOne("SELECT COUNT(*) FROM images") ?? 0);
		return [
			"total" => $total,
			"backed_up" => $backedUp,
			"pending" => $total - $backedUp - $exhausted,
			"exhausted" => $exhausted,
			"last_backed_up_at" => $db->QueryOne("SELECT MAX(backed_up_at) FROM images_backup_status"),
		];
	}

	/**
	 * Rows that exhausted their attempts, with their last error -- shown on the
	 * admin page since the push job will never retry them on its own.
	 */
	public function GetExhausted(): array {
		return $this->images->QueryResult(
			"SELECT images.id, images.folder, images.filename, s.backup_attempts, s.backup_error".
			" FROM images INNER JOIN images_backup_status s ON s.image_id = images.id".
			" WHERE s.backed_up_at IS NULL AND s.backup_attempts >= ".R2Config::GetMaxAttempts().
			" ORDER BY images.id ASC"
		);
	}

}
