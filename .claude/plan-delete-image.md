# Plan: Delete an apikey and all its images

## Context

guia.lol is building scheduled page deletion (`api/.claude/plan-delete-page.md` in the
`guia.lol` repo). Its cascade needs to fully remove a page's images from
`images.guia.lol` when a page's grace period elapses, but that plan flagged an open
question: MagratheaImages has no bulk "delete this key" endpoint, only per-image
`DELETE /key/{private_key}/delete/{id}`. Confirmed by you: MagratheaImages should own
this — deleting an apikey and everything under it end-to-end, in one call, is this
repo's job, not something guia.lol should reconstruct client-side by looping over
`GET /key/{private_key}/images`.

## What already exists — this is smaller than it looks

`ApikeyControl::DeleteKey(int $id)` (`src/api/features/Apikey/ApikeyControl.php:135`)
**already does the entire cascade**:

1. Loads the images (`$apikey->GetImages()`, the `Apikey` → `Images` relation keyed on
   `upload_key`).
2. Deletes each one through `ImagesControl::RemoveImage()` — DB row + raw file +
   every generated/resized variant (`RemoveRawFile()`), collecting per-image warnings
   instead of aborting on a single failure.
3. Recursively wipes the key's entire media folder (`RemoveDir()`) as a second pass —
   catches anything not tracked as an `Images` row (stray files, empty subfolders).
4. Deletes the `apikey` row itself.
5. Regenerates the apikey resolution cache (`CacheClassCreator`), so a stale cached
   folder/secret lookup can't resurrect the key.
6. Returns `{apikey, images_deleted, folder, folder_deleted, warnings}`.

The only place this is currently reachable from is the **server-rendered admin panel**:
`ApikeyAdmin::DeleteApikey()`, gated by an admin session
(`ApikeyAdmin::HasEditPermission()`) plus a human "type DELETE to confirm" challenge in
`admin/delete-confirm.php`. There is **no REST endpoint** a calling service (guia.lol's
`api`) can hit — that's the entire gap. This plan is about exposing existing,
already-correct logic, not writing new cascade logic.

## Trust model — deliberately reusing what already exists, not inventing new auth

Every image-mutating route on this API already uses the same rule: **possession of the
`private_key` in the URL is the credential.**

```
POST   /key/:private_key/upload         self::OPEN
POST   /key/:private_key/upload-url     self::OPEN
DELETE /key/:private_key/delete/:id     self::OPEN
```

Only `POST /key/create` (provisioning a *new* key) requires the separate shared
`secret` — because that's the one operation with no existing key to scope a check to.

Deleting the whole key is a bigger blast radius than deleting one image, so it's worth
naming the decision explicitly rather than silently copying the pattern: **I'm
recommending the same `private_key`-only, `self::OPEN` model for key deletion too**,
not adding a `secret` requirement. Reasoning — whoever already holds a page's
`private_key` (guia.lol's `api`, via `images_private_key` on the `pages` table) can
*already* delete every image under that key, one by one, with zero additional
credential. This endpoint doesn't grant new capability, it just makes the capability
that already exists atomic and complete (image rows + files + the key itself), and
avoids guia.lol's `api` having to also plumb the separate `images_secret` through its
deletion path for a check that wouldn't actually stop anything the private_key can't
already do. If you disagree and want deletion gated behind `secret` as well, say so —
it's a one-line change to the route registration and API method, not an architectural
one.

## Extra verification: `public_key` required as a query param

Confirmed by you: on top of the `private_key`-only model above, this endpoint also
requires the key's `public_key` — but deliberately **not** as another path segment.

`public_key` isn't a secret in this system — it's embedded in every public-facing
image URL (`image/:public_key/:id`, `.../raw`, `.../thumb`, `.../preview/:size`, all
`self::OPEN`), so requiring it here is not a real access-control gate; whoever holds
`private_key` already has full capability regardless. It's a "prove you meant to
target this key" check, not a security boundary — same spirit as `Clone()` already
requiring both keys together, just applied to a single key's own pair here.

The deliberate choice is the **shape**: every other identifier/credential in this API
is a path segment (`key/:private_key/...`, `image/:public_key/...`,
`key/:private_key/clone-from/:public_key/...`). This is the one endpoint where a
caller can't pattern-match the shape off its siblings and blindly copy it — reading
`public_key` from a query string instead is a deliberate outlier, forcing whoever
integrates this route to actually read the docs for the one call that deletes
everything under a key, rather than assume it. That's the entire justification: an
intentional speed bump, not a stylistic slip.

Consequence: unlike a path segment, a query param gets none of the router's free
"required" enforcement — nothing stops the request from reaching `Delete()` with it
missing. Validate it explicitly in code, the same way `NewKey()` manually checks
`@$_POST["secret"]`.

## Changes

### 1. New route (`src/api/api.php`, inside `AddApikey()`)

```php
$this->Add("DELETE", "key/:private_key", $api, "Delete", self::OPEN);
```

Placed on the key's own resource root (not `/key/:private_key/delete`) so it doesn't
collide with the existing per-image `key/:private_key/delete/:id` route and reads as
"delete this key" rather than "delete-action under this key."

### 2. `ApikeyApi::Delete($params)` — new method

```php
public function Delete($params) {
    $key = $this->_GetKey($params); // existing helper: 4042 if private_key not found
    $publicKey = @$_GET["public_key"];
    if(empty($publicKey) || $publicKey !== $key->public_key) {
        // Same code as "private key not found" — a caller holding only the
        // private_key gets no signal that it was otherwise valid.
        ErrorCodes::Instance()->ThrowException(4042, null, @$params["private_key"]);
    }
    try {
        return $this->service->DeleteKey($key->id);
    } catch(MagratheaApiException $e) {
        throw $e;
    } catch(Exception $e) {
        ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
    }
}
```

Same try/catch shape as the existing `Remove()` in `ImagesApi`. `_GetKey()` already
throws `4042` ("Private key not found") for a missing/garbage key, and the
`public_key` check above reuses the same code — no new error codes needed anywhere in
this plan; every case is already covered by the existing conf.

### 3. `ApikeyControl::DeleteKey()` — no changes needed

It already does exactly what this endpoint needs. Its own not-found guard (`4044`,
"Api key not found") becomes effectively unreachable from this new caller since
`_GetKey()` catches that case first by private_key — leave it as-is, it's still correct
defense-in-depth for the admin-panel caller, which looks the key up by numeric `id`
instead.

### 4. Idempotency (matters — this will be called from a sweep job, not a human click)

Calling this twice, or on an already-deleted key, must not throw a 500 or misbehave.
It already doesn't: the second call's `_GetKey()` lookup fails to find the private_key
(already deleted) and throws a clean `4042`. A caller (like guia.lol's sweep) can treat
`4042` on this endpoint as "already gone, nothing to do" and move on — worth spelling
this out explicitly in the swagger description so it isn't rediscovered by trial and
error later.

### 5. Tests — closing an existing gap, not new-feature-driven

`ApikeyControlTest.php` currently covers small helpers (`createRandomStr`,
`assertKeyNotInUse`, `RemoveDir` in isolation) but has **zero coverage of `DeleteKey()`
itself**, despite it being the single most destructive method in this codebase. Add:

- Seed an apikey + N `Images` rows (+ real fixture files under the test media root,
  same pattern `testRemoveDirDeletesFilesAndSubfolders` already uses) → call
  `DeleteKey()` → assert: zero `images` rows remain for that key, the `apikey` row is
  gone, the folder no longer exists on disk, `warnings` is empty for the clean case.
- A partial-failure case: one image's raw file already missing on disk → assert
  `DeleteKey()` still completes, that image shows up in `warnings`, and the DB image
  row is still gone (a missing file must not block the DB cleanup).
- API-layer: `DELETE /key/:private_key` with a private_key that doesn't exist → `4042`,
  not a 500. Calling it twice in a row on a real key → second call also `4042`, cleanly.
- API-layer: `DELETE /key/:private_key` on a real key with `public_key` missing from
  the query string → `4042`. Same, with `public_key` present but wrong (e.g. another
  key's public_key, or a garbage string) → `4042`. Both exercised by calling
  `ApikeyApi::Delete($params)` directly with `$_GET["public_key"]` set (or unset)
  beforehand, matching how `Clone()`'s existing tests call the API method directly
  rather than simulating a full HTTP request.

### 6. `swagger.yaml`

New `/key/{private_key}`: `delete:` entry, same section as the existing
`/key/{private_key}/delete/{id}` (around line 1072). Document:
- A required `public_key` query parameter, called out explicitly as **not** following
  the path-segment convention every other credential in this API uses — deliberate,
  so an integrator can't copy the shape from a sibling route without reading this.
- Response shape: `{apikey, images_deleted, folder, folder_deleted, warnings}`.
- **`warnings` can be non-empty on an otherwise-200 response** — a partial file-level
  failure doesn't fail the request, since the DB rows (source of truth for what's
  servable) are gone either way. Callers that care about complete disk cleanup should
  log `warnings` even on success — this is exactly what guia.lol's page-deletion audit
  log will do.
- `4042` for an unknown/already-deleted private_key, **or** a missing/incorrect
  `public_key` for an otherwise-valid private_key — both indistinguishable on purpose,
  safe to treat as "already gone / not authorized" the same way.

### 7. Versioning

Ships **on the same 3.6.2** already staged for the clone feature, not a new bump —
confirmed by you. `src/version` and `src/swagger.yaml`'s top-level `version:` field
stay untouched; add this as a second `**new:**` bullet under the existing `## 3.6.2`
heading in `src/changelog.md`, alongside the clone-endpoint entry already there.

## What this does NOT change

- No changes to `ImagesControl`, `FileManager`, `PathManager`, or the per-image delete
  route — they're already correct and this reuses them as-is.
- No new database columns, no new error codes.
- No confirmation/challenge step added at this layer. That's deliberate: by the time
  guia.lol's sweep job calls this endpoint, the human-facing confirmation (type the
  handle) and the multi-day grace period have already happened upstream, in
  `plan-delete-page.md`. This endpoint's job is to be a safe, idempotent, well-logged
  primitive for an already-authorized caller — not to re-implement guia.lol's UX
  safety net a second time.

## Feeds back into guia.lol's plan

Once this ships, `guia.lol/api/.claude/plan-delete-page.md`'s `DeletePageCascade()`
collapses its per-image loop (`CollectImageRefs` / `PurgeRemoteImages`) into a single
call: `DELETE {images_api}/key/{page.images_private_key}?public_key={page.images_public_key}`
(via a new `ImagesService::DeleteKey()` wrapper). Both columns already exist on
`pages` (`images_private_key`, `images_public_key` — `PageBase.php:13`, populated
together at creation in `PageControl.php:212-213`), so no schema change is needed on
that side either. Treat a `4042` response the same as success (already gone, or never
had access). I'll update that plan doc to reflect this once this one is confirmed.
