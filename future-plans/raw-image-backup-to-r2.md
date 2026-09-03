# Backup system for MagratheaImages3's raw image storage

## Context

MagratheaImages3 stores every uploaded image on local disk under `medias_path` (set per environment in `src/configs/magrathea.conf`, e.g. `/var/www/medias`), laid out per API key as `{medias_path}/{apikey.folder}/raw/...` and `.../generated/...` (`src/api/features/Images/PathManager.php`). Production runs as **two independent bare-Apache instances** (no Docker in production — Docker is dev-only), each with its own host disk and its own set of `Apikey.folder` values.

**There is currently no backup of this data at all.** Each instance's `raw/` tree is a single copy on a single disk. Disk failure, host loss, or a destructive bug — notably the existing `FileManager::DeleteGeneratedPattern()` (`src/api/features/Images/FileManager.php`), which shells out to `rm -v {glob pattern}` with no undo — means unrecoverable data loss for every image ever uploaded. This is a plain availability/durability gap, independent of any question about migrating live-serving storage to R2.

`generated/` is fully derived from `raw/` on demand (`ImageResizer`/`ImageViewer`) — confirmed only `raw/` needs backing up. At current representative scale (~200 keys × ~75 images × ~2MB avg ≈ 30GB/instance), this is cheap to solve well: R2's free tier (10GB storage, 1M Class A ops/mo, 10M Class B ops/mo, egress always free — confirmed against https://developers.cloudflare.com/r2/pricing/) gets blown past immediately, but the overage cost is ~$0.30/month at this scale and stays negligible even at 10-100x this volume, so cost should not drive any design choice here.

## Recommended approach

One incremental `rclone sync` job per instance, run **hourly** via OS-level cron on each Apache host, pushing `{medias_path}/*/raw/**` to a single Cloudflare R2 bucket, namespaced by instance, with destination-side versioning as the delete-safety net, and Sentry Crons for run/failure monitoring. No PHP application code changes are required for the core mechanism — this is an ops/infra job plus one small monitoring shim script.

**Why this shape, briefly:**
- **One sync job per instance, not a loop over keys.** `rclone sync` with an include filter for `*/raw/**` covers every key's raw folder in a single incremental pass — new keys are automatically covered on the next run, no code changes as key count grows.
- **No compression/archiving.** JPEG/WebP/PNG are already compressed; tar/gzip buys negligible space savings and destroys per-file restore granularity. Plain file-tree mirroring only.
- **Destination: one R2 bucket, instance-namespaced prefixes** — `r2://<backup-bucket>/{instance-name}/{apikey.folder}/raw/...`. `ApikeyControl::Create()` never checks `folder` against existing rows (confirmed by reading the code), and with two independently-administered instances/databases, folder collisions across instances can't be ruled out. Instance-namespacing costs nothing and removes the risk — **confirm with the user whether folder values are actually guaranteed unique before dropping this if it ever seems unnecessary.**
- **Trigger: OS-level cron, not a PHP admin action.** A synchronous "click a button, backup now" admin flow risks PHP `max_execution_time`/Apache worker exhaustion over tens of thousands of files and has no retry/resume semantics. If an admin UI element is added, it's read-only (last-run status/log), never the trigger itself.
- **Race-condition guard:** rclone `--min-age 10m` (tuned above worst-case upload duration) so files `ImageUploader::Upload()`/`UploadUrl()` is still writing are skipped this run and picked up cleanly next run.
- **Delete-safety: R2 bucket versioning + 60-day lifecycle expiry on noncurrent versions.** Let `rclone sync` propagate deletes normally (keeps the mirror current, no manual pruning needed), but versioning means a destructive bug propagating a bad delete (e.g. the `rm`-glob risk above) is recoverable for 60 days. Simpler and less prone to drift than trying to suppress delete-propagation entirely.
- **Frequency: hourly, deliberately** — incremental syncs cost scales with *changes*, not corpus size, so hourly is nowhere near R2's free operation allowances even at this volume. Worst-case data-loss window becomes ~1 hour + min-age buffer instead of a full day.
- **Monitoring: reuse Sentry Crons**, not a new channel. `sentry/sentry` is already a dependency with an established per-instance init convention (`src/api/sentry.example.php` template → gitignored `src/api/sentry.php`, conditionally included in `src/api/_inc.php`). The installed SDK already has `captureCheckIn()`/`CheckInStatus`/`MonitorConfig`/`MonitorSchedule` (verified present in `src/vendor/sentry/sentry/src/`) — a scheduled check-in monitor alerts both on explicit failure *and* on a cron that silently stops running, which a bare exit-code check wouldn't catch.
- **Restore: documented and drilled, not assumed.** A written runbook plus a periodic (e.g. quarterly) restore drill on a real key, diffed byte-for-byte against production.
- **Not needed yet, noted for later:** rclone's default diff-by-listing-destination approach will eventually cost real R2 list-operation pricing at corpus sizes far beyond current scale. A future fix would be app-level backup-state tracking (e.g. a `backed_up_at` column on `Images`). Explicitly out of scope now.

## Setup steps

**A. R2 bucket, once (Cloudflare dashboard/API):**
1. Create one bucket (e.g. `magrathea-images-backup`).
2. Enable bucket versioning.
3. Add a lifecycle rule expiring noncurrent versions after 60 days.
4. Create an R2 API token scoped to this bucket (read+write) per instance — separate tokens per instance for blast-radius isolation, not a shared one; note the S3-compatible endpoint (`https://<account-id>.r2.cloudflarestorage.com`).

**B. Per-instance rclone config, on each Apache host:**
- `rclone.conf` remote: type `s3`, `provider = Cloudflare`, that instance's endpoint/region=auto/access key/secret.
- Sync command shape (lives in a small shell wrapper, not the app repo):
  ```
  rclone sync {medias_path}/ r2remote:magrathea-images-backup/{instance-name}/ \
    --include "*/raw/**" \
    --min-age 10m \
    --transfers 8 --checkers 16 \
    --log-file=/var/log/magrathea-backup.log --log-level INFO
  ```
  `{medias_path}` should be read from that instance's `src/configs/magrathea.conf` rather than hardcoded, to avoid drift.

**C. Cron, per host (app-owning user's crontab, not root):**
```
0 * * * * /usr/local/bin/magrathea-backup.sh >> /var/log/magrathea-backup-cron.log 2>&1
```
`magrathea-backup.sh`: send a Sentry `in_progress` check-in → run the rclone sync → send `ok`/`error` check-in based on exit code. The check-in calls go through a small new PHP CLI script (e.g. `src/api/backup-checkin.php`) that bootstraps the same way other CLI scripts do (`vendor/autoload.php` + that instance's `sentry.php`).

**D. Sentry Crons monitor:** one per instance (e.g. `magrathea-images-instance-a-backup`), hourly schedule, ~15min checkin margin, ~30min max runtime before a run is treated as missed/timed out.

## Critical files

- `src/configs/magrathea.conf` (+ `.sample`) — `medias_path` source of truth per instance.
- `src/api/features/Images/PathManager.php` — authoritative `raw/`/`generated/` layout the sync filter must match.
- `src/api/features/Apikey/Apikey.php`, `ApikeyControl.php` — confirms `folder` uniqueness isn't enforced; underpins instance-namespacing.
- `src/api/sentry.example.php`, `src/api/_inc.php` — existing Sentry init convention to mirror for the check-in script.
- `src/composer.json`, `src/vendor/sentry/sentry/src/` (`CheckIn.php`, `MonitorConfig.php`, `MonitorSchedule.php`) — confirms Crons primitives are already available in the installed SDK.
- `src/api/features/Images/FileManager.php` — the `rm`-glob delete risk that motivates versioning as the safety net.

## Verification

1. **Dry run**: `rclone sync ... --dry-run` on one instance, confirm only `*/raw/**` is selected (nothing from `generated/`), then run for real.
2. **Destination check**: spot-check several keys landed under `{instance-name}/{apikey.folder}/raw/...` in R2, byte-identical to source (`rclone check` or hash compare).
3. **Delete-safety check**: delete a test raw file locally, re-sync, confirm it's gone from the current view but recoverable from R2 version history.
4. **Race-condition check**: upload a test image, immediately trigger a manual sync, confirm `--min-age` skips it and it's picked up on the next run.
5. **Alerting check**: force a sync failure (bad bucket/creds) and confirm the Sentry monitor reports failure; separately, disable the cron entry and confirm Sentry's missed-checkin alert fires within the configured margin.
6. **Restore drill**: `rclone copy` one real key's `raw/` from R2 to a scratch directory, diff file list + hashes against live `medias_path`, confirm exact match. Write this up as the first version of the restore runbook.
7. **Repeat B–D on the second instance**, confirming instance-namespacing prevents any cross-instance prefix collision and both Sentry monitors report independently.
