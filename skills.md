# MagratheaImages API — Skills Guide for AI Agents

This document teaches AI agents how to correctly interact with the MagratheaImages 3 API.
The full machine-readable contract is in `swagger.yaml` at the project root.

---

## Mental model

MagratheaImages is an **image hosting and on-demand resizing** service. The core concepts are:

- **Apikey** — a credential pair (`private_key` / `public_key`) tied to a folder on disk.
  The private key is used to **write** (upload, delete).
  The public key is used to **read** (view images).
- **Image** — a record of an uploaded file, stored under the key's folder.
- **Secure mode vs Public mode** — a server-side flag (`secure_api`) changes the shape of image URLs. Always call `GET /settings` or `GET /version` first if you are unsure which mode is active.

---

## Step 0 — discover the server

Before anything else, confirm the server is reachable and learn its mode:

```
GET /version
GET /settings
```

`/settings` returns:
```json
{
  "thumb_size": 300,
  "secure": false,
  "upload_limit_bytes": 10485760,
  "upload_limit": "10 MB"
}
```

`secure: true` means every image URL must include the public key in the path.
`secure: false` means image URLs use the image ID only.

---

## Key concepts

### Two types of keys

| Key | Length | Purpose |
|---|---|---|
| `private_key` | 25 chars | Upload, delete, list images with secret info |
| `public_key` | 12 chars | Read / display images, list images publicly |

Never expose the `private_key` in client-side code or public URLs.
The `public_key` is safe to embed in HTML `<img src>` attributes.

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

There are four upload endpoints. Choose based on what you have:

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

File upload (`/upload`, `/key/:key/upload`) returns:
```json
{
  "success": true,
  "image": {
    "id": 42,
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
  }
}
```

URL upload (`/upload-url`, `/key/:key/upload-url`) returns the `Image` object directly (no `success` wrapper).

**Always check `success: true`** before using the `image` field on file uploads.

### Upload failure response

```json
{
  "success": false,
  "error": "invalid image extension: [exe]",
  "data": null
}
```

---

## Workflow 3 — Display an image

First determine whether the server is in public or secure mode (see Step 0).

### Public mode (`secure: false`)

Use the image `id` directly in the URL.

| Goal | URL pattern |
|---|---|
| Thumbnail | `GET /image/{id}/thumb` |
| Specific size | `GET /image/{id}/x/{WxH}` |
| Auto-size (query params) | `GET /image/{id}?w=800&h=600` |
| Auto-size (query param) | `GET /image/{id}?size=800x600` |
| Original file | `GET /image/{id}/raw` |
| Metadata only | `GET /image/{id}/details` |

### Secure mode (`secure: true`)

Use the `public_key` + image `id` together.

| Goal | URL pattern |
|---|---|
| Thumbnail | `GET /image/{public_key}/{id}/thumb` |
| Specific size | `GET /image/{public_key}/{id}/x/{WxH}` |
| Auto-size | `GET /image/{public_key}/{id}?w=800&h=600` |
| Original file | `GET /image/{public_key}/{id}/raw` |
| Metadata only | `GET /image/{public_key}/{id}/details` |

### Size resolution order for `/image/{id}` (public) and `/image/{key}/{id}` (secure)

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
GET /key/{public_key}/images
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

Deletion requires the **private key** and the image `id`.

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
GET /image/{id}/preview/800x600
GET /image/{id}/preview/thumb
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
- `GET /key/{public_key}/cached` — get cached key data

```
Authorization: Bearer <token>
```

---

## Error shape

All errors return JSON:
```json
{
  "error": "key [xyz] does not exists",
  "code": 404
}
```

Common errors:

| Situation | What to do |
|---|---|
| `key [] does not exists` | The key value sent is wrong or empty; double-check which key type (public vs private) is expected |
| `invalid image extension: [x]` | File type not supported; only jpg/jpeg/png/bmp/webp/wbmp/svg are accepted |
| `invalid secret for key creation` | The `secret` POST field does not match the server config |
| `File not received` | The multipart field must be named exactly `file` |
| `Key does not belong to image` | Tried to delete an image using a private key that did not upload it |
| `Apikey cache not generated` | Admin must regenerate the key cache from the server admin panel |

---

## Quick reference — which key goes where

| Endpoint | Key type required |
|---|---|
| `POST /key/create` | Server `secret` (not a key pair) |
| `POST /upload` | `private_key` in body as `key` |
| `POST /upload-url` | `private_key` in body as `private_key` |
| `POST /key/{k}/upload` | `private_key` in URL path |
| `POST /key/{k}/upload-url` | `private_key` in URL path |
| `DELETE /key/{k}/delete/{id}` | `private_key` in URL path |
| `GET /key/{k}/images` | `public_key` (or private — both work via `/key/:key/view`) |
| `GET /image/{k}/{id}/...` (secure mode) | `public_key` in URL path |

---

## Subfolders

Subfolders are optional organizational buckets within a key's folder. They can be set at upload time (`subfolder` POST field) and filtered when listing images (`?subfolder=name`). They do not affect image retrieval URLs — image URLs only use `id` (and optionally `public_key`).

---

## Do's and don'ts

**Do:**
- Call `GET /settings` once at startup to know whether you are in secure or public mode.
- Store both `private_key` and `public_key` after creating a key — neither can be recovered from the other.
- Use `/image/{id}/x/{WxH}` (path-based size) in `<img src>` tags — it is the most explicit and cache-friendly form.
- Use `?placeholder=1` to get a blurred low-quality version for progressive image loading.
- Check `success` before reading `image` in file upload responses.

**Don't:**
- Don't use `private_key` in client-side HTML or public URLs — it allows anyone to upload or delete images.
- Don't call `?generate=1` on every request — it bypasses the cache and is expensive.
- Don't assume secure mode or public mode — always detect it from `/settings`.
- Don't try to resize SVGs — the API will always return the raw SVG regardless of which endpoint is called.
- Don't forget that pagination is zero-based (`page=0` is the first page).
