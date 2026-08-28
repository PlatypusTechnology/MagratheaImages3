<?php
namespace MagratheaImages3\R2;

use Magrathea2\DB\Database;
use Magrathea2\DB\Query;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Images\ImagesControl;

/**
 * All backup-state reads and writes for `images` live here. State columns are
 * written through targeted `PrepareAndExecute()` calls -- never `$image->Save()`,
 * which would rewrite every column and can clobber a concurrent change -- and
 * never `$image->Update()`, whose dirty-field path formats any `datetime`-typed
 * field (this class's `backed_up_at` included) down to just its date part
 * (`MagratheaModel::FormatDateValue()`), silently dropping the time.
 */
class BackupControl {

	private ImagesControl $images;

	public function __construct() {
		$this->images = new ImagesControl();
	}

	/**
	 * Rows due for a push attempt: never backed up, and not yet out of retries.
	 * @return Images[]
	 */
	public function GetPending(int $limit, int $maxAttempts): array {
		$query = Query::Select()
			->Obj(new Images())
			->Where("backed_up_at IS NULL")
			->Where("backup_attempts < ".intval($maxAttempts))
			->OrderBy("id ASC")
			->Limit($limit);
		return $this->images->Run($query);
	}

	public function MarkSuccess(Images $image, string $etag): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE images SET backed_up_at = ?, backup_etag = ?, backup_attempts = 0, backup_error = NULL WHERE id = ?",
			["datetime", "string", "int"],
			[date("Y-m-d H:i:s"), $etag, $image->id]
		);
	}

	public function MarkFailure(Images $image, string $error): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE images SET backup_attempts = backup_attempts + 1, backup_error = ? WHERE id = ?",
			["string", "int"],
			[substr($error, 0, 255), $image->id]
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
		return $this->KeyMapFromSql("SELECT id, folder, filename FROM images WHERE backed_up_at IS NOT NULL");
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
	 * re-uploads them. Used to self-heal drift found during reconcile.
	 */
	public function ResetForRetry(array $ids): int {
		$ids = array_values(array_unique(array_map("intval", $ids)));
		if(empty($ids)) return 0;
		$placeholders = implode(",", array_fill(0, count($ids), "?"));
		$types = array_fill(0, count($ids), "int");
		return Database::Instance()->PrepareAndExecute(
			"UPDATE images SET backed_up_at = NULL WHERE id IN (".$placeholders.")",
			$types,
			$ids
		);
	}

	/**
	 * Counts and most-recent timestamp for the admin status view.
	 */
	public function GetStats(): array {
		$db = Database::Instance();
		return [
			"total" => intval($db->QueryOne("SELECT COUNT(*) FROM images") ?? 0),
			"backed_up" => intval($db->QueryOne("SELECT COUNT(*) FROM images WHERE backed_up_at IS NOT NULL") ?? 0),
			"pending" => intval($db->QueryOne("SELECT COUNT(*) FROM images WHERE backed_up_at IS NULL AND backup_attempts < ".R2Config::GetMaxAttempts()) ?? 0),
			"exhausted" => intval($db->QueryOne("SELECT COUNT(*) FROM images WHERE backed_up_at IS NULL AND backup_attempts >= ".R2Config::GetMaxAttempts()) ?? 0),
			"last_backed_up_at" => $db->QueryOne("SELECT MAX(backed_up_at) FROM images"),
		];
	}

	/**
	 * Rows that exhausted their attempts, with their last error -- shown on the
	 * admin page since the push job will never retry them on its own.
	 */
	public function GetExhausted(): array {
		return $this->images->QueryResult(
			"SELECT id, folder, filename, backup_attempts, backup_error FROM images".
			" WHERE backed_up_at IS NULL AND backup_attempts >= ".R2Config::GetMaxAttempts().
			" ORDER BY id ASC"
		);
	}

}
