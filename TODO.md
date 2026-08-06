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
