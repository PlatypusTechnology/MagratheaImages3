# Plan: Clone an image to another key

## Context

New endpoint: given a private key (the actor cloning), a public key + image UUID
(the source image), copy that image — DB row and raw file — into the private key's
folder, so it becomes a normal, independently-owned image under that key.

Guard rail from the request: **the public key must not be the sibling of the private
key**, i.e. they must not belong to the same `apikey` row. Since `apikey` stores
`private_key` and `public_key` as two columns on one row (§`ApikeyBase.php:13`), "same
pair" is a single equality check once both sides are resolved to their row `id`s — no
new schema needed.

## Route & method

Confirmed: `POST`, keeping your exact path shape as the route pattern:

```php
$this->Add("POST", "clone-image/to/:private_key/from/:public_key/:image_uuid", $api, "Clone", self::OPEN);
```

`self::OPEN` to match every other key-scoped route in this API (see `plan-delete-image.md`'s
trust model section — possession of the key in the URL is the credential throughout this
codebase; no reason to special-case this one).

Placed as its own top-level route (not nested under `image/:public_key/:id/...` or
`key/:private_key/...`) since it names both a source and a destination key and doesn't
belong to either's route group.

## Changes

### 1. `src/api/api.php` — new route

Add inside `AddImages()`, alongside the other image-mutating routes:

```php
$this->Add("POST", "clone-image/to/:private_key/from/:public_key/:image_uuid", $api, "Clone", self::OPEN);
```

### 2. `src/api/features/Images/ImagesApi.php` — new `Clone($params)` method

Mirrors the existing `GetById()` / `Upload()` shape: resolve inputs, delegate the real
work to `ImagesControl`, catch/rethrow.

```php
public function Clone($params) {
    try {
        $privateKey = @$params["private_key"];
        $publicKey  = @$params["public_key"];
        $uuid       = @$params["image_uuid"];
        if(!$privateKey) ErrorCodes::Instance()->ThrowException(4005);
        if(!$publicKey)  ErrorCodes::Instance()->ThrowException(400, null, "public key is missing");
        if(!$uuid)       ErrorCodes::Instance()->ThrowException(400, null, "image uuid is missing");

        $post = $this->GetPost();
        $subfolder = @$post["subfolder"];

        return $this->service->CloneImage($privateKey, $publicKey, $uuid, $subfolder);
    } catch(MagratheaApiException $e) {
        throw $e;
    } catch(\Exception $e) {
        ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
    }
}
```

`subfolder` is an optional POST field, same convention `Upload()` already uses
(`@$post["subfolder"]`). Not present on this route's `:params` since it's not part of
the URL pattern — read from the body like every other optional upload param, which also
covers the `upload-url`-style callers you mentioned that wouldn't have a natural place
to put it in the path.

### 3. `src/api/features/Images/ImagesControl.php` — new `CloneImage()` (the real logic)

```php
const DEFAULT_CLONE_SUBFOLDER = "cloned";

public function CloneImage(string $privateKey, string $publicKey, string $uuid, ?string $subfolder = null): array {
    $apiControl = new ApikeyControl();

    // 1. Resolve + validate destination key (the actor). Same checks Upload() applies.
    $destKey = $apiControl->GetByKey($privateKey); // private=true default
    if(empty($destKey->id)) {
        ErrorCodes::Instance()->ThrowException(4042, null, $privateKey);
    }
    $validation = $destKey->ValidateKey();
    if(!$validation["ok"]) {
        ErrorCodes::Instance()->ThrowException($validation["code"] ?? 403, null, $validation["data"]);
    }

    // 2. Resolve source image by UUID, scoped to the given public key.
    $image = $this->GetByUuid($uuid);
    if(empty($image) || empty($image->name)) {
        ErrorCodes::Instance()->ThrowException(4043, ["uuid" => $uuid]);
    }
    $sourceKey = $apiControl->GetByKey($publicKey, false); // private=false: look up by public_key column
    if(empty($sourceKey->id)) {
        ErrorCodes::Instance()->ThrowException(4041, $publicKey);
    }
    if($image->upload_key != $sourceKey->id) {
        ErrorCodes::Instance()->ThrowException(4032, ["uuid" => $uuid]);
    }

    // 3. The guard rail: public_key and private_key must not be the same apikey row.
    if($sourceKey->id == $destKey->id) {
        ErrorCodes::Instance()->ThrowException(4036, null, "source and destination belong to the same key pair");
    }

    // 4. Copy the raw file on disk.
    $sourcePath = $image->GetRawFile();
    if(!file_exists($sourcePath)) {
        ErrorCodes::Instance()->ThrowException(5005, null, $sourcePath);
    }
    $destFolder = PathManager::GetRawFolder($destKey->folder);
    $folderOk = PathManager::CheckDestinationFolder($destFolder);
    if(!$folderOk["success"]) {
        ErrorCodes::Instance()->ThrowException(5003, $folderOk["path"], $folderOk["error"]);
    }

    // 5. Build the new Images row — same shape ImageUploader::CreateImage() builds,
    //    minus a re-upload: reuses SetFilename() so the new row gets its own
    //    "{new_id}_name.ext" filename and its own auto-generated uuid on Insert().
    $clone = new Images();
    $clone->folder     = $destKey->folder;
    $clone->upload_key = $destKey->id;
    $clone->subfolder  = $subfolder ?: self::DEFAULT_CLONE_SUBFOLDER;
    $clone->width      = $image->width;
    $clone->height     = $image->height;
    $clone->file_type  = $image->file_type;
    $clone->size       = $image->size;
    $clone->extension  = $image->extension;
    $clone->name       = $image->name;
    $originalBasename  = $image->extension ? $image->name.".".$image->extension : $image->name;
    $clone->SetFilename($originalBasename);

    $destPath = $destFolder.$clone->filename;
    if(!copy($sourcePath, $destPath)) {
        ErrorCodes::Instance()->ThrowException(5006, null, "could not copy [".$sourcePath."] to [".$destPath."]");
    }

    $clone->Insert();
    $destKey->IncrementUses();

    return [
        "image" => $clone,
        "public_key" => $destKey->public_key,
    ];
}
```

Notes on why it's shaped this way:
- Reuses `ApikeyControl::GetByKey($key, $private)` for **both** directions — it already
  takes a `$private` flag to switch which column it filters on
  (`ApikeyControl.php:55`), so no new lookup method needed.
- Reuses `Apikey::ValidateKey()` for the destination key (usage limit / expiration /
  active) — same three checks `ImagesApi::GetApiKeyByValue()` already applies before an
  upload. **Deliberately not applied to the source key** — cloning only *reads* from the
  source, it doesn't consume its quota, so an expired/inactive source key shouldn't
  block a clone of an image that's still sitting on disk.
- `$clone->SetFilename()` calls `GetNextID()` (`MagratheaModel.php:224`, `SHOW TABLE
  STATUS`) to build the new row's filename prefix before `Insert()` — the exact same
  pattern `Images::FromUploadFile()`/`FromUrl()` already use. Same pre-existing
  TOCTOU race (two concurrent inserts could in theory read the same next-id) as every
  other upload path in this codebase today — not a new risk introduced here, not fixing
  it here either.
- `$destKey->IncrementUses()` — cloning into a key counts as a use of that key, same as
  uploading into it. Confirmed: clones count against `usage_limit` like any upload.
- **Known gap, accepted as-is**: no rollback if `copy()` succeeds but `Insert()` then
  throws — leaves an orphaned raw file with no `images` row. Same order-of-operations
  gap already exists in `ImageUploader::Upload()` (`move_uploaded_file()` then
  `Insert()`), so this isn't a new risk. Confirmed: leave it consistent with existing
  behavior; not adding a `unlink()`-on-failure rollback here. Revisit both together
  later if it ever matters in practice.

### 4. What's deliberately **not** copied

Only the raw file is duplicated. Generated/resized variants
(`PathManager::GetGeneratedFolder()`) are **not** copied — they regenerate lazily on
first view under the clone's own id/uuid, exactly like a freshly uploaded image. Copying
them would mean re-deriving every cached size's filename under the new id and doubling
disk usage for files that may never be requested again for the clone.

### 5. `src/api/error-manager/error_codes.conf` — one new code

```
4036 = "Cannot clone image: source and destination belong to the same key pair"
5006 = "Could not copy image file"
```

`4036` slots after the existing `403` family (`4032`–`4035`, all "key vs image"
mismatches) since this is the same shape of error — an authorization mismatch, not a
missing-resource or server error. `5006` slots after `5005` ("Could not open file") —
the read-then-write pair for a filesystem copy failure.

Everything else reuses existing codes: `4005` (private key required), `400` ad-hoc
(missing public key / uuid — same pattern `GetById()` already uses for a missing public
key), `4041` (public key not found), `4042` (private key not found), `4043` (image not
found), `4032` (key does not match image), `4033`/`4034`/`4035` (destination key usage
limit / expired / inactive, via `ValidateKey()`), `5003` (folder permissions), `5005`
(source raw file missing on disk — reusing "Could not open file" rather than adding a
near-duplicate code).

### 6. Response shape

Matches `UploadSuccessResponse` (the same wrapper every upload endpoint already
returns) rather than inventing a new one:

```json
{
  "success": true,
  "data": {
    "image": { "...cloned Images row, own id/uuid/filename..." },
    "public_key": "<destination key's public_key>"
  }
}
```

### 7. Tests

- Happy path: two distinct keys, source has an image → clone succeeds, new `images` row
  exists with a different `id`/`uuid`/`filename`, raw file exists at the new path with
  identical bytes to the source, destination key's `uses` incremented by 1.
- Same-pair rejection: pass a `public_key` that pairs with the given `private_key` →
  `4036`, no DB row or file created.
- Unknown `private_key` → `4042`. Unknown `public_key` → `4041`. Unknown/garbage
  `image_uuid` → `4043`.
- `public_key` valid but the image belongs to a *different* key than that public key
  (data integrity edge case — shouldn't happen via normal API use, but the check must
  still hold) → `4032`.
- Destination key expired / inactive / at its usage limit → `4034`/`4035`/`4033`,
  nothing written.
- Source's raw file missing from disk (DB row exists, file was manually deleted) →
  `5005`, no DB row created for the clone (fail before `Insert()`).
- Cloning an SVG (no resize support) — raw copy only, no resize path involved, should
  need no special-casing; worth one test to confirm nothing in the copy path assumes
  `getimagesize()` succeeds (it doesn't — width/height are copied from the source row,
  never re-derived from the file).

### 8. `swagger.yaml`

New `POST /clone-image/to/{private_key}/from/{public_key}/{image_uuid}` entry, response
= `UploadSuccessResponse` (reuse the existing schema), documenting all the error codes
above.

### 9. `skills.md`

Per the existing sync process (verify against source/routes, not just `changelog.md`) —
add a "Workflow: Clone an image to another key" section once this lands, cross-checked
against the actual route registration in `api.php`, not this plan doc.

### 10. Versioning

Per `claude.md`: bump `src/version`, add a `### new` entry to `src/changelog.md`, sync
`src/swagger.yaml`'s top-level `version:` — all three together, same commit as the code.

## Decisions — all confirmed

1. **HTTP method** — `POST`.
2. **`uses` counter** — increments on clone, same as upload; clones count against
   `usage_limit`.
3. **Subfolder** — source `subfolder` is **not** carried over. An optional `subfolder`
   POST field (same convention as `Upload()`) overrides it; if omitted, the clone lands
   in a hardcoded default subfolder, `"cloned"`.
4. **Error code numbers** — `4036` and `5006` confirmed free to use.
5. **Orphaned file on `Insert()` failure after `copy()`** — left as-is, consistent with
   the same pre-existing gap in `ImageUploader::Upload()`. Not fixed here; revisit both
   together later if needed.
6. **Cross-instance storage** — confirmed all keys currently share the same server, so
   local `copy()` between the two raw folders is safe as planned. No HTTP-fetch fallback
   needed.

## What this does NOT change

- No new database columns or migrations — reuses the existing `images` and `apikey`
  tables as-is.
- No changes to `ImageUploader`, `ImageViewer`, `PathManager`, or any existing route —
  this is new, additive logic that reuses their pieces (`PathManager::GetRawFolder()`,
  `Apikey::ValidateKey()`, `ApikeyControl::GetByKey()`) without modifying them.
- Generated/resized variants of the source image are never touched or copied.
