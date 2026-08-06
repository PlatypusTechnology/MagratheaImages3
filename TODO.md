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

**Status:** Deferred — flagged as a known issue, to be fixed deliberately (not as a side effect of
the UUID work). Revisit as its own change.
