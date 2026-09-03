# List of bugs / findings — MagratheaImages3 image storage & admin architecture review

Produced during a planning session covering image storage architecture, backup, and admin-panel security. Item 1 has its own plan document in this folder; items 2-5 are done and their plan docs deleted; items 6-10 are found/flagged but not yet planned — context for each is below the list so a future agent can pick any of them up without re-deriving the finding from scratch.

## Status

**Update (2026-08-21):** items 2 through 5 are all done, plan docs deleted. `FileManager`'s command injection is fixed and verified end-to-end against the real dev environment (regex allowlist widened afterward to permit legitimate size-specific admin patterns like `uuid_200*`; `GeneratedFileAdmin::Pattern()` also given proper error handling so an invalid pattern shows a clean inline alert instead of a MagratheaPHP2 framework quirk re-rendering the whole admin page). CSRF/permission-dispatch: MagratheaPHP2 `v2.3.0` shipped CSRF enforcement (immediately, not via the staged rollout originally planned) and `v2.3.1` closed the `HasPermission()` dispatch bypass; `MediaAdmin::Remove()` was migrated GET→POST app-side. All in changelog `3.6.0` (unreleased). The `composer.json`/`composer.lock` commit/deploy for `2.3.1` is being handled directly by Paulo, outside this workflow.

Current recommended order: `raw-image-backup-to-r2.md` (#1) next — independent, no code blockers, ops/infra work. Then #6-9 as capacity allows. #10 is observational, not actionable.

1. **Backup system for `medias/raw`** — planned, see `raw-image-backup-to-r2.md`. Independent of everything else on this list.
2. **`FileManager` command injection** (`DeleteGeneratedPattern()` shells out to `rm` with unvalidated input) — **done** (2026-08-21). `shell_exec()` removed; regex allowlist (matching the real `{id-or-uuid}_{addon}` generated-filename shape) + `realpath()` containment added to `DeleteGeneratedPattern()` and `DeleteFile()`; tests extended and passing; verified end-to-end against the real dev environment.
3. **CSRF protection, framework side** — **done.** Shipped in MagratheaPHP2 `v2.3.0` as immediate, unconditional enforcement (no staged rollout, contrary to the original plan). Nothing further to do on the framework side.
4. **CSRF protection, app side** — **done.** `MediaAdmin::Remove()` migrated GET→POST (`MediaAdmin.php`/`scripts.js`, 2026-08-21). Plan doc deleted; nothing left open in this repo (composer commit/deploy is Paulo's own, outside this workflow).
5. **`HasPermission()` bypass in admin feature dispatch** — **done**, fixed in MagratheaPHP2 `v2.3.1`. `Start::CheckFeature()` and `views/index.php`'s subpage dispatch now both call `HasPermission()` before dispatching, matching the base `GetPage()` path. No-op for this app (neither `MediaAdmin` nor `GeneratedFileAdmin` override `HasPermission()`).
6. **No storage abstraction over local filesystem** — flagged, no plan written yet. Originally the highest-leverage finding of the whole review; still unplanned despite everything else that's been scoped since.
7. **GD instead of Imagick for image processing** — noted, low urgency.
8. **`fpassthru()` ties up an Apache/PHP worker for the full transfer duration of every image request** — noted, no plan written yet.
9. **No disk-space monitoring** — noted, no plan written yet.
10. **Two independent production instances, no shared infrastructure** — architectural observation, not really a "fix."

## Context for items 5–10

### 5. `HasPermission()` bypass in admin feature dispatch

Location: `src/vendor/platypustechnology/magratheaphp2/src/Admin/Start.php` — `CheckFeature()` (~lines 58-74) and the feature-subpage dispatch in `views/index.php` (~lines 34-63).

`AdminFeature::HasPermission($action)` (`Admin/AdminFeature.php:73-91`) only gets called from inside `AdminFeature::GetPage()`, and `GetPage()` is only reached when the requested `magrathea_feature_subpage` defaults to `"GetPage"`. Two dispatch paths skip it entirely: `CheckFeature()` (`?magrathea_feature=X&magrathea_feature_action=Y`) invokes `$feature->$action()` directly with no permission check at all, and `views/index.php`'s subpage dispatch invokes `$f->$subpage()` for any subpage name other than the default, again bypassing `HasPermission()`. A logged-in admin without permission for a given feature could still reach that feature's actions through either path, sidestepping whatever the UI itself would prevent.

Found while investigating the CSRF gap in the same file — this is a distinct authorization bug, not a CSRF issue, and was deliberately kept out of the CSRF plan. Same caveat as the CSRF fix applies: this is shared-library code (`platypustechnology/magratheaphp2`) with other live consumers (the changelog references "Guia.LOL" as a consumer), so treat a fix as needing the same backward-compatibility care as the CSRF work, not a one-line patch.

### 6. No storage abstraction over local filesystem

Location: primarily `src/api/features/Images/ImageUploader.php`, `ImageResizer.php`, `ImageViewer.php`, `FileManager.php`, and `src/api/features/Apikey/ApikeyControl.php` (`RemoveDir()`).

~15+ call sites across these files call PHP filesystem/GD functions directly against local paths (`move_uploaded_file`, `file_put_contents`, `fopen`/`fpassthru`, `unlink`, `mkdir`, `scandir`, and GD's path-based `imagecreatefromjpeg`/`imagepng`/etc.) with no interface/adapter between business logic and storage. `PathManager.php` centralizes path *construction* only, not storage operations.

This is why the existing test suite (`PathManagerTest.php`, `FileManagerTest.php`, `ImageUploaderTest.php`, `ImageResizerTest.php`, `ApikeyTest.php`, `fileTest.php`) has to create/tear down real files on disk instead of testing against a fake, and it's the blocker for cleanly migrating storage to an object store like Cloudflare R2 (discussed at length in this planning session but not written up as a plan — no driving need was identified, just an exploratory "what would it take" discussion) — without this abstraction, such a migration means touching the same ~15+ call sites directly rather than swapping one implementation behind an interface. This was the original highest-leverage finding from the first critical-review pass of this codebase and remains completely unplanned.

### 7. GD instead of Imagick for image processing

Location: `src/api/features/Images/ImageResizer.php` (`GetRawGD()`, `Save()`, `SaveWebp()`).

All image manipulation uses PHP's GD extension. GD has weaker resampling/resize quality and narrower format support (no HEIC, limited modern-format coverage depending on PHP build) than Imagick, which matters more here than in a typical app since image processing is this service's core function. A quality/capability tradeoff, not a bug or vulnerability — low urgency.

### 8. `fpassthru()` ties up an Apache/PHP worker for the full transfer duration

Location: `src/api/features/Images/ImageViewer.php` — `ViewFile()` (~lines 130-144), `ViewQuickAccess()` (~lines 117-128), and similar raw-serving methods stream image bytes to the HTTP response via `fopen()`/`fpassthru()`.

Each request holds an Apache/PHP worker for as long as the transfer takes. Invisible at low traffic; becomes a real concurrency ceiling before disk I/O or CPU would, since the number of concurrent workers is finite. More likely to become a real problem under traffic growth than the local-disk-vs-object-storage question explored elsewhere in this session.

### 9. No disk-space monitoring

Location: `src/api/shared/SystemApi.php` (`Validate()`, ~line 61) calls `CheckFolder(Config::Instance()->Get("medias_path"))`.

The system health-check validates that `medias_path` exists and has correct permissions, but never checks remaining disk capacity. A full disk fails uploads, but nothing in the app's self-checks surfaces that proactively — it would only be discovered when uploads start failing in production.

### 10. Two independent production instances, no shared infrastructure

Production runs as two fully separate bare-Apache instances (Docker is dev-only), each with its own host disk, its own database, and its own `Apikey.folder` namespace. As of this session, neither has backup, shared monitoring, or shared ops tooling. Not a bug — a standing cost: every operational concern (backups, monitoring, capacity planning, and security fixes like the CSRF/injection work above) has to be independently applied and verified on both instances rather than once. Worth naming since it multiplies the effort of every other item on this list, whether or not the isolation itself was a deliberate choice.
