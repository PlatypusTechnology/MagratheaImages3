# R2 backup — attempt history table (future work)

Follow-up to [`r2-backup-module.md`](r2-backup-module.md). Not started — this is
a near-future idea, not a committed phase.

## Status

Raised 2026-08-28, right after the 3.6.0 `images` migration was written but
before any instance finished phase 5 rollout (so no production data is on the
current schema yet — this is the cheapest point to change it).

**Implemented 2026-08-28, as 3.6.1** (`database/migrations/migration-3.6.1-image-backup-history.sql`).
One deviation from the design below: the migration does **not** backfill
`images_backup_status` from the old columns — confirmed with the user that
since no instance has run a real push yet, there's no state to carry over,
and a missing status row already means "never attempted" by design, so a
backfill would just be dead code protecting against a scenario that doesn't
exist. `BackupControl`, `ImagesBase.php`, and the R2 test suite are updated
per the rewrite scope below; `Images::jsonSerialize()` needed no change (it
already excluded the backup columns via its explicit allowlist).

## Motivation

Two separate complaints from the same conversation, both driving this plan:

1. **`images` table pollution.** The 3.6.0 migration adds four backup-tracking
   columns (`backed_up_at`, `backup_attempts`, `backup_error`, `backup_etag`)
   directly onto the core `images` table for what is an optional, config-gated
   module. Decided to keep it as-is for 3.6.0 (the columns are a true 1:1
   overlay and every `BackupControl` query already scans all of `images`
   anyway, so splitting it out at the time would only add joins), but the
   discomfort with a core table carrying feature-module state stands and is
   the trigger for this plan. See [[project-r2-backup-module]] for the full
   discussion.
2. **No attempt history is kept.** `BackupControl::MarkSuccess()` resets
   `backup_attempts` to 0 and clears `backup_error`; `MarkFailure()` overwrites
   `backup_error` in place. Today only the *current* state survives — the
   moment a retry succeeds, every prior failure for that image is gone with
   no trace. Desire is to keep a record of every backup attempt, for every
   image, not just the latest one.

## Proposed design

Replace the four `images` columns with two tables:

**`images_backup_status`** — one row per image, current state only (what
`images` carries today, moved off it). Row is created lazily, by the first
`MarkSuccess`/`MarkFailure` upsert — no row means "never attempted":

```sql
CREATE TABLE `images_backup_status` (
	`image_id`        INT UNSIGNED NOT NULL PRIMARY KEY,
	`backed_up_at`     DATETIME NULL DEFAULT NULL,
	`backup_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
	`backup_error`     VARCHAR(255) NULL DEFAULT NULL,
	`backup_etag`      VARCHAR(64) NULL DEFAULT NULL,
	FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
);
```

**`images_backup_attempts`** — append-only, one row per push attempt, never
updated or deleted by the push job itself, kept indefinitely:

```sql
CREATE TABLE `images_backup_attempts` (
	`id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`image_id`    INT UNSIGNED NOT NULL,
	`attempted_at` DATETIME NOT NULL,
	`succeeded`   TINYINT(1) NOT NULL,
	`error`       VARCHAR(255) NULL DEFAULT NULL,
	`etag`        VARCHAR(64) NULL DEFAULT NULL,
	KEY `idx_image_id` (`image_id`, `attempted_at`),
	FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE
);
```

`BackupRunner` writes both in the same call: upsert `images_backup_status`
(exactly like today's `MarkSuccess`/`MarkFailure`), plus one `INSERT` into
`images_backup_attempts`. Per `BackupControl` method:

- `GetPending()` — `images LEFT JOIN images_backup_status s ON s.image_id =
  images.id WHERE s.image_id IS NULL OR (s.backed_up_at IS NULL AND
  s.backup_attempts < ?)`. The only method that needs the `LEFT JOIN` — it's
  the one place a never-attempted image (no status row yet) must still show
  up as a push candidate.
- `GetStats()` — no join needed. `total = COUNT(images)`, `backed_up =
  COUNT(status WHERE backed_up_at IS NOT NULL)`, `exhausted = COUNT(status
  WHERE backed_up_at IS NULL AND backup_attempts >= max)`, `pending = total -
  backed_up - exhausted` (covers both retrying and never-attempted rows,
  since neither has a qualifying status row).
- `GetAllKeys()` — unchanged, still `SELECT folder, filename FROM images`;
  it never touched backup fields.
- `GetBackedUpKeys()`, `GetExhausted()` — plain `INNER JOIN images_backup_status`;
  both only care about images that already have a status row.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Status row creation | Lazy — first `MarkSuccess`/`MarkFailure` upserts it | Keeps `ImageUploader.php`/`Images::Insert()` untouched, preserving "nothing outside `features/R2` references R2." The `LEFT JOIN` cost lands only on `GetPending()`, run by the hourly push cron — never the upload path. |
| Row cleanup on image delete | `ON DELETE CASCADE` on both FKs | `Images::Delete()` (`MagratheaModel.php:383`) is a hard `DELETE` with no per-model hook today. Cascading FKs clean up both new tables at the DB level without teaching core `Images` code about R2 — same boundary reasoning as the row above. |
| Attempt retention | Keep every row indefinitely; no automatic pruning at launch | Full history is the point of this feature. A manual per-`image_id` cleanup query covers the rare runaway case (e.g. an infra bug causing repeated drift → retry cycling) — building archival machinery now, against a table with no real growth data, would be guessing. Revisit only if a real instance's table size becomes an actual problem. |
| Admin UI | None at first pass — `R2Admin` keeps its current-state view, just reads `images_backup_status` instead of `images` | A "view attempt history for image X" view is a separate later backlog item, once there's real data and a real use case to design against. |
| Timing vs. phase 5 | Land this before running the phase-5 seed push (checklist step 3) on any instance, including the one in progress now | Confirmed 2026-08-28: the in-progress instance hasn't been seeded yet, so this is still the free window — no live data to backfill. Once a seed push runs anywhere, this becomes a real migration (create tables, backfill `images_backup_status` from the four columns, drop them from `images`) instead of a schema change with nothing to move. |

## Rewrite scope

`BackupControl` (all methods), `ImagesBase.php`'s `dbValues` entries, the
`jsonSerialize()` allowlist in `Images.php`, and most of the R2 test suite
(`BackupControlTest`, `BackupRunnerTest`, `R2BoundaryTest`, `ImagesTest`'s
exact-key-set test) were all written against columns-on-`images` and need
touching.
