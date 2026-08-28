# R2 backup module — implementation plan (Scope A)

Supersedes the `rclone` mechanism in [`raw-image-backup-to-r2.md`](raw-image-backup-to-r2.md).

## Status

**Phase 1 complete** (2026-08-25) — schema and serialization, folded into the unreleased 3.6.0:

- `database/migrations/migration-3.6.0-image-backup.sql`, `database/database.sql`
- `Base/ImagesBase.php` (four properties + `dbValues`), `magrathea_objects.conf` (four fields + the missing `uuid`)
- `Images.php` — `JsonSerializable` with the documented-field allowlist
- `tests/ImagesTest.php` — exact-key-set test + hidden-fields test; suite at 142 passing
- `install.md` migration paths, `changelog.md` entries under 3.6.0

Applying the migration to dev and production is handled outside this plan.

**Phase 2 complete** — `composer require async-aws/s3` (`^3.4`, pulling in `async-aws/core`), committed in `composer.json`/`composer.lock`. Nothing imports it yet.

**Phase 3 complete** — the module itself:

- `src/api/features/R2/`: `R2Config`, `R2Client`, `R2Exception`, `BackupControl`, `BackupRunner`
- `src/api/backup.php` — CLI entrypoint (`--push [--limit=N] [--verbose]`, `--reconcile [--dry-run]`)
- `src/configs/r2.conf.sample`; `r2.conf` added to `.gitignore` alongside `magrathea.conf`; `install.md` documents it as optional
- `_inc.php` — `->AddFeature("R2")` on its own line (not folded into the `Apikey, Images` call), so it's a one-line comment-out if the module ever needs to be pulled
- Tests: `R2ConfigTest`, `R2ClientTest`, `BackupControlTest`, `BackupRunnerTest`, `R2BoundaryTest` (the `features/Images` / `admin/MediaManager` / `admin/GeneratedFileManager` grep) — suite at 173 passing
- **Deviation from the table below**: state writes go through `Database::Instance()->PrepareAndExecute()` with an explicit prepared query, not `$image->Save()` *or* `Query::Update()` as originally written. Reason found during implementation: `MagratheaModel::Update()`'s dirty-field path formats every `date`/`datetime`-typed field through `FormatDateValue()`, which regexes out only the `Y-m-d` part — so a partial update through the model would have silently truncated `backed_up_at` to midnight. `Magrathea2\DB\QueryUpdate::SQL()` also builds its `SET` clause by string-concatenating values with no escaping, which is a bad fit for `backup_error` (arbitrary exception text). A hand-written parameterized query sidesteps both.

**Phase 4 complete** — admin connection:

- `src/api/features/R2/R2Admin.php` — read-only `AdminFeature`; renders "not configured" when `R2Config::IsEnabled()` is false, otherwise a status card (bucket, endpoint, last backed-up) plus counts (total/backed-up/pending/exhausted) and a table of exhausted rows with their last error. No secret key rendered, no "backup now" button.
- `src/api/features/R2/admin/index.php` — the view, following the existing `features/Apikey/admin/*` layout convention (cards, `AdminElements::Table()`, short `<?`/`<?=` tags).
- `src/api/admin/MagratheaImagesAdmin.php` — one entry in `LoadImagesAdmin()` (called from `SetFeatures()`) and one in `BuildMenu()`, placed after `images-crud`. This is the only place core names an R2 class, per the module boundary.

**Next: phase 5** — rollout, manual and per-instance.

## Scope

One-directional backup of `raw/` files to Cloudflare R2, as an optional feature module. Nothing in the request path changes — serving, resizing and uploading continue to read and write local disk.

`generated/` is excluded; it is derived on demand by `ImageResizer`/`ImageViewer`.

R2 as a storage backend for reading/serving (Scope B) is deferred and not attempted here.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Deletes | Mirror deletes, with bucket versioning + 60-day noncurrent expiry as the recovery window | Keeps R2 an exact mirror; versioning is what makes an accidental delete recoverable, so it is not optional |
| Bucket layout | One bucket per instance, each with its own API token | R2 tokens are bucket-scoped only — there is no prefix scoping, so a shared bucket cannot isolate the instances |
| Backup state | Columns on the `images` table | No extra table or join; the cost is the `jsonSerialize()` allowlist below |
| Extra column | `backup_etag`, stored but unused | A future integrity sweep needs it, and adding a column to a core table later is far more expensive than now |
| S3 client | `async-aws/s3` | Rides the existing `symfony/http-client`; `aws/aws-sdk-php` would drag in Guzzle and ~12MB |

## Object key layout

```
r2://{bucket}/{images.folder}/raw/{images.filename}
```

Identical to the on-disk layout beneath `medias_path` (`PathManager::GetRawFolder()`), with no instance prefix — the bucket is the instance. Restore is a straight sync back into `medias_path` with no key translation.

## Module layout

```
src/api/features/R2/
├── R2Config.php        config read, IsEnabled(), validation
├── R2Client.php        thin wrapper over async-aws S3Client
├── R2Exception.php
├── BackupControl.php   pending query, mark success/failure, stats
├── BackupRunner.php    orchestrates a run, owns Sentry check-ins
├── R2Admin.php         read-only admin feature
└── admin/index.php     status view
src/api/backup.php      CLI entrypoint
```

Registered in `_inc.php` as `->AddFeature("Apikey", "Images", "R2")`.

Registration is unconditional; behaviour is config-gated via `R2Config::IsEnabled()`. Nothing outside `features/R2/` may reference an R2 class by name.

## Configuration

`src/configs/r2.conf`, read through `Magrathea2\ConfigFile` (a non-singleton ini reader; `Config::Instance()` is a singleton bound to `magrathea.conf` and must not be repointed).

`src/configs/r2.conf.sample`, committed:

```ini
[dev]
	enabled    = false
	account_id = ""
	bucket     = ""
	access_key = ""
	secret_key = ""

[production]
	enabled    = false
	account_id = ""
	bucket     = ""
	access_key = ""
	secret_key = ""
```

Sections mirror `magrathea.conf`'s environments and are selected with `Config::Instance()->GetEnvironment()`.

`batch_size` (200), `max_attempts` (5) and `delete_cap` (100) default in `R2Config`; they appear in the file only to override one on a specific host.

`R2Config` resolves the directory via `MagratheaPHP::Instance()->GetConfigRoot()` and derives the endpoint (`https://{account_id}.r2.cloudflarestorage.com`, region `auto`).

```php
$path = MagratheaPHP::Instance()->GetConfigRoot();
$file = $path."/r2.conf";
if(!file_exists($file)) return [];          // absent file == disabled
$conf = (new ConfigFile())->SetPath($path)->SetFile("r2.conf")
	->GetConfigSection(Config::Instance()->GetEnvironment()) ?: [];
```

`file_exists()` must be checked before touching `ConfigFile`: `ConfigFile::LoadFile()` throws `MagratheaConfigException` on a missing file, which the default Debugger renders and `die()`s on. An absent file must produce a clean "disabled".

`IsEnabled()` requires `enabled` **and** non-empty `account_id`/`bucket`/`access_key`/`secret_key`. A half-filled config is a hard error from the CLI, not a silent no-op.

Also:

- Add `r2.conf` to `.gitignore`, alongside `magrathea.conf` and `src/api/sentry.php`.
- `chmod 640`, owned by the app user.
- Document the file in `install.md` where `magrathea.conf` setup is described, marked optional.

## Database changes

`database/migrations/migration-3.6.0-image-backup.sql` — the schema and serialization changes ship in the (still unreleased) 3.6.0, ahead of the module itself:

```sql
ALTER TABLE `images`
	ADD COLUMN `backed_up_at`    DATETIME NULL DEFAULT NULL AFTER `upload_key`,
	ADD COLUMN `backup_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `backed_up_at`,
	ADD COLUMN `backup_error`    VARCHAR(255) NULL DEFAULT NULL AFTER `backup_attempts`,
	ADD COLUMN `backup_etag`     VARCHAR(64) NULL DEFAULT NULL AFTER `backup_error`;

CREATE INDEX `idx_images_backup_pending`
	ON `images` (`backed_up_at`, `backup_attempts`, `id`);
```

`backed_up_at IS NULL` means "needs backup"; existing rows backfill to NULL, which is the seed set.

`backup_etag` is written by the push job and otherwise unused for now. It exists so a future integrity sweep (HEAD each object, compare against the stored ETag) doesn't need a second migration on a core table — reconcile as specified only checks that a key exists, not that its contents are still correct. Adding the column is free today; adding it later is not.

Update by hand in the same commit:

- `database/database.sql` — the `images` CREATE TABLE, for fresh installs.
- `src/api/features/Images/Base/ImagesBase.php` — the four properties and their `dbValues` entries.
- `src/configs/magrathea_objects.conf` — the four fields **and the missing `uuid` entry**. That section is stale: `uuid` was added to `ImagesBase.php` by hand in `d1f0001` and never written back, so regenerating models today would drop it. Hand-edit the Base class; do not run the generator.

## Job 1 — push (hourly)

`php src/api/backup.php --push [--limit=N] [--verbose]`

1. `R2Config::IsEnabled()` → if false, log once and exit 0.
2. Sentry check-in `inProgress`, keep the returned check-in id.
3. `SELECT * FROM images WHERE backed_up_at IS NULL AND backup_attempts < {max_attempts} ORDER BY id ASC LIMIT {batch_size}`.
4. Per image, local path = `PathManager::GetRawFolder($image->folder) . $image->filename`. Missing on disk → record `backup_error`, increment `backup_attempts`, continue; one missing file must never fail the run.
5. `PutObject` with a `fopen(..., 'rb')` stream (never `file_get_contents`), `ContentType` from `images.file_type` with an extension-map fallback, and `x-amz-meta-image-uuid`.
6. Verify the returned ETag against `md5_file()` — single-PUT objects have ETag = MD5 hex. Mismatch is a failure.
7. Success → `backed_up_at = NOW()`, `backup_etag`, `backup_attempts = 0`, `backup_error = NULL`. Failure → `backup_attempts + 1`, truncated error message.
8. Check-in `ok` or `error` with duration.

State columns are written with a targeted, hand-written `PrepareAndExecute()` call (see the phase-3 deviation note above) — never `$image->Save()`, which rewrites every column and can clobber a concurrent change, and never `$image->Update()`, whose dirty-field path truncates `backed_up_at` to its date part.

Failures are aggregated into one Sentry event per run with counts and a sample, not one event per file.

Rows that exhaust `max_attempts` stop being retried and surface on the admin page.

No upload-race guard is needed: both upload paths call `$image->Insert()` only after the file is written and stripped (`ImageUploader.php:83-92`, `148-158`), so a row implies a complete file.

## Job 2 — reconcile (daily)

Deletes are discovered by comparison, because `Images::Delete()` is a hard `DELETE` (`MagratheaModel.php:383`) — the row and its backup state vanish together, leaving no tombstone to drive a delete from.

`php src/api/backup.php --reconcile [--dry-run]`

1. Page through `ListObjectsV2` over the whole bucket, collecting keys.
2. Build the expected key set from `SELECT folder, filename FROM images`.
3. **Orphans** (in R2, not in DB) → delete, batched via `DeleteObjects` (1000/request). Versioning retains them 60 days.
4. **Drift** (in DB with `backed_up_at` set, not in R2) → reset `backed_up_at = NULL` so the push job re-uploads.

### Guardrails

This job deletes production backups based on a query result. All four are mandatory:

- Abort if the `images` query returns **zero rows**.
- Abort if **any** listing page errors — a partial listing makes every unlisted key look like an orphan.
- Abort if orphan count exceeds `delete_cap` (default 100) **or** 5% of total objects, whichever is smaller; report an `error` check-in and delete nothing.
- `--dry-run` prints the plan and deletes nothing.

These get the module's most thorough tests.

## Cron and locking

Per host, in the app-owning user's crontab (not root):

```
7  *  * * *  /usr/bin/flock -n /tmp/mi3-backup-push.lock  /usr/bin/php /var/www/api/backup.php --push      >> /var/log/magrathea-backup.log 2>&1
23 4  * * *  /usr/bin/flock -n /tmp/mi3-backup-recon.lock /usr/bin/php /var/www/api/backup.php --reconcile >> /var/log/magrathea-backup.log 2>&1
```

## Monitoring

Sentry check-ins from `BackupRunner`, using the existing `sentry.php` convention. Verified signatures in the installed SDK:

- `\Sentry\captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string`
- `new MonitorConfig(MonitorSchedule $schedule, ?int $checkinMargin, ?int $maxRuntime, ?string $timezone)`
- `MonitorSchedule::crontab(string $value)`

`{instance}` in the slugs below is `Config::Instance()->GetEnvironment()` (`dev`/`scripttest`/`production`) — accepted as a known gap: the two production instances share one environment name, so they also share one pair of monitor slugs for now. Revisit if that ever needs distinguishing.

Two monitors per instance:

| Slug | Schedule | Margin | Max runtime |
|---|---|---|---|
| `mi3-{instance}-backup-push` | `7 * * * *` | 15 min | 30 min |
| `mi3-{instance}-backup-reconcile` | `23 4 * * *` | 60 min | 60 min |

## Admin page

`R2Admin` renders one read-only status view: total / backed-up / pending / failed counts, most recent `backed_up_at`, rows that exhausted their attempts with their errors, and the active bucket and endpoint. Never the secret key, and no "backup now" button.

## Cloudflare setup (per instance, once)

1. Create the bucket (`magrathea-images-backup-{instance}`).
2. Enable **bucket versioning** — without it, mirrored deletes have no safety net.
3. Lifecycle rule: expire noncurrent versions after 60 days.
4. Create an R2 API token scoped to **that bucket only**, Object Read & Write. Note the account id.
5. Copy `r2.conf.sample` to `r2.conf` on that host, fill in account id, bucket and token; leave `enabled = false` until the seed is ready.

## Implementation order

Phases 1–4 are code and merge with no `r2.conf` present anywhere; nothing happens in production until phase 5. Phase 5 is manual and runs once per instance.

The column list is settled (`backed_up_at`, `backup_attempts`, `backup_error`, `backup_etag`) and so is serialization, so phase 1 can be written without waiting on anything downstream.

### Phase 1 — schema

1. `database/migrations/migration-3.6.0-image-backup.sql`.
2. `database/database.sql` — same columns on the `images` CREATE TABLE.
3. `src/api/features/Images/Base/ImagesBase.php` — properties + `dbValues`, hand-edited.
4. `src/configs/magrathea_objects.conf` — the four fields plus the missing `uuid` entry.
5. `src/api/features/Images/Images.php` — `jsonSerialize()`, per [API serialization](#api-serialization). It belongs in this commit: it is what keeps the new columns out of API responses, so the two must never ship apart.
6. Apply to dev, run the existing test suite. The columns are inert, so the only expected movement is in API-response assertions.

### Phase 2 — dependency

`composer require async-aws/s3`, commit `composer.json` and `composer.lock`. Nothing imports it yet.

### Phase 3 — module

Everything in `src/api/features/R2/` plus `src/api/backup.php`, in this order:

1. `R2Config` + `IsEnabled()`; `r2.conf.sample`; `.gitignore` entry; `install.md` note; `->AddFeature("Apikey", "Images", "R2")` in `_inc.php`. Test both disabled shapes (`enabled = false`, and no file at all).
2. `R2Client` + `R2Exception`, with tests against a mocked HTTP client — key construction, content type, stream handling, ETag verification.
3. `BackupControl` + `BackupRunner` + the push job + `src/api/backup.php`, with tests.
4. The reconcile job, with a test per guardrail. Budget more time here than for the push job and the client combined; it is the only code in the module that deletes anything.
5. A boundary test: grep `features/Images/`, `admin/MediaManager/` and `admin/GeneratedFileManager/` for `R2` and assert zero matches. This is what stops someone adding a backup call to `ImageUploader::Upload()` later and putting network I/O back on the upload path.

`src/api/backup.php` must `chdir(__DIR__)` before including `_inc.php` — that file's includes (`../vendor/autoload.php`, `sentry.php`, `shared/Helper.php`) are all relative to the working directory, which is `src/api` for web and phpunit but `$HOME` under cron. It then calls `MagratheaPHP::Instance()->StartDb()`, which `_inc.php` leaves commented out.

### Phase 4 — admin connection

`R2Admin` + `admin/index.php`, registered in `src/api/admin/MagratheaImagesAdmin.php`: one entry in `SetFeatures()` and one in `BuildMenu()`.

This is the only place core names an R2 class, so the module is optional to *configure*, not optional to *delete*. Without `r2.conf` the page renders "not configured" rather than disappearing.

### Phase 5 — rollout, per instance

1. Cloudflare setup (bucket, versioning, lifecycle rule, bucket-scoped token).
2. `r2.conf` on the host, `enabled = true`. **Re-read the bucket name against the instance before saving it** — see the guardrail note below.
3. **Seed**: `--push --limit=0` manually, off-hours. Resumable by construction; if it dies, rerun it.
4. Push cron + Sentry monitor. Soak for several days.
5. Reconcile on cron with `--dry-run`. Inspect the output daily.
6. Arm reconcile. Last step, per instance, and only after 4 and 5 are clean.
7. Restore drill; write it up as the restore runbook.

The reason 6 comes last: a `r2.conf` copied from instance A to instance B without changing `bucket` points B's reconcile at A's data, where every object is an orphan. The `delete_cap` / 5% guardrail is the only thing between that typo and total loss of the backup, which is why it is not configurable up to "unlimited".

## Verification

1. Unit tests green, especially the reconcile guardrails: empty DB aborts, cap-exceeded aborts, partial listing aborts.
2. **Disabled path**: test both `enabled = false` and no `r2.conf` on disk. Each exits 0 and touches nothing, with no `MagratheaConfigException` reaching the Debugger.
3. **Single-image push**: upload one image, run `--push`, confirm the object exists at `{folder}/raw/{filename}`, byte-identical to disk, and `backed_up_at` is set.
4. **ETag verification**: push a truncated file, confirm the run marks it failed rather than recording success.
5. **Failure handling**: point at a bad bucket; confirm `backup_attempts` increments, one aggregated Sentry event is emitted, and the check-in reports `error`.
6. **Missed-run alert**: disable the cron entry, confirm Sentry's missed-checkin alert fires within the margin.
7. **Delete mirroring**: delete an image via the API, run `--reconcile --dry-run` (confirm it is listed), then armed (confirm the object is gone from the current view but recoverable from version history).
8. **Drift self-healing**: delete an object directly in R2, run `--reconcile`, confirm `backed_up_at` resets and the next `--push` re-uploads it.
9. **Restore drill**: sync one real key's `raw/` prefix from R2 into a scratch directory, diff file list and hashes against live `medias_path`. Write up as the restore runbook. Repeat quarterly.
10. **Repeat 3–9 on the second instance**, and confirm instance A's token fails against instance B's bucket.

## API serialization

`MagratheaApi` `json_encode`s the response directly and `Images` has no `jsonSerialize()`, so every public property is emitted. Without a fix the four backup columns appear in upload and list responses, and `backup_error` can carry endpoint and bucket strings.

**Decided: add `jsonSerialize()` to `Images`, returning an explicit allowlist of the fields documented in `swagger.yaml`** (`id`, `uuid`, `name`, `filename`, `extension`, `folder`, `subfolder`, `width`, `height`, `file_type`, `size`, `upload_key`, `created_at`, `updated_at`).

This aligns the implementation with the published contract rather than breaking it: the swagger `Image` schema already documents exactly those fields, while the live response additionally emits `placeholder` and `accessId`. Those two disappear — undocumented, internal, request-scoped, and consumers are in beta.

**Do not implement it as `return $this->ToArray();`.** `ToArray()` (`MagratheaModel.php:537-548`) merges `relations["properties"]`, which for `Images` holds `Apikey` (`ImagesBase.php:43`), and `Apikey` has a public `private_key`. Today that is invisible because `$relations` is `protected` and default `json_encode` only reaches public properties; a `ToArray()`-based serializer would emit `"Apikey": null` on every response and the full key object on any path that touched `GetApikey()` first. An explicit allowlist has no such failure mode.

Goes in `src/api/features/Images/Images.php` — hand-written, survives model regeneration — never in `ImagesBase.php`.
