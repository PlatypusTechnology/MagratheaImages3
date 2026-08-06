# TODO

## Upload size error handling is unclear

**Where:** `src/api/features/Images/ImageUploader.php`, `src/api/features/Images/ImagesApi.php`

The max upload size isn't a fixed app constant — `ImageUploader::getMaximumFileUploadSize()`
(ImageUploader.php:181-183) derives it as `min(post_max_size, upload_max_filesize)` from PHP's
own ini settings, and it's exposed read-only via `GET /settings` (`upload_limit_bytes` /
`upload_limit`, api.php:56-64).

There's no explicit app-level check against that limit, so oversized uploads fail with generic,
unhelpful errors instead of a clear "file too large":

- **File > `upload_max_filesize`, POST body still under `post_max_size`:** PHP populates
  `$_FILES["file"]` with `error = UPLOAD_ERR_INI_SIZE`, `tmp_name = ""`, `size = 0`. This isn't
  checked anywhere (`ImagesApi.php:176-179` only checks `empty($_FILES)`). Execution proceeds
  into `ImageUploader::Upload()`, `move_uploaded_file()` silently fails on the empty tmp path,
  and the API returns `{"success": false, "error": "image was not uploaded", "data": {...}}` —
  no mention that the cause was file size.

- **Total POST body > `post_max_size`:** PHP discards the whole request; `$_FILES`/`$_POST` come
  back empty. Caught by `empty($_FILES)` in `ImagesApi.php:176`, which throws
  `MagratheaApiException("File not received", ..., 500)` — also generic, and arguably should be
  a 413 rather than a 500.

**Proposed fix:** check `$_FILES["file"]["error"]` for `UPLOAD_ERR_INI_SIZE` /
`UPLOAD_ERR_FORM_SIZE` and return a clear error (e.g. 413) that states the configured limit,
instead of falling through to the generic "image was not uploaded" / "File not received"
messages.

**Status:** Deferred — UUID implementation work takes priority. Revisit after that lands.

## Every `MagratheaApiException` throw returns HTTP 500, regardless of the coded status

**Where:** every `throw new MagratheaApiException(...)` call site in `src/api/` (~30, e.g.
`ImagesApi.php`, `ApikeyApi.php`, `ImageResizer.php`, `ImageViewer.php`, `ImageUploader.php`) —
found while verifying Phase 3 of `plan-uuid.md` (the `force_uuid=true` rejection was supposed to
return 400 but returns 500).

The vendored framework's real constructor (`platypustechnology/magratheaphp2`,
`src/vendor/.../Exceptions/MagratheaApiException.php` — gitignored, not part of this repo's git
history) is:
```php
__construct($message = "...", $code = 0, $data = null, $kill = true, ?\Exception $previous = null)
```
Every call site in this repo instead calls it positionally as
`(message, boolKill, intendedHttpCode, extraData)`, e.g.:
```php
throw new MagratheaApiException("Image not found", true, 404, $params);
```
So `true` lands in `$code` (cast to `1`), the intended HTTP status (`404`) lands in the unused
`$data` slot, and `MagratheaApi::ReturnApiException()` — which derives the response status from
`$exception->getCode()` — always falls through to its `else` branch (500), since `1` isn't a valid
HTTP-range code. Net effect: **every** API error response in this project currently returns
HTTP 500, no matter what status the call site clearly intended (404, 400, 403, 415, ...). This
predates the UUID work (confirmed pre-existing, e.g. the untouched "Image not found" line above)
but was exposed anew by `ImagesApi::ResolveImage()`'s `force_uuid` rejection
(`ImagesApi.php:33`), which should return 400 per `plan-uuid.md` but currently returns 500.

**Proposed fix:** reorder arguments at each of the ~30 call sites in this repo to match the real
constructor signature (`message, code, data, kill, previous`). The framework class itself lives in
a separate vendored package and can't be fixed from this repo.

**Status:** Fixed. Every `throw new MagratheaApiException(...)` call site in `src/api/features/`
(plus a few plain `MagratheaException` throws in the same files) was replaced with
`ErrorCodes::Instance()->ThrowException($code, $data, $detail)` (`src/api/error-manager/`),
which always calls the constructor with the correct argument order. `error_codes.conf` now has a
dedicated numeric code per error scenario (400/401/403/404/415/500 buckets with 4-digit
sub-codes), so `MagratheaImagesApi::ReturnApiException()`'s `intval($code/10)` HTTP-status
derivation now works as originally intended — `force_uuid` rejection returns 400, "not found"
scenarios return 404, etc. `error-manager` also had to be registered in `_inc.php`'s
`AddCodeFolder(...)` list, since the app's autoloader is a flat scan over registered folders and
didn't know about the new directory.
