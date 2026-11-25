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

`/settings` returns (in `data`, see [Response envelope](#response-envelope)):
```json
{
  "thumb_size": "300",
  "upload_limit_bytes": 10485760,
  "upload_limit": "10.00 MB",
  "force_uuid": false
}
```

`thumb_size` is a string (or `null` when the server never set it; the thumbnail is then 100px).
`force_uuid: true` means the `:id` segment on every image endpoint **must** be a UUID —
sending a numeric id returns HTTP 400 (error code `4004`, see [Error shape](#error-shape)).

For deeper diagnostics, three more system endpoints exist:

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

## Response envelope

Every JSON response is wrapped:
```json
{ "success": true, "data": { ... } }
```
Errors use `"success": false` with the error in `data` (see [Error shape](#error-shape)). The
examples in this guide show only `data`, except where the envelope matters (uploads).

---

## Key concepts

### Two types of keys

| Key | Default length | Purpose |
|---|---|---|
| `private_key` | 25 chars (configurable via `private_key_size`) | Upload, delete, list images with secret info |
| `public_key` | 12 chars (configurable via `public_key_size`) | Read / display images |

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

File uploads: `jpg`, `jpeg`, `png`, `bmp`, `webp`, `wbmp`, `svg` (checked by extension).
URL uploads are checked by content type instead: JPEG, PNG, WebP, BMP, GIF, SVG.

SVGs cannot be resized — they are always served raw regardless of which size endpoint is called.

### Size format

Whenever a size is needed in a path or query string, use `WxH` notation with integers:
`800x600`, `1920x1080`, `400x400`. **Both W and H are required** — see
[How the size is resolved](#how-the-size-is-resolved).

---

## Workflow 1 — Provision a new key

Call `POST /key/create` with the server `secret` (a shared secret configured on the server, not a key pair) and a `folder` name. The folder name should be a simple slug (no spaces, no slashes). Send them **form-encoded**: this endpoint doesn't read JSON bodies.

```
POST /key/create
Content-Type: application/x-www-form-urlencoded

secret=<server-secret>&folder=myblog
```

Optional fields: `usage_limit` (`0` = unlimited) and `expiration` (datetime).

Response:
```json
{
  "apikey": {
    "created_at": null,
    "updated_at": null,
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

`created_at`/`updated_at` are `null` here (the API doesn't read the new row back);
`GET /key/{private_key}/view` returns the stored values.

Save both keys. You will need `private_key` to upload/delete, and `public_key` to construct image URLs.

Errors: `4011` wrong secret, `4008` no folder, `5002` the server has no secret configured.

---

## Workflow 2 — Upload an image

There are four upload endpoints. Choose based on what you have. Uploads are capped by
`upload_limit_bytes` from `/settings` (server config `max_upload_size`, bounded by PHP's
`post_max_size`/`upload_max_filesize`). Each upload counts as a use of the key (`uses`,
limited by `usage_limit`).

### Upload a file — key in body

```
POST /upload
Content-Type: multipart/form-data

file=<binary>
private_key=<private_key>
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

All upload endpoints — file and URL — return the same `UploadSuccessResponse` (full body):
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
      "created_at": null,
      "updated_at": null
    },
    "public_key": "abc123def456"
  }
}
```

`created_at`/`updated_at` are `null` in upload, upload-url and clone-from responses (since 3.7.0, the API doesn't read the new row back). Fetch `GET /image/:public_key/:id/details` if you need the stored timestamps.

### Upload failure response

Every upload failure is a real HTTP error (4xx/5xx) with the usual [Error shape](#error-shape):
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
The usual ones: `4151` extension not allowed, `4153` unreadable image, `4152` unsupported
content type (URL uploads), `4001`/`4002`/`4003` no file or too big, `4033`/`4034`/`4035` key
over its limit/expired/inactive, `5003`/`5007` the file couldn't be stored.

Before 3.7.0, a **file** upload that failed after the key was accepted (`4151`, `4153`,
`5003`) answered HTTP 200 with `success: true` and the failure nested in `data`
(`{"success": false, "error": "…"}`). A client written against that has to read the error path
instead.

---

## Workflow 3 — Display an image

Every image-viewing endpoint takes the `public_key` and the image `id` (numeric or UUID, see
[Image identifiers](#image-identifiers--id-vs-uuid)) in the path.

| Goal | URL pattern |
|---|---|
| Thumbnail (a `thumb_size` square) | `GET /image/{public_key}/{id}/thumb` |
| Exactly W×H, cropped to fill (default) | `GET /image/{public_key}/{id}/x/{W}x{H}` |
| Inside a W×H box, keeping the image's ratio | `GET /image/{public_key}/{id}/x/{W}x{H}?fit=1` |
| Size from the query string | `GET /image/{public_key}/{id}?size=800x600` or `?w=800&h=600` |
| Original file | `GET /image/{public_key}/{id}/raw` (or `/x/raw`) |
| Metadata only (JSON) | `GET /image/{public_key}/{id}/details` |

### How the size is resolved

The server takes the first of these that is present:
1. Path segment `/x/{WxH}` — most explicit, use this when you know the size. `/x/raw` serves
   the original file.
2. Query param `?size=800x600`. When `size` is present, `w`/`h` are ignored.
3. Query params `?w=800&h=600` (aliases: `width`, `height`).

**Both dimensions are required.** With one missing (`/x/800`, `/x/800x`, `?w=800`,
`?size=800`) or none at all, the response is the **thumbnail**: a `thumb_size` square crop.
It is not a fit, and it is not 800 wide. A non-numeric dimension (`/x/800x600-s`) answers 500.

### Resize modes

| Request | Output | Shape |
|---|---|---|
| `/x/{W}x{H}` (default) | exactly W×H | **cropped to fill**: scaled to cover the box, then an image wider than the box loses both sides evenly, and one taller than the box keeps its top |
| `/x/{W}x{H}?fit=1` | **at most** W×H | scaled to fit inside the box, keeping its ratio: no crop, no padding, never upscaled |
| `/x/{W}x{H}?stretch=1` | exactly W×H | the whole image, **distorted** to W×H when the ratios differ |

`fit=1` computes `s = min(W/w, H/h, 1)` from the original `w×h`, and returns
`round(w·s) × round(h·s)`. An original smaller than the box comes back at its own size. When
both `fit=1` and `stretch=1` are sent, `fit` wins.

### Image modifier query parameters

| Param | Values | Effect | Applies to |
|---|---|---|---|
| `fit` | `0` (default), `1` | Fit inside W×H keeping the ratio (see above). Overrides `stretch` | `/x/{WxH}`, `?size=`/`?w=&h=`, `/preview/{WxH}` |
| `stretch` | `0` (default), `1` | Distort to exactly W×H. Broken before 3.7.0 (it returned the default crop) | same as `fit` |
| `placeholder` | `0` (default), `1` | A low-quality version (LQIP): the output size scaled by the server's `placeholder_size` (default 0.5), encoded at low quality. With `fit=1`, it scales the fitted size | every resized route, `/thumb` included |
| `generate` | `0` (default), `1` | Regenerate and overwrite the cached file, skipping the cache lookup | every route that caches (not `/preview`) |

`fit` and `stretch` do nothing on `/thumb` and `/raw`; nothing applies to `/raw`.

### Response format

`image/webp` when the server has `webp_quick_access` on, otherwise the original's format. SVGs
are always the original file (`image/svg+xml`), whatever size or modifier is sent. `/raw` is
always the original file.

### Caching behavior

Generated/resized images are saved to disk on first request; later requests for the same URL are
served from that file. Each combination is its own file: `{id}_800x600`, `{id}_800x600-f` (fit),
`{id}_800x600-s` (stretch), each also with `_placeholder`. Every distinct W×H you request is a
new file generated and kept on disk, so use a small fixed set of sizes. Use `?generate=1` only to
replace a stale cached file.

### Recipe — show each image in its own ratio, inside a max box

```
https://<server>/image/{public_key}/{uuid}/x/{maxW}x{maxH}?fit=1
https://<server>/image/{public_key}/{uuid}/x/{maxW}x{maxH}?fit=1&placeholder=1   (LQIP)
```
- The response is **at most** maxW×maxH and never upscaled: don't assume it fills the box.
- To reserve layout space before it loads, read `width`/`height` from
  `GET /image/{public_key}/{uuid}/details` and apply the same formula client-side:
  `s = min(maxW/w, maxH/h, 1)` → `round(w·s) × round(h·s)`.
- Use a small fixed set of boxes (e.g. `400x400`, `800x800`, `1600x1600`), not container-exact
  pixel sizes.
- `x/800x?fit=1` (one dimension) returns the square thumbnail, not a fit.

---

## Workflow 4 — List images for a key

```
GET /key/{private_key}/images
```

Optional query params:
- `page` — zero-based page index (default `0`)
- `count` — images per page (default `12`)
- `subfolder` — filter to a specific subfolder

Images come newest-first. `has_more` is `true` when the page came back full: increment `page`
to fetch the next batch (which can be empty when the total is an exact multiple of `count`).

```json
{
  "private_key": "a1b2c3d4e5f6g7h8i9j0k1l2m",
  "public_key": "abc123def456",
  "page": 0,
  "images": [ /* Image objects */ ],
  "has_more": true,
  "timestamp": "2025-06-01 12:00:00"
}
```

---

## Workflow 5 — Delete an image

Deletion requires the **private key** and the image `id`. Unlike the viewing endpoints,
**only the numeric `id` is accepted here — not the `uuid`** (a uuid answers HTTP 500, code `5001`).

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
      "del_generated": {
        "id": {
          "pattern": "42_*",
          "deleted": { "/var/www/medias/myblog/generated/42_thumb.webp": true }
        },
        "uuid": {
          "pattern": "0193f2a4-1b2c-7def-8a9b-1234567890ab_*",
          "deleted": []
        }
      }
    }
  }
}
```

`del_image` — the database record was removed.
`del_file.deleted.del_file` — the original uploaded file was removed from disk.
`del_file.deleted.del_generated` — the resized/generated variants removed from disk, named by
id and by uuid: each `deleted` maps a file to whether it was removed (`[]` when there were none).

Errors: `4007` key or id missing, `4032` the image doesn't belong to that key.

---

## Workflow 6 — Preview without caching (testing / admin)

Use `/preview/{size}` when you want to test resize output without touching the cache: it's
always generated and never saved.

```
GET /image/{public_key}/{id}/preview/800x600
GET /image/{public_key}/{id}/preview/800x600?fit=1
GET /image/{public_key}/{id}/preview/thumb
```

`{size}` is `WxH` (both required), `thumb` or `raw`. `fit`, `stretch` and `placeholder` work
as on `/x/{WxH}`; `generate` doesn't apply. The image is always in the original's format
(never converted to webp).

Add `?debug=1` to get the resizer's steps as JSON instead of the binary: a list of strings,
for humans (don't parse their wording):
```json
[
  "Setting new file: /var/www/medias/myblog/generated/42_800x800-f",
  "Original dimensions: 1600x900",
  "Setting dimensions: 800x800",
  "fit: box 800x800 -> 800x450",
  "...",
  "Creating blank image... of size (800x450)",
  "OK!"
]
```

---

## Workflow 7 — Clone an image to another key

Copies an image owned by one key into the folder of another key, as a new,
independently-owned image (own `id`/`uuid`/`filename`). Only the raw file is
duplicated — generated/resized variants regenerate lazily under the clone's own id,
exactly like a freshly uploaded image.

```
POST /key/{private_key}/clone-from/{public_key}/{image_uuid}
```

- `private_key` — the destination key doing the cloning (the actor).
- `public_key` — the source key that owns the image being cloned.
- `image_uuid` — UUID of the source image (the numeric id isn't accepted here).
- `subfolder` (optional POST field) — subfolder for the clone. The source image's own
  subfolder is **not** carried over; if omitted, the clone lands in a default `"cloned"`
  subfolder.

**Guard rail:** `public_key` and `private_key` must not belong to the same API key pair
(same `apikey` row) — that request fails with `4036`.

Cloning counts as a use of the destination key, same as an upload (`uses` increments,
subject to `usage_limit`).

Response is the same `UploadSuccessResponse` wrapper as an upload:
```json
{
  "success": true,
  "data": {
    "image": { "...cloned image, own id/uuid/filename..." },
    "public_key": "<destination key's public_key>"
  }
}
```

Errors: `4042` unknown destination key, `4033`/`4034`/`4035` destination key over its limit,
expired or inactive, `4043` unknown image, `4041` unknown source key, `4032` the image doesn't
belong to the source key, `4036` same key pair, `5005`/`5006` the raw file couldn't be read or
copied.

---

## Workflow 8 — Delete an entire key and all its images

Deletes the API key itself and everything under it in one call — every image row and
file (raw + generated/resized variants), the key's whole media folder, and the
`apikey` row. Replaces looping over Workflow 5 per image.

```
DELETE /key/{private_key}?public_key={public_key}
```

- `private_key` — in the URL path, same as every other key-scoped route.
- `public_key` — **as a query parameter**, not a path segment. This is the one
  endpoint that breaks the path-segment convention every other credential in this
  API follows — deliberately, so it can't be copied by pattern-matching a sibling
  route. It isn't a second access-control gate (whoever holds `private_key` already
  has full capability); it's a "prove you meant to target this key" check.

Response:
```json
{
  "apikey": true,
  "images_deleted": 12,
  "folder": "/var/www/medias/myfolder/",
  "folder_deleted": true,
  "warnings": []
}
```

`warnings` can be non-empty on an otherwise-200 response — a partial file-level
failure (e.g. a raw file already missing on disk) does not fail the request, since
the database rows are gone either way. Log `warnings` even on success if complete
disk cleanup matters to you.

**Idempotent:** calling this twice, or on an already-deleted/unknown `private_key`,
returns `4042` cleanly — safe to treat as "already gone, nothing to do." A missing or
incorrect `public_key` for an otherwise-valid `private_key` also returns `4042`,
indistinguishable from "not found" on purpose.

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

All errors return the same envelope, with the HTTP status derived from the code:
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
`data.error` carries extra context for some errors (often `null`).
`GET /error-codes` returns this same table live from `error_codes.conf` — call it if this list
ever looks out of date.

One exception to "errors are real HTTP errors": an **unknown route** answers HTTP 200 with
`success: false`. An unexpected server error (a PHP error) answers a JSON 500 with
`"Internal Server Error"`.

| Code | HTTP | Meaning |
|---|---|---|
| `400` | 400 | Invalid data |
| `4001` | 400 | Incorrect upload data |
| `4002` | 400 | File size greater than accepted (a file or URL download over the upload limit: `upload_limit_bytes` in `/settings`) |
| `4003` | 400 | File too big for this upload (the whole request over the upload limit, so PHP dropped the file) |
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
| `4036` | 403 | Cannot clone image: source and destination belong to the same key pair |
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
| `5006` | 500 | Could not copy image file |
| `5007` | 500 | Could not save image file |

---

## Quick reference — which key goes where

| Endpoint | Key type required |
|---|---|
| `POST /key/create` | Server `secret` (not a key pair) |
| `POST /upload` | `private_key` in body as `private_key` |
| `POST /upload-url` | `private_key` in body as `private_key` |
| `POST /key/{k}/upload` | `private_key` in URL path |
| `POST /key/{k}/upload-url` | `private_key` in URL path |
| `DELETE /key/{k}/delete/{id}` | `private_key` in URL path (numeric `id` only) |
| `DELETE /key/{k}` | `private_key` in URL path + `public_key` as a query param (not path) |
| `POST /key/{k}/clone-from/{k2}/{uuid}` | `private_key` (destination) in URL path + `public_key` (source) in URL path |
| `GET /key/{k}/view` | `private_key` in URL path |
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
- Use `?fit=1` when the image must keep its own ratio inside a max width × max height.
- Use a small, fixed set of sizes — every distinct `WxH` is a separate file on the server.
- Use `?placeholder=1` to get a low-quality version for progressive image loading.
- Handle upload failures in your HTTP client's error path: they are real 4xx/5xx responses (Workflow 2).
- Use the image's `id` for delete calls — the `uuid` won't work there.
- Call `GET /validate` after standing up a new environment to catch misconfigured upload limits, folder permissions, or DB connectivity early.

**Don't:**
- Don't use `private_key` in client-side HTML or public URLs — it allows anyone to upload or delete images.
- Don't call `?generate=1` on every request — it bypasses the cache and is expensive.
- Don't assume image endpoints work without a `public_key` — as of v3.5.0 it is always required.
- Don't send only one dimension and expect a proportional resize — `/x/800` returns the square thumbnail.
- Don't assume a `fit=1` response fills the box — it is *at most* W×H, and never upscaled.
- Don't try to resize SVGs — the API will always return the raw SVG regardless of which endpoint is called.
- Don't forget that pagination is zero-based (`page=0` is the first page).
- Don't pass a `uuid` to the delete endpoint — it only accepts the numeric `id`.
