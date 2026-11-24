# Migrating an instance to MagratheaImages 3.7.0 (MagratheaPHP 3)

3.7.0 moves the app from **MagratheaPHP2 2.3.1** (`platypustechnology/magratheaphp2`, namespace
`Magrathea2`) to **MagratheaPHP 3.0** (`platypustechnology/magratheaphp`, namespace `Magrathea`).
The public API keeps its routes, parameters, response shapes, JSON types and error codes. Section 1
lists every difference a consumer can see.

This file is the runbook for **each live instance**. Instances run different versions, were
installed at different times, and their databases weren't all built from the same `database.sql`
(it only exists since 3.6.0; older installs got their tables from the 2.x admin). So the steps
below **check** each instance instead of assuming its state.

**Why upgrade every instance:** every version up to 3.6.4 has SQL injection reachable without
authentication:
- `GET /image/{public_key}/{id}/...`: an `{id}` that isn't a UUID went straight into the SQL.
  On 3.6.4, `/image/{public_key}/1abc/details` answers `Unknown column '1abc' in 'WHERE'`.
- the framework's query builder didn't escape array values, and `private_key` comes from the URL.

MagratheaPHP 3 fixes both.

---

## Contents

1. [What changes for API consumers](#1-what-changes-for-api-consumers)
2. [Before you start](#2-before-you-start)
3. [Upgrade path by starting version](#3-upgrade-path-by-starting-version)
4. [Deploy, step by step](#4-deploy-step-by-step)
5. [Database: the timestamp contract](#5-database-the-timestamp-contract)
6. [Timezone: decide per instance](#6-timezone-decide-per-instance)
7. [Verify](#7-verify)
8. [Rollback](#8-rollback)
9. [Known issues 3.7.0 doesn't change](#9-known-issues-370-doesnt-change)
10. [What changed in the code (maintainers)](#10-what-changed-in-the-code-maintainers)

---

## 1. What changes for API consumers

**Unchanged:** every route and parameter; response envelopes (`{success, data}`); JSON value types
(ids, `width`, `height`, `size`, `uses`, `active`… keep their 2.x string/int mix, see the
`$strictTypes` pin in section 10); error codes (`error_codes.conf`) and their HTTP statuses;
HTTP 200 with `success: false` for an unknown route.

**Changed:**

| # | What | Before (≤ 3.6.4) | 3.7.0 |
|---|------|------------------|-------|
| C1 | `created_at` / `updated_at` of the object returned by the call that **creates** it: `data.image` of `upload`, `upload-url`, `clone-from`; `data.apikey` of `key/create` | PHP's `now()` at insert time | `null`. The stored row has the database default; any read endpoint (`image/{pub}/{id}/details`, `key/{priv}/view`, `key/{priv}/images`) returns it |
| C2 | Key order in Apikey JSON (`key/create`, `key/{priv}/view`) | `created_at`, `updated_at` last | first. Irrelevant to JSON parsers; only byte-level comparisons notice |
| C3 | Malformed numeric id (`/image/{pub}/1abc/details`) | HTTP 500, code `5001`, SQL error text | HTTP 404, code `4043` "Image not found" |
| C4 | Existing `created_at`/`updated_at` values, **only on instances whose MySQL timezone differs from `timezone` in the config** | rendered in MySQL's zone | rendered in PHP's zone (shifted), unless the instance sets `db_sync_timezone = false`: see section 6 |
| C5 | An unexpected exception without an error code reaching the router | HTTP 200, `data: {}` | HTTP 500, `data: {"message": …}` |
| C6 | A PHP `\Error` (`TypeError`…) inside an action | uncaught: PHP's fatal error output, or an empty 500 where `sentry.php` installs its exception handler | JSON 500, `"Internal Server Error"` (the message only in DEV) |
| C7 | `GET /version` → `magrathea_version` | `"2.3.1\n"` | `"3.0.0"` |

C5 and C6 are error paths the app's own code doesn't use (its errors go through `ErrorCodes`).
They're listed for completeness.

**Consumers of instances older than 3.6.0** also receive every API change since their version.
Read `src/changelog.md` from their version up. The ones that break clients:
- **3.5.0**: `secure_api` is gone, so every image endpoint requires the public key. Errors return
  their real HTTP status (they were all 500).
- **3.4.0**: the upload response lost a duplicated `data` layer.

Tell those consumers before upgrading their instance.

---

## 2. Before you start

Do this for each instance.

1. **Find its version:**
   ```bash
   curl -s https://<instance>/version
   # or, on the server:
   cat src/version; echo
   ```
2. **Check the requirements on the server**, with the web server's PHP, not just the CLI's:
   - PHP **8.1+**, with `mysqli`, `gd` and `fileinfo`;
   - `short_open_tag = On`: the admin's views open PHP with `<?`. Without it they print their code
     as text. This isn't new, but a PHP upgrade on the same server can turn it off;
   - Composer running on PHP 8.1+;
   - optional: `proc_open()` enabled, for the admin's Database → Dumps (`mysqldump` without a shell).
3. **Back up**, and keep the backups outside the web root:
   - the database: `mysqldump --single-transaction --routines --triggers <db> > before-3.7.0.sql`;
   - the instance's own files, which git doesn't track: `src/configs/magrathea.conf`,
     `src/configs/r2.conf`, `src/api/sentry.php`, `src/api/.user.ini`.

   The migration doesn't touch `medias/`, but an instance without a media backup should get one anyway.
4. **Record API snapshots** to compare after the deploy (section 7). Pick one existing key pair and
   one image on that instance:
   ```bash
   B=https://<instance>; PRIV=<private_key>; PUB=<public_key>; ID=<image id>
   mkdir -p snapshots-before && cd snapshots-before
   for p in version settings health-check "key/$PRIV/view" "key/$PRIV/images" "image/$PUB/$ID/details"; do
     curl -s "$B/$p" > "$(echo "$p" | tr '/' '_').json"
   done
   curl -s -o thumb.bin "$B/image/$PUB/$ID"; curl -s -o raw.bin "$B/image/$PUB/$ID/raw"
   ```
   These hold real keys: don't commit them, and delete them when done.
5. **Pause the R2 backup cron** (`backup.php`), if the instance has one, until section 7 passes.

---

## 3. Upgrade path by starting version

Code and dependencies are replaced as a whole, so the starting version decides only two things:
which SQL files to run (in order), and which settings to review.

| Starting version | SQL to run, in this order (`database/migrations/`) |
|------------------|----------------------------------------------------|
| 3.6.1 – 3.6.4 | none |
| 3.6.0 | `migration-3.6.1-image-backup-history.sql` |
| 3.5.x | `migration-3.6.0-image-backup.sql`, then `migration-3.6.1-image-backup-history.sql` |
| 3.3.x – 3.4.x | `migration-3.5.0-image-uuid.sql`, `migration-3.6.0-image-backup.sql`, `migration-3.6.1-image-backup-history.sql` |
| 3.2.x | `migration-3.3.0.sql`, then all of the above |
| older than 3.2 | stop: compare the database with `database/database.sql` by hand first |

```bash
mariadb -u <user> -p <database> < database/migrations/<file>.sql
```

- Run 3.6.0 **and** 3.6.1 even though 3.6.1 drops what 3.6.0 adds. 3.6.1 drops 3.6.0's index and
  columns, so it fails without them.
- None of these files is safe to run twice (`ADD COLUMN`, `CREATE TABLE`). If one fails halfway,
  look at the table (`SHOW CREATE TABLE images;`) before running anything else.
- `migration-3.5.0-image-uuid.sql` runs `UPDATE images SET uuid = UUID()` on every row. On a large
  `images` table, run it in a maintenance window.

**Settings that appeared along the way.** All of them are optional, unless noted:

| Since | Where | Setting |
|-------|-------|---------|
| 3.2.2 | file `src/api/sentry.php` | Sentry (copy `sentry.example.php`) |
| 3.5.0 | `magrathea.conf`, instance section | `force_uuid` (default `false`) |
| 3.5.0 | admin → App Configuration | `max_upload_size`, `private_key_size`, `public_key_size` |
| 3.6.0 | file `src/configs/r2.conf` + cron | R2 backup (see `install.md` → "Optional: R2 backup") |
| any | admin → App Configuration | `secret`: **required** by `POST /key/create` |

---

## 4. Deploy, step by step

### 4.1 Code

Deploy the 3.7.0 code (git checkout/pull, or the instance's usual method). Then:

- **Deploys that copy files over the old ones** (FTP, `rsync` without `--delete`) leave files that
  3.7.0 deleted. Remove them:
  ```bash
  rm -rf src/api/features/Apikey/Base src/api/features/Images/Base \
         src/configs/magrathea_objects.conf src/vendor/platypustechnology/magratheaphp2
  ```
  The framework still registers `features/*/Base`, and the old `Base/` classes still name
  `Magrathea2`: `models:verify` loads every class in the code folders and warns that those can't
  load.
- The files from step 2.3 (`magrathea.conf`, `r2.conf`, `sentry.php`, `.user.ini`) stay as they are.

### 4.2 Dependencies

```bash
cd src
composer install --no-dev   # drop --no-dev if the instance uses the admin's Tests page (needs phpunit)
```
`composer.lock` installs `platypustechnology/magratheaphp` v3.0.0 and removes `magratheaphp2`. If
Composer refuses to run, it's on PHP < 8.1.

### 4.3 SQL

Run the files from section 3.

### 4.4 Config (`src/configs/magrathea.conf`)

In the instance's environment section, and in `[general]`:
- **`timezone`**: must be set in the environment section. Then make the section 6 decision
  (`db_sync_timezone`) **now**, before the instance serves traffic.
- **`time_zone`** (with an underscore) and any copy in `[general]`: never read. Delete it.
- **`db_port`**: 2.3.1 ignored it, 3.0 uses it. If it's there, it must be the real port, or the
  connection fails. With `db_host = "localhost"`, the socket is used and the port doesn't matter.
- **`bootstrap_unlocked`**: must not exist outside `[dev]`. With it, `setup.php` opens on the live
  instance.
- **`secure_api`**: removed in 3.5.0, ignored. Delete it.

### 4.5 Framework checks

```bash
cd src
vendor/bin/magrathea check            # config, folders, database, timezone, schema
vendor/bin/magrathea models:verify    # the Apikey and Images models against their tables
```
Both must report **no ERROR** (exit code 0). The CLI loads `src/api/_inc.php` (declared in
`composer.json`), so it reads the same config as the web server. Run it as a user that can read
`magrathea.conf`. A schema ERROR or WARNING on `created_at`/`updated_at` is section 5. INFO lines
for `images_backup_status`/`images_backup_attempts` ("no model maps the table") are expected.

### 4.6 Folders the web server must write

`check` covers logs, cache and backups. It doesn't cover these two app folders. Check them as
the web server user:
- `medias_path` (as before);
- **`src/api/features/Apikey/cache/`**: `key/create` and key deletion regenerate
  `ApikeyCache.php` there, and **a failed write is silent**. Every image request then answers
  `4041 Public Key not found` for the new key. A deploy that changes the owner of the code
  (`git pull` as another user) breaks this.
  ```bash
  sudo -u www-data test -w src/api/features/Apikey/cache && echo ok
  ```
  After the deploy, regenerate the file once from the admin (Api Keys → Cache → Create). It also
  proves the write works.

### 4.7 Admin users

- Every admin is **logged out once**: 3.0 can't read 2.x sessions.
- Users with `active = 0` (or `NULL`) **can no longer log in**. List them and decide:
  ```sql
  SELECT id, email, active FROM `_magrathea_users` WHERE active IS NULL OR active = 0;
  ```
- Every failed login now says "Invalid email or password".
- Locked out? `vendor/bin/magrathea admin:reset-password <email>` prints a new password.
- The menu gains MagratheaPHP 3's sections: **Objects**, a generic CRUD of every model, including
  `Apikey` with its private keys; and **Database** (Explorer, Queries, Dumps, Checks). Every admin
  user sees them. If that's too much for some admins, they shouldn't be admins.

### 4.8 Web server

- The document root stays `src/api/`. `src/configs/` is its sibling, so it's outside the web
  root, and it must stay that way: it holds the DB password and, on fresh installs, the setup token.
- `https://<instance>/setup.php` must answer **403**. That's the 3.0 wizard, locked.
- Optional hardening (unchanged by 3.7.0): deny `setup.php`, `magrathea.php`, `backup.php` and
  `tests/` at the web server. Only humans and cron need them.

### 4.9 Cron (R2 backup)

The command doesn't change (`php /path/to/src/api/backup.php --push` / `--reconcile`). It now
loads from any working directory. A broken bootstrap now **exits non-zero**: it used to print the
exception and carry on. A run holds one database connection, which only matters if a single R2
upload outlasts MySQL's `wait_timeout`. Re-enable the cron after section 7.

---

## 5. Database: the timestamp contract

**Why it matters now.** MagratheaPHP 2 wrote `updated_at = now()` from PHP on every update.
MagratheaPHP 3 never writes `created_at`/`updated_at`. Inserts rely on `DEFAULT CURRENT_TIMESTAMP`,
and updates rely on `ON UPDATE CURRENT_TIMESTAMP`. Without `ON UPDATE`, a table's `updated_at`
**silently stops changing**. `apikey.updated_at` changes on every upload (the `uses` counter).

Tables built from `database/database.sql` (3.6.0+) already comply. Older installs may not: the 2.x
admin created these columns as `varchar(255) NULL` in some versions. `models:verify` (4.5) tells you:

```
OK      [schema] `apikey`.`updated_at` follows the contract (`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).
ERROR   [schema] `apikey`.`updated_at` has no `DEFAULT CURRENT_TIMESTAMP` ... and no `ON UPDATE CURRENT_TIMESTAMP` ...
        ALTER TABLE `apikey` MODIFY `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
```

**Fixing a finding:**
1. Look at the column: `SHOW CREATE TABLE apikey;` (and `images`).
2. **`datetime` or `timestamp` column:** run the `ALTER` that `models:verify` printed. It keeps the
   type and nullability. `MODIFY` redefines the whole column, so add back any `COMMENT` it had.
3. **Any other type (`varchar`…):** **don't** run the printed fix. For these columns MagratheaPHP
   3.0.0 prints invalid SQL (`DEFAULT CURRENT_TIMESTAMP(255)`). Check that every value parses as a
   date first:
   ```sql
   SELECT id, created_at FROM images
   WHERE created_at IS NOT NULL AND STR_TO_DATE(created_at, '%Y-%m-%d %H:%i:%s') IS NULL LIMIT 20;
   ```
   Nothing listed? Convert it:
   ```sql
   ALTER TABLE `images` MODIFY `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
   ALTER TABLE `images` MODIFY `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
   ```
   If rows are listed, fix or clear those values first. Decide that with whoever owns the data.
4. **Size first:** an `ALTER` can rebuild the table and block writes (uploads) while it runs.
   ```sql
   SELECT table_name, table_rows FROM information_schema.tables
   WHERE table_schema = DATABASE() ORDER BY table_rows DESC;
   ```
   Run it in a maintenance window, after the dump from step 2.3. Then rerun `models:verify` until
   it's clean.
5. Optional: fill the `NULL` `updated_at` values a broken column left behind:
   `UPDATE apikey SET updated_at = created_at WHERE updated_at IS NULL;` (same for `images`).

**Optional, admin table.** In databases installed by the 2.x admin, `_magrathea_users.last_login`
may have picked up `ON UPDATE CURRENT_TIMESTAMP`. That happens under MySQL 5.7's
`explicit_defaults_for_timestamp=OFF`, because it's the table's first `TIMESTAMP`. It then changes
on every edit of the user. To check and fix:
```sql
SHOW CREATE TABLE `_magrathea_users`;
ALTER TABLE `_magrathea_users` MODIFY `last_login` TIMESTAMP NULL DEFAULT NULL;   -- only if it has ON UPDATE
```

---

## 6. Timezone: decide per instance

MagratheaPHP 3 sets MySQL's **session** `time_zone` to the config's `timezone` on every
connection. The goal is that `NOW()` and the column defaults agree with PHP. Where MySQL already
runs in that zone, nothing changes. Where it doesn't, `TIMESTAMP` columns (`apikey` and `images`
`created_at`/`updated_at`) **read back converted**. The stored instant is the same, but the string
the API returns moves by the offset.

This was measured during the 3.7.0 testing, with MySQL on UTC and `timezone = "America/Sao_Paulo"`.
The same row read through the API:
- 3.6.4 (no sync): `"created_at": "2026-11-24 16:15:14"` (UTC);
- 3.7.0 (sync on, the default): `"created_at": "2026-11-24 13:18:39"` (São Paulo);
- 3.7.0 with `db_sync_timezone = false`: `"created_at": "2026-11-24 16:19:05"` (UTC, as before).

**Check the instance:**
```sql
SELECT @@global.time_zone, @@system_time_zone, NOW();
```
Compare that with the config's `timezone`. `vendor/bin/magrathea check` prints both sides in its
`[timezone]` group.

**Decide:**

| MySQL's zone | Do |
|--------------|----|
| Same as `timezone` | nothing |
| Different, and consumers must see the same values as before (**recommended for 3.7.0**) | add `db_sync_timezone = false` to the instance's environment section. Existing and new `created_at` values keep MySQL's clock, as in 3.6.4 |
| Different, and you want one clock everywhere | leave the sync on. Tell consumers that every existing `created_at`/`updated_at` shifts by the offset once |

With `db_sync_timezone = false`, one small difference remains: `updated_at` now comes from MySQL's
`ON UPDATE`, so it's on MySQL's clock. 3.6.4 wrote PHP's clock on updates. Only `apikey.updated_at`
gets updated in practice.

If MySQL's timezone tables aren't loaded, the sync uses a fixed offset (`-03:00`), which doesn't
follow DST. `check` says which one is in use. To load them:
`mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql`.

---

## 7. Verify

- [ ] `GET /version` shows `"version": "3.7.0"` and `"magrathea_version": "3.0.0"`.
- [ ] `GET /health-check` → `"database": "ok"`; `GET /validate` → `"valid": true`.
- [ ] The snapshots from step 2.4, taken again, differ only by C1–C7 (section 1). For
      `key/{priv}/view`, expect C2, plus C4 if you left the sync on with different zones.
      `thumb.bin`/`raw.bin` must be identical.
- [ ] Upload a test image (`POST /key/{priv}/upload`). It answers `success: true` with `created_at: null` (C1).
      Then `GET /image/{pub}/{id}/details` returns real timestamps, and the thumb, a size
      (`/x/100x100`) and `/raw` render. Delete it (`DELETE /key/{priv}/delete/{id}`).
- [ ] Wait a second and upload again with the same key: `key/{priv}/view` shows `updated_at` later
      than `created_at`. That's the `ON UPDATE` from section 5 at work.
- [ ] `vendor/bin/magrathea check` and `models:verify`: no ERROR.
- [ ] `setup.php` answers 403.
- [ ] Admin: log in, then open Api Keys (list, a key's form), Medias, Generated Files, Images CRUD,
      R2 Backup, App Configuration and Database → Checks. Regenerate the Apikey cache (4.6).
- [ ] Sentry (if configured): `GET /test-sentry` answers 500 and shows up in Sentry.
- [ ] R2 (if enabled): `php src/api/backup.php --push --limit=1 --verbose` succeeds. Then re-enable the cron.
- [ ] The logs (`logs_path`) show none of `Unknown field type`, `Call to undefined method`,
      `Class "…" not found`, `Access level`.
- [ ] Delete the snapshot files.

---

## 8. Rollback

- **Code:** redeploy the previous version (e.g. 3.6.4) and run `composer install` with that
  version's `composer.lock`. That reinstalls `magratheaphp2` 2.3.1. Remove
  `src/vendor/platypustechnology/magratheaphp` if the deploy copies files over.
- **Database:** 3.7.0 adds no migration of its own. The section 5 `ALTER`s are compatible with
  3.6.4, and so are the section 3 files (3.6.4 needs them too). Nothing to undo.
- **Config:** `db_sync_timezone` is ignored by 2.3.1. It can stay.
- **Admins:** logged out again.
- Rolling back reopens the SQL injection described at the top of this file.

---

## 9. Known issues 3.7.0 doesn't change

These were found during the migration and behave the same on 3.6.4.
- **MySQL 8 (not MariaDB): raw filenames can collide.** A new image's raw file is named
  `{next auto-increment}_{name}`, read with `SHOW TABLE STATUS`. MySQL 8 caches that value for
  `information_schema_stats_expiry` seconds (default 86400). Two uploads of the same file name
  within that window can then get the same name, and the second overwrites the first's raw file. On
  a MySQL 8 instance, set `information_schema_stats_expiry = 0` in the server config. The read is
  also racy under concurrent uploads on any server.
- **Admin → Api Keys → edit a key with an empty expiration** fails in MySQL strict mode
  (`Incorrect datetime value: ''`). Set a date, or clear it in the database.
- **`ApikeyCache.php` write failures are silent** (see 4.6).

---

## 10. What changed in the code (maintainers)

Following MagratheaPHP's own guide,
`src/vendor/platypustechnology/magratheaphp/docs/mds/migrations/migrate-3.0.md` (and
`migrate-2.4.0.md`, since 2.3 → 3.0 is one hop). The section numbers are that guide's:

| Guide item | In this app |
|------------|-------------|
| Composer | `magratheaphp2: 2.3.1` → `magratheaphp: ^3.0` (lock: v3.0.0); `extra.magrathea.bootstrap = api/_inc.php` for the CLI |
| M2 rename | `Magrathea2` → `Magrathea` in every PHP file |
| M3 generator removed | `magrathea_objects.conf` deleted; the generated `Base/` classes merged into `Apikey`/`Images` (single classes), with `ApikeyControl`/`ImagesControl` declaring `protected static $model`. Relations are plain methods (`Apikey::GetImages()`, `Images::GetApikey()`); `created_at`/`updated_at` come from `MagratheaModel` |
| M5 removed methods | `GetNextID()` → private `Images::NextAutoIncrement()` (same query, so filenames don't change); `CloseConnectionThanks()` dropped from `SystemApi::CheckDatabase()`; `StartDB()` → `Connect()` in `_inc.php` |
| M7 field types | already valid (`int`, `string`, `datetime`, `boolean`, `uuid`) |
| M9 strict types | `protected $strictTypes = false;` on `Apikey` and `Images`. **Don't remove it** without telling the consumers: ids, `uses`, `width`… would turn from `"1"` into `1` in JSON |
| M10 timestamps on insert | accepted as API change C1 |
| M11/M13 timestamp contract | `database.sql` already complies; per-instance check in section 5 |
| M24 CLI | `_inc.php` prints nothing, catches nothing, and anchors every path on `__DIR__` |
| M27 setup wizard | `setup.php` is the 3.0 entry file (`Start::Instance()->AppPath(__DIR__)->Load()`) |
| M29 CORS | `AddAcceptHeaders()` now appends; the list already had `Authorization`/`Content-Type`, so there's no difference |
| M30 `p_r($ex)` | `index.php`, `admin.php` and `magrathea.php` log, report to Sentry and answer a generic 500 |
| 2.4 #16 HTTP statuses | `MagratheaImagesApi::ReturnError()` keeps its `$status = 200` default on purpose. MagratheaPHP 3's is `null` (status from the code), which would turn the unknown route's HTTP 200 into 404 |
| 2.4 #18 `\Error` → JSON 500 | `MagratheaImagesApi::ReturnInternalError()` reports them to Sentry. The framework catches them now, so Sentry's global handler no longer sees them |
| 2.4 #23 changed-fields `Update()` | `Apikey::IncrementUses()` now writes only `uses` (2.3.1 rewrote the whole row) |
| 2.4 #30 one connection per request | `backup.php` runs are short; nothing to do |
| 2.4 #48 POST-only writes | `AdminPostOnly` trait: `ApikeyAdmin` `Create`/`DeleteApikey`/`CreateCache`, `MediaAdmin::Remove()`, `GeneratedFileAdmin` `DeleteFile`/`Pattern` answer 405 to anything but POST (`createCache()` in the admin JS now POSTs) |
| Not applicable | M4 (no File Editor), M6 (no conflicting redeclarations), M8 (no `$model`/`GetDbTable()` clash), M12 (no code bumps `updated_at` on purpose), M17a (no `mysqli_report`), M20a (no `Values()`/`Set()` calls) |

**How 3.7.0 was verified:**
- The PHPUnit suite: 183 tests, green, as on 3.6.4.
- The same 36 API calls (success and error paths: keys, uploads by file and URL, details by
  id/uuid, thumb/sized/raw/preview bytes, clone, deletes) run against a fresh MariaDB on 3.6.4 and
  on 3.7.0. The only differences are C1, C2, C3 and C7; the image bytes are identical.
- An admin smoke test on 3.7.0: login, every custom feature, the POST guards (GET → 405), CSRF
  (a token-less POST is refused) and the Magrathea pages.
- `vendor/bin/magrathea check` and `models:verify`, including against legacy column definitions.
