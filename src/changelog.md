## 3.6.3
2026-09-28
- **fix:** R2 backup uploads now set `StorageClass: STANDARD` explicitly instead of inheriting the bucket's default storage class; a bucket created with Infrequent Access as its default was billing the IA minimums (no free tier, usage rounded up to a full million-op unit — $9 Class A + $0.90 Class B for a handful of requests) on the hourly push job (`R2\R2Client::PutFile()`). Existing IA objects are not migrated by this change — reset `images_backup_status` and let the push re-upload them

## 3.6.2
2026-09-03
- **new:** `POST /key/{private_key}/clone-from/{public_key}/{image_uuid}` — copies an image owned by `public_key` into the folder owned by `private_key` as a new, independently-owned `images` row (own id/uuid/filename); only the raw file is duplicated, generated/resized variants regenerate lazily under the clone's own id, same as a fresh upload. `public_key` and `private_key` must not belong to the same API key pair (`4036`); cloning counts as a use of the destination key, same as an upload. An optional `subfolder` POST field overrides the default `"cloned"` destination subfolder — the source image's own subfolder is not carried over (`Images\ImagesControl::CloneImage()`)
- **new:** `DELETE /key/{private_key}` — deletes an API key and every image under it in one call (db rows + raw files + generated/resized variants + the key's whole media folder), replacing what previously required looping over the per-image delete endpoint. Requires `public_key` as a query parameter (not a path segment, deliberately, to avoid a caller copying the shape from a sibling route) confirming the key pair; a missing/wrong `public_key` and an unknown `private_key` both return the same `4042`, safe to treat as "already gone." Idempotent — safe to call twice or on an already-deleted key. A file-level warning does not fail the request; check `warnings` in the response (`Apikey\ApikeyControl::DeleteKey()`, already existed for the admin panel — this exposes it as a REST endpoint)

## 3.6.1
2026-08-28
- **improvement:** R2 backup state moved off the `images` table into two dedicated tables — `images_backup_status` (current state, one row per image, created lazily on the first push attempt) and `images_backup_attempts` (append-only, one row per push attempt, kept indefinitely so a retry that eventually succeeds no longer erases every prior failure for that image); `backed_up_at`/`backup_attempts`/`backup_error`/`backup_etag` are dropped from `images` (`database/migrations/migration-3.6.1-image-backup-history.sql`)

## 3.6.0
2026-08-21
- **new:** uploaded images (via `/key/{private_key}/upload` and `/key/{private_key}/upload-url`) now have embedded metadata stripped before storage — EXIF/IPTC/XMP/comments for JPEG, tEXt/zTXt/iTXt/tIME/eXIf chunks for PNG, EXIF/XMP RIFF chunks for WEBP, comment extensions for GIF, and editor cruft (comments, `<metadata>`, Inkscape/Sodipodi namespaces) for SVG; done via structural byte/chunk editing rather than decode+re-encode, so pixel data is untouched — a JPEG's EXIF `Orientation` tag is preserved on its own when present so rotated photos still display correctly (`Images\MetadataStripper`)
- **fix:** `FileManager::DeleteGeneratedPattern()` built a shell command by string concatenation and ran it via `shell_exec()`, letting unvalidated input from the admin `Pattern()` action execute arbitrary shell commands; replaced with `glob()`+`unlink()`, a regex allowlist matching the real `{id-or-uuid}_{addon}` shape generated filenames actually use (so admin patterns like `uuid_200*` for a specific size still work), and `realpath()` containment (also added to `DeleteFile()`)
- **fix:** `MediaAdmin::Remove()` deleted an image via a GET request, which can never be safely protected by a CSRF token (a token embedded in a GET URL leaks via browser history, server/proxy logs, and `Referer` headers); moved to POST
- **fix:** `GeneratedFileAdmin::Pattern()` had no error handling, so an invalid pattern's exception fell through to a MagratheaPHP2 framework path that renders the entire admin page again underneath the error; now caught and shown as a clean inline alert, matching `DeleteFile()`'s existing handling
- **fix:** image objects in API responses were serialized by encoding every public property, so they carried two undocumented internal fields — `placeholder` and `accessId`, both per-request rendering flags — that were never part of the `Image` schema in `swagger.yaml`; `Images` now implements `JsonSerializable` with an explicit allowlist, so responses contain exactly the documented fields and no model property can leak into the API by being added later
- **new:** `images` gains `backed_up_at`, `backup_attempts`, `backup_error` and `backup_etag` (`database/migrations/migration-3.6.0-image-backup.sql`), the state columns for the upcoming R2 raw-image backup module; they are written only by that module and are not exposed in the API
- **improvement:** using Magrathea v.2.3.1 — the admin panel now enforces CSRF protection on every authenticated POST request, and a `HasPermission()` bypass in two admin dispatch paths (`Start::CheckFeature()`, the feature-subpage dispatch) is closed

## 3.5.1
2026-08-09
- **fix:** uploading an image via `/key/{private_key}/upload-url` ignored `max_upload_size`, letting remote URLs bypass the size limit enforced on direct file uploads; `ImageUploader::GetExternalContent()` now streams the download and aborts once it exceeds the configured limit

## 3.5.0
2026-08-06
- **new:** images now have a UUID (`uuid` column, backfilled for existing rows); every image-viewing endpoint accepts either the numeric id or the UUID in the `:id` path segment
- **new:** `force_uuid` config flag to require UUID-only access (id-based requests get a 400)
- **new:** `changelog` endpoint, returning the 5 most recent parsed versions from `changelog.md`
- **new:** `error-codes` endpoint, returning the full map of error codes and messages from `error_codes.conf`
- **improvement:** `private_key`/`public_key` sizes are now configurable via `private_key_size`/`public_key_size`
- **fix:** removed `secure_api` — all image endpoints now always require the public key
- **improvement:** API errors are now centralized through `ErrorCodes` (`error_codes.conf`), giving each error scenario its own numeric code
- **fix:** `MagratheaApiException` throws across the API were passing arguments in the wrong order, so every error response returned HTTP 500 regardless of the intended status; fixed via the `ErrorCodes` migration
- **new:** `validate` endpoint, checking upload size config, media/log folder permissions, and database connectivity
- **new:** `max_upload_size` config to cap uploads below the PHP `post_max_size`/`upload_max_filesize` limits

## 3.4.3
2026-08-04
- **fix:** `ApikeyControl::createKey()` was calling `assertKeyNotInUse()` with the key and private-flag arguments swapped, so the uniqueness check never actually queried for the generated key
- **fix:** `Helper::GetSize()` MB branch re-checked the KB value instead of the MB value, so any size ≥ 1MB always fell through to the GB branch (e.g. a 2MB file showed as "0GB")
- **fix:** `Apikey::ValidateKey()` had an inverted expiration comparison, flagging valid keys as expired and expired keys as valid; now fixed and wired into the upload path (`ImagesApi::GetApiKeyByValue()`) so inactive, expired, or over-usage-limit keys are rejected on upload (uploads by file and by URL)
- **fix:** `Images::FromUrl()`/`FromUploadFile()` derived the filename from the source URL/upload name with no length limit; URLs whose last path segment is a long opaque token (e.g. `lh3.googleusercontent.com` photo URLs) produced filenames past the 255-byte filesystem limit, so `file_put_contents()` silently failed and uploads returned `"image was not uploaded"`. Oversized name segments are now replaced with a deterministic `truncated-<hash>` name.

## 3.4.2
2026-08-01
- **fix:** SVG uploads (unreadable by `getimagesize`) stored null width/height, crashing resizing with a fixed size (`ResampleCalculator` TypeError); now non-resizable images fall back to raw and uploads no longer persist null dimensions

## 3.4.1
2026-07-20
- **fix:** using Open Api admin feature instead of custom Swagger
- **new:** health-check endpoint
- **improvements:** using Magrathea v.2.2.1

## 3.4.0
2026-07-13
- **fix:** removing double data encapsulation on upload image
- **new:** api key deletion function on admin

## 3.3.3
2026-06-23
- **fix:** fixing pagination on /key/images
- **fix:** htaccess fixed

## 3.3.2
2026-04-12
- **new:** swagger Admin Feature.
- **new:** docker creation and destroy by session
- **fix:** now `upload-url` don't block uploads without valid extenstion; checks mime type instead.

## 3.3.1
2026-01-01
- **new:** get images by subfolder
- **fix:** getting svg raw files

## 3.3.0
2025-12-29
- **new:** subfolder for images

## 3.2.2
2025-12-21
- **new:** implementing sentry
- **new:** caddy sample file
- **fix:** invalid variable in `upload-url` error

## 3.2.1
2025-02-06
- png images generate webp images, not png (this will improve size and avoid looking for two image types)
- code cleaning: removing code that was not being called anymore due to 3.2.0 update
- improved performance
- TODO: remove medias by file patterns

## 3.2.0
2025-01-20
**new resize processing functions**
-	now considering png transparency;
-	better performance;
-	removing unnecessary resizes;
-	tests;

## 3.1.7
fixed return on image upload with url: removed duplicate layer of success/data object