# R2 backup — phase 5 rollout checklist

Companion to [`r2-backup-module.md`](r2-backup-module.md), phase 5. Copy this
whole file per instance (dev / scripttest / each production host) and work
through it top to bottom. Do not skip ahead — step 6 (arming reconcile) is
gated on 3, 4 and 5 being clean, on purpose: reconcile deletes real objects.

**Known doc bug in the parent plan:** its step 3 says seed with
`--push --limit=0`. Don't do that — in the current code, `--limit=0` means
"push zero rows, exit clean" (`0 ?? default` is still `0`, and
`Query::Limit(0)` renders `LIMIT 0`), not "no limit". It will look like a
successful no-op. Use the loop in step 3 below instead — run `--push` with
**no** `--limit` flag (falls back to `batch_size`, default 200) repeatedly
until nothing is left pending. That also matches the plan's own "resumable,
rerun if it dies" description.

---

## Instance: ______________________ (dev / scripttest / production-A / production-B)

### 0. Before you start

- [ ] SSH access to this host confirmed, as the app-owning user (not root).
- [ ] Cloudflare account access confirmed (dashboard or API token with
      account-level R2 permission to create buckets/tokens).
- [ ] Know this instance's `Config::Instance()->GetEnvironment()` value
      (`dev` / `scripttest` / `production`) — check `src/configs/magrathea.conf`
      on the host if unsure. **If this is production, remember both prod
      instances report as `production`** — see the guardrail note in step 2.

### 1. Cloudflare setup (once per instance)

- [ ] Create the bucket, named `magrathea-images-backup-{instance}` where
      `{instance}` identifies *this physical host*, not the environment name
      (e.g. `magrathea-images-backup-production-a`,
      `...-production-b` — do **not** give both production hosts the same
      bucket name, even though they share the `production` environment
      string).
      - Dashboard: R2 → Create bucket. Location: Automatic unless you have a
        reason to pin it.
      - Confirm the exact bucket name and account ID before moving on — you
        will type both into `r2.conf` in step 2.
- [ ] Enable **bucket versioning** on this bucket (Dashboard → bucket →
      Settings → Object versioning → Enable). This is not optional: it is
      the only recovery path for a mirrored delete. Verify current
      Wrangler/API flag names yourself if you'd rather script this — don't
      trust a remembered CLI incantation for something this consequential.
- [ ] Add a **lifecycle rule**: expire noncurrent versions after 60 days
      (Dashboard → bucket → Settings → Lifecycle rules → Add rule → "Delete
      noncurrent versions" → 60 days).
- [ ] Create an **R2 API token** scoped to *this bucket only*, Object Read &
      Write permission (Dashboard → R2 → Manage API tokens → Create API
      token → scope to the specific bucket, not "all buckets"). Save the
      access key ID and secret access key now — the secret is shown once.
- [ ] Note the account ID (shown on the R2 overview page, same for all
      buckets in the account).

### 2. `r2.conf` on the host

- [ ] `cp src/configs/r2.conf.sample src/configs/r2.conf` on the host (path
      is relative to wherever `MagratheaPHP::Instance()->GetConfigRoot()`
      resolves to on this install — normally `src/configs/`).
- [ ] Fill in the `[{instance}]` section — `account_id`, `bucket`,
      `access_key`, `secret_key` — from step 1. Leave `enabled = false` for
      now.
- [ ] **Stop. Re-read `bucket` against which physical host you are on**,
      out loud if you have to. A `r2.conf` copied from one production
      instance to the other with the bucket name left unchanged points that
      host's reconcile job at the other instance's data — every object in
      the correct bucket will look like a DB row with no matching R2 key
      (drift), and every object actually in the wrong-target bucket will
      look orphaned. This is the mistake the whole phase-5 ordering (step 6
      last) exists to protect against.
- [ ] `chmod 640 src/configs/r2.conf`, owned by the app user (same as
      `magrathea.conf`).
- [ ] Confirm `r2.conf` is untracked / ignored: `git status` on the host
      should not show it (it's in `.gitignore`).
- [ ] Set `enabled = true`.
- [ ] Sanity check before touching real data:
      `php src/api/backup.php --push --limit=1 --verbose`
      (a real, small, positive limit — not the seed run). Confirm one row's
      `backed_up_at` gets set and the object shows up in the bucket
      (Dashboard → bucket → Objects, or `--reconcile --dry-run` after).

### 3. Seed: push everything pending

Do this off-hours; it walks every existing `raw/` file through the S3 PUT
+ ETag-verify path, which is I/O and CPU work proportional to your image
count.

- [ ] Loop `--push` with no `--limit` (uses `batch_size`, default 200 —
      raise `batch_size` in `r2.conf` first if you'd rather do this in fewer,
      larger batches) until nothing is left:

  ```sh
  while true; do
    out=$(php src/api/backup.php --push --verbose)
    echo "$out"
    # BackupRunner::Push() result includes "succeeded" and "failed" counts;
    # stop once a run processes zero images.
    processed=$(echo "$out" | php -r '
      $j = json_decode(stream_get_contents(STDIN), true);
      echo ($j["succeeded"] ?? 0) + ($j["failed"] ?? 0);
    ')
    [ "$processed" -eq 0 ] && break
    sleep 2
  done
  ```

  It's resumable by construction (rows already backed up are excluded by
  `WHERE backed_up_at IS NULL`) — if it dies partway, just rerun the loop.
- [ ] After the loop exits, confirm via the admin page (`/admin` →
      `r2-backup`, or wherever this install mounts admin features) that
      `pending` is 0 and `exhausted`/`failed` is 0 (or, if non-zero,
      you've inspected each failure's `backup_error` and it's a real
      missing/corrupt local file, not a config problem).

### 4. Push cron + Sentry monitor

- [ ] Add to the app-owning user's crontab (`crontab -e` as that user, **not**
      root):

  ```cron
  7  *  * * *  /usr/bin/flock -n /tmp/mi3-backup-push.lock  /usr/bin/php {app_dir}/src/api/backup.php --push      >> /var/log/magrathea-backup.log 2>&1
  ```

  (fill in `{app_dir}`; leave `--reconcile` out until step 6)
- [ ] Confirm `sentry.php` is present and configured on this host (it's
      gitignored — same category as `r2.conf`) so `Sentry\captureCheckIn`
      actually fires; `BackupRunner` no-ops the check-in silently
      (`function_exists("Sentry\\captureCheckIn")` guard) if the SDK isn't
      wired up, which would hide monitoring gaps rather than error.
- [ ] In Sentry, confirm a Cron Monitor appears for slug
      `mi3-{instance}-backup-push` after the first cron-triggered run (not
      the manual runs above — those also check in, so it may already be
      there). Schedule `7 * * * *`, margin 15 min, max runtime 30 min —
      confirm these match what Sentry shows.
- [ ] **Soak for several days.** Watch the admin page's `pending` count
      trend toward 0 each hour and stay there (new uploads flow in, get
      picked up next run). Watch Sentry for missed check-ins or error
      check-ins.

### 5. Reconcile on cron, dry-run only

- [ ] Add to the same crontab:

  ```cron
  23 4  * * *  /usr/bin/flock -n /tmp/mi3-backup-recon.lock /usr/bin/php {app_dir}/src/api/backup.php --reconcile --dry-run >> /var/log/magrathea-backup.log 2>&1
  ```

- [ ] Each day, read the log output. It should report:
  - a plausible object count (roughly matching the DB row count)
  - **zero orphans** if step 3 finished cleanly and nothing has been
    deleted through the app yet — a nonzero orphan count here on a fresh
    seed is a signal something's wrong (wrong bucket? a delete happened
    mid-seed?), not something to wave through.
  - zero drift, same reasoning.
- [ ] If you delete an image through the app during the soak period, confirm
    the next dry-run lists exactly that key as an orphan-to-be — this is
    your dry-run confirmation for step 7 later, do it deliberately rather
    than waiting for an accidental one.

### 6. Arm reconcile

Only after 4 and 5 have run clean for multiple days. This is the last step,
per instance — it's the one that deletes.

- [ ] Remove `--dry-run` from the crontab line:

  ```cron
  23 4  * * *  /usr/bin/flock -n /tmp/mi3-backup-recon.lock /usr/bin/php {app_dir}/src/api/backup.php --reconcile >> /var/log/magrathea-backup.log 2>&1
  ```

- [ ] Confirm the `mi3-{instance}-backup-reconcile` Sentry monitor
      (`23 4 * * *`, margin 60 min, max runtime 60 min) is green after the
      first armed run.
- [ ] Keep reading the log for the first week or two even though it's
      armed — the guardrails (abort on zero rows / listing error / cap
      exceeded) stop catastrophic mistakes, not every mistake.

### 7. Restore drill

- [ ] Pick one real key. Sync just its `raw/` prefix from R2 into a scratch
      directory (not back onto `medias_path` — this is a drill, don't
      overwrite live data):

  ```sh
  aws s3 sync s3://{bucket}/{folder}/raw/ /path/to/scratch/{folder}/raw/ \
    --endpoint-url https://{account_id}.r2.cloudflarestorage.com
  ```

  (or the equivalent via `rclone` / Cloudflare's S3-compatible tooling —
  whatever you have configured; the R2 credentials from step 1 work as
  standard S3 credentials against that endpoint)
- [ ] Diff the synced file(s) against the live copy under `medias_path` —
      file list and hashes (`md5sum` both sides) should match exactly.
- [ ] Write up what you did and the result as the restore runbook (ask for
      this as a separate task if you want it drafted from this drill's
      output — it belongs in `future-plans/` or wherever runbooks live in
      this repo, not folded into this checklist).
- [ ] Repeat this drill quarterly, per the parent plan.

---

## Cross-instance check (do once both production instances have run steps 3–7)

- [ ] Confirm instance A's R2 token fails when pointed at instance B's
      bucket (attempt a `HeadBucket`/`ListObjects` call with A's
      `access_key`/`secret_key` against B's bucket name — should get an
      auth/permission error, not data). This is your proof the
      bucket-scoped-token isolation in the Decisions table actually holds,
      not just that the config happens to be right today.
