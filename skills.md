# MagratheaImages API — Skills Guide for AI Agents

This document teaches AI agents how to correctly interact with the MagratheaImages 3 API.
The full machine-readable contract is in `swagger.yaml` at the project root.

---

## Mental model

MagratheaImages is an **image hosting and on-demand resizing** service. The core concepts are:

- **Apikey** — a credential pair (`private_key` / `public_key`) tied to a folder on disk.
  The private key is used to **write** (upload, delete).
  The public key is used to **read** (view images).
- **Image** — a record of an uploaded file, stored under the key's folder. Every image has both
  a numeric `id` and a `uuid` — either can be used to address it in view/preview URLs (see below).
- **Every image-viewing endpoint requires the `public_key`** in the path. There is no keyless
  "public mode" — as of v3.5.0 the old `secure_api` toggle was removed and image URLs always
  take the shape `/image/{public_key}/{id}/...`.

---

## Step 0 — discover the server

Before anything else, confirm the server is reachable and learn its config:

```
GET /version
GET /settings
```

`/settings` returns:
```json
{
  "thumb_size": 300,
  "upload_limit_bytes": 10485760,
  "upload_limit": "10 MB",
  "force_uuid": false
}
```

`force_uuid: true` means the `:id` segment on every image endpoint **must** be a UUID —
sending a numeric id returns HTTP 400 (error code `4004`, see [Error shape](#error-shape)).

For deeper diagnostics, two more system endpoints exist:

```
GET /changelog      -- 5 most recent versions parsed from changelog.md
GET /error-codes    -- full map of error codes -> messages (see Error shape)
GET /validate       -- checks upload-size config, media/log folder permissions, DB connectivity
```

`/validate` returns:
```json
{
  "valid": true,
  "checks": {
    "max_upload_size": "ok",
    "medias_path": "ok",
    "logs_path": "ok",
    "database": "ok"
  }
}
```
Use it to sanity-check a new deployment before relying on it.

---

## Key concepts

### Two types of keys

| Key | Default length | Purpose |
|---|---|---|
| `private_key` | 25 chars (configurable via `private_key_size`) | Upload, delete, list images with secret info |
| `public_key` | 12 chars (configurable via `public_key_size`) | Read / display images, list images publicly |

Never expose the `private_key` in client-side code or public URLs.
The `public_key` is safe to embed in HTML `<img src>` attributes.

### Image identifiers — id vs UUID

Every image has a numeric `id` (auto-increment) and a `uuid` (assigned on creation). Any
`:id` path segment on an image-viewing endpoint accepts **either** form — the server detects
a UUID with a `[0-9a-f-]{36}` pattern and looks it up accordingly. Use whichever you already
have; there's no need to resolve one to the other.

The **delete** endpoint (`DELETE /key/{private_key}/delete/{id}`) is the one exception — it
only accepts the numeric `id`, not the UUID.

If the server has `force_uuid` enabled (see Step 0), numeric ids are rejected on the
UUID-capable endpoints (400, error code `4004`) — always use the `uuid` from the image object
in that case.

### Supported image formats

`jpg`, `jpeg`, `png`, `bmp`, `webp`, `wbmp`, `svg`

SVGs cannot be resized — they are always served raw regardless of which size endpoint is called.

### Size format

Whenever a size is needed in a path or query string, use `WxH` notation: `800x600`, `1920x1080`, `400x400`.

---

## Workflow 1 — Provision a new key

Call `POST /key/create` with the server `secret` (a shared secret configured on the server, not a key pair) and a `folder` name. The folder name should be a simple slug (no spaces, no slashes).

```
POST /key/create
Content-Type: application/x-www-form-urlencoded

secret=<server-secret>&folder=myblog
```

Response:
```json
{
  "apikey": {
    "id": 7,
    "private_key": "a1b2c3d4e5f6g7h8i9j0k1l2m",
    "public_key": "abc123def456",
    "folder": "myblog",
    "uses": 0,
    "usage_limit": 0,
    "expiration": null,
    "active": true
  },
  "paths": {
    "folder": "myblog",
    "create_base": { "success": true },
    "create_raw":  { "success": true },
    "create_gen":  { "success": true }
  }
}
```

Save both keys. You will need `private_key` to upload/delete, and `public_key` to construct image URLs.

---

## Workflow 2 — Upload an image

There are four upload endpoints. Choose based on what you have. Uploads are capped by
`upload_limit_bytes` from `/settings` (server config `max_upload_size`, bounded by PHP's
`post_max_size`/`upload_max_filesize`).

### Upload a file — key in body

```
POST /upload
Content-Type: multipart/form-data

file=<binary>
key=<private_key>
subfolder=<optional>
```

### Upload a file — key in URL path (preferred for cleaner code)

```
POST /key/<private_key>/upload
Content-Type: multipart/form-data

file=<binary>
subfolder=<optional>
```

### Upload from a remote URL — key in body

```
POST /upload-url
Content-Type: application/x-www-form-urlencoded

private_key=<private_key>&url=https://example.com/photo.jpg&subfolder=<optional>
```

### Upload from a remote URL — key in URL path

```
POST /key/<private_key>/upload-url
Content-Type: application/x-www-form-urlencoded

url=https://example.com/photo.jpg&subfolder=<optional>
```

### Upload success response

File upload (`/upload`, `/key/:private_key/upload`) returns:
```json
{
  "success": true,
  "data": {
    "image": {
      "id": 42,
      "uuid": "0193f2a4-1b2c-7def-8a9b-1234567890ab",
      "name": "my_photo",
      "filename": "42_my_photo.jpg",
      "extension": "jpg",
      "folder": "myblog",
      "subfolder": null,
      "width": 1920,
      "height": 1080,
      "file_type": "image/jpeg",
      "size": 204800,
      "upload_key": "7",
      "created_at": "2025-06-01T12:00:00",
      "updated_at": "2025-06-01T12:00:00"
    },
    "public_key": "abc123def456"
  }
}
```

All upload endpoints — file and URL — return the same `UploadSuccessResponse` wrapper above.

**Always check `success: true`** before using `data.image`.

### Upload failure response

```json
{
  "success": false,
  "data": {
    "type": "exception",
    "error": null,
    "code": 4151,
    "message": "Invalid image extension: exe",
    "debug_level": "none"
  }
}
```

---

## Workflow 3 — Display an image

Every image-viewing endpoint takes the `public_key` and the image `id` (numeric or UUID, see
[Image identifiers](#image-identifiers--id-vs-uuid)) in the path.

| Goal | URL pattern |
|---|---|
| Thumbnail | `GET /image/{public_key}/{id}/thumb` |
| Specific size | `GET /image/{public_key}/{id}/x/{WxH}` |
| Auto-size (query params) | `GET /image/{public_key}/{id}?w=800&h=600` |
| Auto-size (query param) | `GET /image/{public_key}/{id}?size=800x600` |
| Original file | `GET /image/{public_key}/{id}/raw` |
| Metadata only | `GET /image/{public_key}/{id}/details` |

### Size resolution order for `/image/{public_key}/{id}`

The server resolves dimensions in this priority order:
1. Path segment `/x/{WxH}` — most explicit, use this when you know the size
2. Query param `?size=800x600`
3. Query params `?w=800&h=600` (aliases: `width`, `height`)
4. No dimensions → falls back to thumbnail

### Image modifier query parameters

These work on all image-serving endpoints:

| Param | Values | Effect |
|---|---|---|
| `stretch` | `0` (default), `1` | Ignore aspect ratio; stretch to exact WxH |
| `placeholder` | `0` (default), `1` | Return a low-quality blurred placeholder (for lazy loading) |
| `generate` | `0` (default), `1` | Force re-generation even if a cached file already exists |

### Caching behavior

Generated/resized images are saved to disk automatically on first request. Subsequent requests for the same size return the cached file immediately. Use `?generate=1` to bust the cache.

---

## Workflow 4 — List images for a key

```
GET /key/{private_key}/images
```

Optional query params:
- `page` — zero-based page index (default `0`)
- `subfolder` — filter to a specific subfolder

Returns 12 images per page, ordered newest-first. When `has_more` is `true`, increment `page` to fetch the next batch.

```json
{
  "private_key": "a1b2c3d4e5f6g7h8i9j0k1l2m",
  "public_key": "abc123def456",
  "page": 0,
  "images": [ /* Image objects */ ],
  "has_more": true,
  "timestamp": "2025-06-01T12:00:00"
}
```

---

## Workflow 5 — Delete an image

Deletion requires the **private key** and the image `id`. Unlike the viewing endpoints,
**only the numeric `id` is accepted here — not the `uuid`.**

```
DELETE /key/{private_key}/delete/{id}
```

Response:
```json
{
  "del_image": true,
  "del_file": {
    "file": "42_my_photo.jpg",
    "deleted": {
      "del_file": true,
      "del_generated": true
    }
  }
}
```

`del_image` — the database record was removed.
`del_file.deleted.del_file` — the original uploaded file was removed from disk.
`del_file.deleted.del_generated` — all resized/generated variants were removed from disk.

---

## Workflow 6 — Preview without caching (testing / admin)

Use `/preview/{size}` when you want to test resize output without polluting the cache.

```
GET /image/{public_key}/{id}/preview/800x600
GET /image/{public_key}/{id}/preview/thumb
```

Add `?debug=1` to get a JSON description of the resize operation instead of the binary:
```json
{
  "id": 42,
  "name": "my_photo",
  "extension": "jpg",
  "file": "42_800x600",
  "width": 800,
  "height": 600,
  "dimensions": "800x600",
  "size": "45 KB",
  "generator": { ... }
}
```

---

## Authentication

Most endpoints are **open** (no auth required). Two endpoints require a Bearer token:

- `GET /keys` — list all API keys
- `GET /key/{private_key}/cached` — get cached key data

```
Authorization: Bearer <token>
```

---

## Error shape

All errors return the same envelope:
```json
{
  "success": false,
  "data": {
    "type": "exception",
    "error": null,
    "code": 4042,
    "message": "Private key not found: a1b2c3d4e5f6g7h8i9j0k1l2m",
    "debug_level": "none"
  }
}
```

`data.code` is the application error code; the HTTP status is derived from it (`code` in
100-599 is used as-is, `code` in 1000-9999 uses `floor(code / 10)` — e.g. `4043` → HTTP 404).
`GET /error-codes` returns this same table live from `error_codes.conf` — call it if this list
ever looks out of date.

| Code | HTTP | Meaning |
|---|---|---|
| `400` | 400 | Invalid data |
| `4001` | 400 | Incorrect upload data |
| `4002` | 400 | File size greater than accepted (total POST body over `post_max_size`) |
| `4003` | 400 | File too big for this upload (single file over the configured upload limit) |
| `4004` | 400 | Image must be requested by UUID (`force_uuid` enabled, numeric id sent) |
| `4005` | 400 | Private key is required |
| `4006` | 400 | Invalid URL for upload |
| `4007` | 400 | Private key and image id are required |
| `4008` | 400 | Folder cannot be empty |
| `401` | 401 | Unauthorized |
| `4011` | 401 | Wrong private secret (key creation) |
| `403` | 403 | Forbidden |
| `4031` | 403 | Private secret corrupted |
| `4032` | 403 | Key does not match image |
| `4033` | 403 | Usage limit reached |
| `4034` | 403 | Key expired |
| `4035` | 403 | Key not active |
| `404` | 404 | Not found |
| `4041` | 404 | Public key not found |
| `4042` | 404 | Private key not found |
| `4043` | 404 | Image not found |
| `4044` | 404 | Api key not found |
| `415` | 415 | Unsupported media type |
| `4151` | 415 | Invalid image extension |
| `4152` | 415 | Invalid image type |
| `4153` | 415 | Could not read image dimensions; file may be corrupt |
| `500` | 500 | Server error |
| `5001` | 500 | Error on internal API request |
| `5002` | 500 | Incorrect configuration |
| `5003` | 500 | Permissions failed on server |
| `5004` | 500 | Image generation failed |
| `5005` | 500 | Could not open file |

---

## Quick reference — which key goes where

| Endpoint | Key type required |
|---|---|
| `POST /key/create` | Server `secret` (not a key pair) |
| `POST /upload` | `private_key` in body as `key` |
| `POST /upload-url` | `private_key` in body as `private_key` |
| `POST /key/{k}/upload` | `private_key` in URL path |
| `POST /key/{k}/upload-url` | `private_key` in URL path |
| `DELETE /key/{k}/delete/{id}` | `private_key` in URL path (numeric `id` only) |
| `GET /key/{k}/images` | `private_key` in URL path |
| `GET /image/{k}/{id}/...` | `public_key` in URL path (`id` = numeric id or uuid) |

---

## Subfolders

Subfolders are optional organizational buckets within a key's folder. They can be set at upload time (`subfolder` POST field) and filtered when listing images (`?subfolder=name`). They do not affect image retrieval URLs — image URLs only use `public_key` and `id`/`uuid`.

---

## Do's and don'ts

**Do:**
- Call `GET /settings` once at startup to check `force_uuid` and the upload limit.
- Store both `private_key` and `public_key` after creating a key — neither can be recovered from the other.
- Use `/image/{public_key}/{id}/x/{WxH}` (path-based size) in `<img src>` tags — it is the most explicit and cache-friendly form.
- Use `?placeholder=1` to get a blurred low-quality version for progressive image loading.
- Check `success` before reading `image` in file upload responses.
- Use the image's `id` for delete calls — the `uuid` won't work there.
- Call `GET /validate` after standing up a new environment to catch misconfigured upload limits, folder permissions, or DB connectivity early.

**Don't:**
- Don't use `private_key` in client-side HTML or public URLs — it allows anyone to upload or delete images.
- Don't call `?generate=1` on every request — it bypasses the cache and is expensive.
- Don't assume image endpoints work without a `public_key` — as of v3.5.0 it is always required.
- Don't try to resize SVGs — the API will always return the raw SVG regardless of which endpoint is called.
- Don't forget that pagination is zero-based (`page=0` is the first page).
- Don't pass a `uuid` to the delete endpoint — it only accepts the numeric `id`.
