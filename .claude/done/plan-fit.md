# Plan: `fit=1` resize mode + `stretch` fix

## Context

Claque displays images from images.guia.lol via `/image/{public_key}/{id}/x/{WxH}` and needs
each image in its own aspect ratio, capped by a max width and a max height. Today every sized
request returns exactly W×H, center-cropped. The original request is copied verbatim in the
appendix at the end of this file. Everything below was checked against the source on
2026-11-24 (HEAD `ac134f4`, version 3.6.4).

Two copies of this plan exist: `.claude/plan-fit.md` (the working copy) and
`future-plans/plan-fit.md`. Edit both together.

## Decisions (Paulo, 2026-11-24)

- **Version:** Paulo gives it at build time. `{VERSION}` below is a placeholder. Ask for the
  value before touching `src/version`, `swagger.yaml` or `changelog.md`.
- **Docs:** update SKILL.md and `swagger.yaml` *properly*: a full pass against the source, not
  only adding `fit` (see Docs).
- **Infra:** no CDN in front of images.guia.lol. The two prod instances are believed **not** to
  share `medias_path`.
- **Stale `-s` purge:** ships as a migration file, plus the deploy steps.

## The request vs. the code: corrections

1. **There are no "public-mode versions of those routes."** `secure_api` was removed in 3.5.0.
   The only image routes are `image/:public_key/:id[...]` (`src/api/api.php:91-99`). The
   public-mode table that suggests otherwise comes from the stale
   `~/.claude/skills/magrathea-images-api/SKILL.md`. **No route changes are needed.** `fit` is a
   query flag read inside `ImagesApi`.

2. **The purge pattern `*-s.*` is incomplete.** Stretched placeholders are named
   `{id}_{W}x{H}-s_placeholder.{ext}` (`Images::GetFileName()`: `-s` comes before
   `_placeholder`). `*-s.*` misses them.

3. **Use the purge, not `generate=1`, for stale `-s` files.** `generate=1` regenerates only the
   URL that sends it, and the docs tell clients not to send it routinely. Cropped `-s` files
   would stay on disk and keep being served to every client that doesn't send it.

4. **"A missing dimension falls back to the thumbnail, as today" holds for `ViewImage` only.**
   `Preview()` does `explode('x', $size)` and passes `$sizes[1]` (null or `""`) into
   `ImageViewer::Process(int $width, int $height)`. That throws a `TypeError` (an `\Error`,
   which `catch(\Exception)` doesn't catch), so the response is a 500. This is pre-existing.
   **Leave it alone.** It's an admin/testing route and outside this change.

5. **`stretch` has no description in `swagger.yaml`.** There is nothing to correct, so the plan
   adds one. `placeholder` and `generate` have no descriptions either (see Docs).

6. **A test that checks `keepAspectRatio === false` after `Process()` would not catch the
   stretch bug.** With the bug, the flag is still `false` once `Process()` returns, because it is
   set after `Resize()` instead of before. The stretch test has to check the pixels it produces
   (see Tests).

## Design

### Fit = fitted dimensions + the stretch path

Don't add a third branch inside `ResampleCalculator::Calculate()`. Instead:

1. Compute the fitted size from the source size and the box:
   `scale = min(W/srcW, H/srcH, 1)`, `w = max(1, round(srcW·scale))`, `h = max(1, round(srcH·scale))`.
2. Resample the **whole** source into exactly `w×h` with `keepAspectRatio = false`. That is
   `Calculate()`'s existing `SameAspectRatio()` branch: zero offsets, full source to full
   destination.

Why not pass the fitted size to the crop calculator? Rounding makes the fitted ratio differ
from the source ratio by a tiny amount (3000×2000 → 800×533: 1.5 vs 1.5009). `Calculate()`
would then take `CutHorizontal`/`CutVertical` and trim a pixel row or column. Using the stretch
path for a sub-pixel ratio difference is invisible, and it skips `CutVertical()`'s odd
two-stage resize entirely. This is also why the stretch fix ships with fit: fit relies on that
code path.

The `max(1, …)` clamp matters for extreme ratios. Example: 4000×10 into a 100×100 box gives
100×0.25, which rounds to 0, and `imagecreatetruecolor(100, 0)` throws a `ValueError`.

### Placeholder order is the main trap

`ImageResizer::Resize()` (`ImageResizer.php:132-133`) calls `AdjustPlaceholder()` first, and
that scales the requested box. For fit, `placeholder_size` has to apply to the **fitted**
dimensions, so the fitted size must be computed **before** `AdjustPlaceholder()` runs. The order
changes the result:

| Original | Box | `placeholder_size` | fit, then placeholder (correct) | placeholder, then fit (wrong) |
|---|---|---|---|---|
| 400×300 | 800×800 | 0.5 | **200×150** | 400×300 |
| 1600×900 | 800×800 | 0.5 | **400×225** | 400×225 |

Only the no-upscale case shows the difference, so the test has to include it. In the fit path,
also clamp the result of `AdjustPlaceholder()` to ≥1. The crop path stays untouched, because it
must behave exactly as it does now.

### One filename builder, shared by both sides

`LookForImage()` runs **before** the DB lookup; that's the purpose of quick access. It has no
`Images` instance and builds the name from `$params["id"]` and the URL size string.
`GetFileName()` builds the name from `accessId ?? id` and `"{$w}x{$h}"`. These two builders are
separate today. That's how `-s` ended up duplicated, and it's how a missing `-f` would fail
silently (quick access never hits, every request takes the slow path, and no test notices).

Add one static builder that both sides call:

```php
// Images
public static function GeneratedName(string $accessId, string $size, bool $stretch, bool $fit, bool $placeholder): string
// "thumb" never gets a mode suffix; fit wins over stretch:
//   {accessId}_{size}[-f|-s][_placeholder]
```

- `GetFileName()` and `GetThumbFile()` = generated folder + `GeneratedName(...)`.
- `LookForImage()` = `GeneratedName(...) . ".webp"`.

Side effect: `LookForImage($params, "thumb")` currently appends `-s` when `stretch=1`, which
looks for `{id}_thumb-s.webp`, a file that can never exist. With the shared builder, thumb
lookups ignore stretch and fit. Responses don't change; those requests just stop missing quick
access. This matters for fit: Claque requests with `fit=1` and a missing dimension fall back to
the thumbnail, and without this they would always miss quick access.

### Flag precedence, resolved once

In `ImagesApi`, add a private helper that returns `fit = $_GET["fit"] == '1'` and
`stretch = !fit && $_GET["stretch"] == '1'`. `ViewImage()` and `LookForImage()` both use it.
`Preview()` keeps its own looser `@$_REQUEST["stretch"]` check for requests without `fit`, so
their behaviour doesn't change, but it also applies "fit wins." `GeneratedName()` applies fit
before stretch as well, as a second guard.

## Changes

### 1. `ImageResizer` (`src/api/features/Images/ImageResizer.php`)
- Add `public bool $fit = false;` and a chainable `SetFit()`.
- Add `public static function FitDimensions(int $srcW, int $srcH, int $boxW, int $boxH): array`
  that returns `[w, h]` using the formula above. It's pure math and can be tested without GD.
  (It could go on `ResampleCalculator` instead. Here is better, because the calculator deals
  with crop geometry and fit never reaches its crop branches.)
- In `Resize()`, before `AdjustPlaceholder()`: if `$fit`, set `width`/`height` to the fitted
  dimensions and set `keepAspectRatio = false`. After `AdjustPlaceholder()`: if `$fit`, clamp
  both to ≥1. Add a debug line (`fit: box WxH -> wxh`) for `preview?debug=1`.

### 2. `ImageViewer` (`src/api/features/Images/ImageViewer.php`)
- `Size($w, $h, $stretch=false, $fit=false)` passes `$fit` to `GetFileName()` and `Process()`.
- `Process(int $width, int $height, bool $stretch=false, bool $fit=false)`:
  - **Stretch fix:** `if($stretch) $this->resizer->keepAspectRatio = false;` moves from after
    `Resize()` (line 189) to before it.
  - `if($fit) $this->resizer->SetFit();`, also before `Resize()`.

### 3. `Images` (`src/api/features/Images/Images.php`)
- Add `GeneratedName()` as described above.
- `GetFileName($w, $h, $stretch=false, $fit=false)` and `GetThumbFile()` call it.
  `BuildGenFileName()` stays, because tests and `FileManagerTest` comments refer to it.

### 4. `ImagesApi` (`src/api/features/Images/ImagesApi.php`)
- Add the private flag helper (fit wins).
- `LookForImage()`: split into a public `QuickAccessFileName($params, $size): string` (built
  from `GeneratedName()`) plus the existing `ViewQuickAccess()` call. The split is needed
  because `ViewQuickAccess()` calls `exit` on a hit, which a test can't get past.
- `ViewImage()` passes `$fit` to `Size()`. The single-dimension fallback to thumb stays as it
  is.
- `Preview()` reads `fit` and passes it to `Size()`.
- SVGs (`CanResize()` false) are already served raw before any of this runs. No change there.

### 5. `src/api/api.php:90`
- Update the comment `// gets: generate, placeholder, stretch` to add `fit`. Comment only; no
  route changes.

### 6. Migration: `database/migrations/migration-{VERSION}-purge-stretch-cache.sh`

The fix changes no schema, so this is the folder's first non-SQL migration. It's a bash script
and not a PHP script on purpose: it needs nothing from the app (no bootstrap, no DB, no
environment selection), so a deployer can read exactly what it deletes before running it.

```bash
#!/usr/bin/env bash
# Migration {VERSION}: purge cropped "stretch" (-s) files from the generated cache.
# Before {VERSION}, stretch=1 produced center crops (ImageViewer::Process() set
# keepAspectRatio after Resize() had already run). Those files keep being served from
# cache until deleted. Run AFTER the {VERSION} code is live (purging earlier just gets
# refilled with crops), on every instance with its own medias_path.
# Idempotent: a second run, or a run against shared storage, finds nothing.
# Usage: migration-{VERSION}-purge-stretch-cache.sh <medias_path> [--delete]
#        without --delete it only lists what would be removed.
set -euo pipefail
dir="${1:?usage: $0 <medias_path> [--delete]}"
[ -d "$dir" ] || { echo "not a directory: $dir" >&2; exit 1; }
action=(-print); [ "${2:-}" = "--delete" ] && action=(-print -delete)
find "$dir" -path '*/generated/*' -type f \
  \( -name '*-s.*' -o -name '*-s_placeholder.*' \) "${action[@]}" | wc -l
```

- `-path '*/generated/*'` is the safety bound, not decoration. Raw uploads keep their original
  name (`raw/{id}_{name}`), so an upload called `photo-s.jpg` sits at `raw/12_photo-s.jpg` and
  would match `*-s.*` without it. Generated names are `{numeric-id|uuid}_{addon}`, and a UUID
  is hex only, so inside `generated/` the pattern can only match the stretch suffix.
  Non-numeric sizes such as `/x/800x600-s` throw a `TypeError` before `Save()`, so they never
  write a file.
- **Check it before shipping:** build a fixture tree in the scratchpad with
  `f/generated/1_800x600-s.webp`, `f/generated/1_800x600-s_placeholder.webp`,
  `f/generated/{uuid}_200x300-s.jpg`, `f/generated/1_800x600.webp`,
  `f/generated/1_800x600-f.webp`, `f/generated/1_thumb.webp` and `f/raw/1_photo-s.jpg`. A dry
  run must list exactly the first three, `--delete` must remove only those three, and a second
  run must report 0.
- `install.md` § "Applying migrations": add that `.sh` migrations are not run through
  `mariadb`. They're run with bash on **each** instance, against that instance's
  `medias_path`, dry run first, after the code is deployed.

## Tests (`src/api/tests`)

The tests that need GD use generated fixtures: a temporary JPEG/PNG made with `imagecreatetruecolor`
and filled with **colored vertical bands** (red left quarter, blue right quarter, green middle).
`ImageResizer::$rawFile` is public, so tests can point it at the fixture without touching
`medias_path`. Skip with `markTestSkipped` when GD is missing, the same way
`ImageResizerTest::testGetGDReturnsNewGdImageWhenSet` does.

**`ImageResizerTest`, fit math (`FitDimensions`, no GD)**
- Landscape into a square box: 1600×900 → 800×800 gives 800×450
- Portrait into a square box: 900×1600 → 800×800 gives 450×800
- Smaller than the box: 400×300 → 800×800 gives 400×300 (no upscaling)
- Same ratio as the box: 1600×1200 → 800×600 gives 800×600
- Rounding: 3000×2000 → 800×800 gives 800×533
- Extreme ratio clamp: 4000×10 → 100×100 gives 100×1

**`ImageResizerTest`, fit end-to-end (GD)**
- `SetFit()`, `SetDimensions(800, 800)`, `Resize()` on a 1600×900 fixture: `imagesx`/`imagesy`
  of `GetGD()` are 800×450. The pixels near x=0 are red and near x=max are blue, which proves
  nothing was cropped.
- The same check on a 400×300 fixture: the output is 400×300.

**`ImageResizerTest`, fit + placeholder (GD)**
- `ConfigApp::Mock(["placeholder_size" => "0.5"])`. A 400×300 fixture in an 800×800 box gives
  **200×150**, which catches the order trap. A 1600×900 fixture in an 800×800 box gives 400×225.

**`ImageViewerTest`, stretch fix (GD)**
- Use a 1000×500 band fixture, set `$viewer->resizer` to a resizer pointed at it, and call
  `DontSave()`, `ForceGeneration()` and `Process(500, 500, true)`. The output is 500×500 and
  the pixel at (5, 250) is **red**. With today's bug the center crop removes the red band and
  that pixel is green. This test must fail on the current code. Run it once before the fix to
  confirm.
- Control case: `Process(500, 500, false)` gives a green pixel at (5, 250) (crop unchanged).
- Fit wins: `Process(800, 800, true, true)` on a 1600×900 fixture gives 800×450.

**`ImagesTest`, filenames**
- `GetFileName(800, 800, false, true)` ends with `{id}_800x800-f`. With placeholder:
  `{id}_800x800-f_placeholder`. With fit and stretch together it's still `-f`. With stretch
  only it's `-s` (unchanged). With neither, the name has no suffix (unchanged). Set `accessId`
  to a UUID once to check that it's used.
- `GetThumbFile()` output is unchanged.

**`ImagesApiTest`, quick-access name**
- With `$_GET["fit"]="1"` (plus `placeholder`, plus `stretch`): `QuickAccessFileName()` equals
  `basename(GetFileName(...)) . ".webp"` for the same id, size and flags. This is the "builds
  exactly the same name" requirement as an assertion. Also check that the thumb lookup ignores
  `fit`/`stretch`. Clean up `$_GET` in `tearDown`.

Run the full suite before and after. The existing `resizeTest.php` crop cases must stay green
unchanged; they are the guarantee that "requests without fit behave exactly as now." The
migration script is checked separately against the fixture tree in Changes §6.

## Docs

The rule for both files: **the PHP source is the ground truth**, not the changelog or the
current docs. Both are known to lag behind the code (see the memory note on skills.md syncing).

### `src/swagger.yaml`: full pass over the image-viewing section

Adding `fit`:
- Add `fit` (query, integer, enum `[0, 1]`, default 0) to `/image/{public_key}/{id}`,
  `/image/{public_key}/{id}/x/{size}` and `/image/{public_key}/{id}/preview/{size}`. Those are
  the routes that accept a size. `/thumb`, `/raw` and `/details` don't accept one, so `fit`
  isn't added there.
- Description for `fit`: scales to fit inside W×H keeping the original ratio; no crop, no
  padding, never upscales; overrides `stretch`; both W and H required (a missing one means
  thumbnail); `placeholder` applies to the fitted size; cached separately (`-f`).

Gaps found on 2026-11-24 that are already wrong or missing today (re-check each one against
`ImagesApi`/`ImageViewer` at build time):
1. **No modifier parameter has a description.** Add them:
   - `stretch`: resizes to exactly W×H ignoring the ratio (distorts); broken before {VERSION},
     when it returned the default crop; overridden by `fit`.
   - `placeholder`: low-quality version, scaled by `placeholder_size`, cached separately.
   - `generate`: regenerates and overwrites the cached file, and skips the quick-access lookup.
     It's expensive, so not meant for routine use.
2. **The default behaviour isn't stated.** `/image/{public_key}/{id}` and `/x/{size}`
   center-crop to fill exactly W×H by default.
3. **The `width`/`height` aliases for `w`/`h` are missing** (`GetWidthHeight()`).
4. **Precedence isn't documented.** Path `/x/{size}` beats `?size=`, which beats `w`/`h`. When
   `?size` is present, `w`/`h` are ignored entirely.
5. **The single-dimension fallback isn't documented.** `/x/800`, `/x/800x`, `?w=800` and
   `?size=800` all return the thumbnail, which is a `thumb_size` square crop, not a fit.
   Today's text only says "if none are provided."
6. **`/x/raw` is undocumented.** It serves the original file (`ViewImage`: `$size == "raw"`).
   `/preview/{size}` also accepts `thumb` and `raw`.
7. **Response content types don't match the code.** Sized and thumb responses are `image/webp`
   when `webp_quick_access` is on, and otherwise the original's format. Non-resizable images
   (SVG) are served raw as `image/svg+xml`. `/preview` always renders in the original's format
   (`DisplayFromGD($gd, $resizer->extension)`), never forced to webp. Its listed
   `image/webp` is only correct for webp originals.
8. **`/preview` details.** `generate` doesn't apply (it always generates and never saves).
   `stretch` there accepts any truthy value. Both dimensions are required (don't document the
   pre-existing 500; see correction 4).

### `~/.claude/skills/magrathea-images-api/SKILL.md` **and** repo `skills.md`

**SKILL.md is out of date throughout, not just in one section.** A diff against `skills.md`
shows about 240 changed lines: the deleted public/secure mode (in Step 0, Workflow 3, the
quick-reference table and the do's/don'ts), the old `secure` field in `/settings`, no UUID
section, no clone workflow, and the old message-based error table. The only content that exists
in SKILL.md and not in `skills.md` is its frontmatter, plus a delete-key workflow that
`skills.md` already covers as Workflow 8. So:

1. **Full pass of `skills.md` against the source.** Read every route in `api.php`, plus
   `ApikeyApi`, `ImagesApi`, `/settings` and `/version` output, and the error codes. Fix
   whatever has drifted. Don't assume the repo copy is right just because it's newer.
2. **Fit/crop edits in `skills.md`:**
   - Add a `fit` row to "Image modifier query parameters".
   - Correct the `stretch` row: it distorts, it was broken before {VERSION}, and `fit`
     overrides it.
   - State that by default `/x/{WxH}` **center-crops to fill exactly W×H**, and that a single
     dimension (`/x/800`, `/x/800x`, `?w=800`) returns the thumbnail.
   - Fix "These work on all image-serving endpoints". That's false: `fit`/`stretch` do nothing
     on `/thumb` and `/raw`, and `generate` does nothing on `/preview`.
   - Add a short "fit inside a max box" recipe, matching the Report-back section below.
3. **Rebuild SKILL.md** as its frontmatter (lines 1-5, kept as is) followed by the body of
   `skills.md`. Done means
   `diff <(tail -n +6 ~/.claude/skills/magrathea-images-api/SKILL.md) skills.md` prints
   nothing. This closes the sync gap the memory note has tracked as open since 2026-09-03. Also
   update that memory afterwards.

### Versioning (per `claude.md`: update all three)
- **Ask Paulo for `{VERSION}`** before this step.
- `src/version` gets `{VERSION}` (no trailing newline).
- `src/swagger.yaml` line 11 `version:` gets the same value.
- `src/changelog.md`: a new `## {VERSION}` section dated on the build day, with:
  - **new:** `fit=1` (formula, no upscale, cache suffix `-f`, placeholder applied to the fitted
    size, overrides `stretch`, available on `/x/{WxH}`, `?size=`, `?w=&h=` and `/preview/{WxH}`)
  - **fix:** `stretch=1` set `keepAspectRatio` after `Resize()` had already run, so stretched
    files were center-cropped like every other size (`ImageViewer::Process()`); existing `-s`
    files must be purged with `database/migrations/migration-{VERSION}-purge-stretch-cache.sh`
  - **improvement:** generated-file names for the cache path and the quick-access lookup come
    from one builder (`Images::GeneratedName()`); thumb quick-access lookups no longer miss
    when `stretch`/`fit` is sent
  - **docs:** swagger image routes reworked (modifier descriptions, aliases, precedence,
    fallback, content types); `skills.md` and the Claude skill resynced
- `src/configs/version.php` is auto-generated by CI (`scripts/build.sh`). Don't touch it.

## What this does NOT change
- The default crop path, `ResampleCalculator`, thumbnails, `CutVertical()`'s quirks, Preview's
  single-dimension 500 (pre-existing, see correction 4).
- Routes, DB schema, config, error codes.
- Deleting an image: `ImagesControl::RemoveRawFile()` already deletes `{id}_*` and `{uuid}_*`,
  so `-f` files are cleaned up with no change. The admin "Delete Pattern" regex
  (`FileManager.php:56`) already accepts `-`.
- R2 backups store raw files only. Generated files aren't there, so there is nothing to purge
  in R2.
- **No commits.** Paulo handles git.

## Deploy

1. **Before deploying, check who sends `stretch=1` today** (Apache access logs, both
   instances). Any consumer that sends it has been getting crops. After the purge it gets
   **distorted** images, which is the documented behaviour but still a visible change for that
   consumer. Consumers are in beta, so the risk is low, but look first.
2. **Deploy the code to both instances.**
3. **Run the migration on each instance**, against that instance's `medias_path`:
   ```sh
   bash database/migrations/migration-{VERSION}-purge-stretch-cache.sh <medias_path>           # dry run, lists files + count
   bash database/migrations/migration-{VERSION}-purge-stretch-cache.sh <medias_path> --delete
   ```
   Storage is believed not to be shared, so run it on both. If it turns out to be shared, the
   second run reports 0. It's idempotent, so the topology doesn't need confirming first.
4. Nothing else: no CDN to purge, no SQL migration, no config change. `fit=1` URLs are new, so
   nothing is cached for them.

## Report back (what to tell Claque when this is done)

```
https://images.guia.lol/image/{public_key}/{uuid}/x/{maxW}x{maxH}?fit=1
https://images.guia.lol/image/{public_key}/{uuid}/x/{maxW}x{maxH}?fit=1&placeholder=1   (LQIP)
```
- Both dimensions are required. `x/800x?fit=1` returns the cropped square thumbnail, not a fit.
- The response is **at most** maxW×maxH. It is never upscaled, so the client must not assume
  it fills the box.
- To reserve layout space before load, read the original `width`/`height` from
  `/image/{public_key}/{uuid}/details` and apply the same formula client-side:
  `s = min(maxW/w, maxH/h, 1)` → `round(w·s) × round(h·s)`.
- Use a **small fixed set** of box sizes (e.g. 400×400, 800×800, 1600×1600), not
  container-exact pixel sizes. Every distinct `WxH` is a separate file generated and stored on
  disk.

The final report also repeats the Deploy steps with the real `{VERSION}` filled in.

## Open questions
- None blocking. `{VERSION}` comes from Paulo at build time.

---

## Appendix: original request (verbatim)

> Context: Claque (a client app) displays images from images.guia.lol in secure mode, via
> `/image/{public_key}/{id}/x/{WxH}`. It needs each image shown in its own aspect ratio, limited
> by a maximum width and a maximum height. Today that's impossible: every image comes back
> cropped to exactly W×H.
>
> What the code does today (checked in the source):
> - `ResampleCalculator::Calculate()` always outputs exactly W×H. When the ratios differ it crops
>   (`CutHorizontal` / `CutVertical`). `ImageResizer::$keepAspectRatio = true` means "keep the
>   ratio by cropping to fill the box", not "fit inside the box".
> - Sending only one dimension (`/x/800`, `/x/800x`, `?w=800`) does not fit the image.
>   `ImagesApi::GetWidthHeight()` gets a null height and `ViewImage()` falls back to `ViewThumb()`.
> - `?stretch=1` is the only resize option clients can set, and it is broken.
>   `ImageViewer::Process()` sets `$this->resizer->keepAspectRatio = false` AFTER calling
>   `$this->resizer->Resize()`, so the generated `-s` file is cropped like any other.
>
> What I need:
>
> 1. A `fit=1` query option.
>    - It works wherever a W×H size is accepted: `/image/{public_key}/{id}/x/{WxH}`,
>      `/image/{public_key}/{id}?size=WxH` and `?w=&h=`, the public-mode versions of those routes,
>      and `/image/.../preview/{WxH}`.
>    - Output size: scale = min(W/srcW, H/srcH, 1), output = round(srcW·scale) × round(srcH·scale).
>      No cropping, no padding or letterboxing, and never larger than the original. An original
>      that is already smaller than the box is served at its own size (still re-encoded/webp as
>      usual).
>    - Both W and H are still required. A missing dimension falls back to the thumbnail, as today.
>    - If `fit=1` and `stretch=1` are both sent, fit wins and stretch is ignored.
>    - `placeholder=1` together with `fit=1` returns the placeholder of the fitted image
>      (`placeholder_size` is applied to the fitted dimensions, not to the requested box).
>    - Cache: generated files get their own `-f` suffix (`{id}_800x800-f.webp`,
>      `{id}_800x800-f_placeholder.webp`). `Images::GetFileName()` and the webp quick-access lookup
>      in `ImagesApi::LookForImage()` must build exactly the same name, or quick access never finds
>      the file.
>    - SVGs and other images that can't be resized are served raw, as today.
>    - Requests without `fit` must behave exactly as they do now. Other apps rely on the crop.
>
> 2. Fix `stretch`: set `keepAspectRatio = false` before `Resize()` runs in
>    `ImageViewer::Process()`. The `-s` files already on disk are cropped and will keep being served
>    from cache, so tell me how to get rid of them (purge `*-s.*` from the generated folders, or rely
>    on `generate=1`).
>
> 3. Tests in `src/api/tests`:
>    - fit: landscape into a square box, portrait into a square box, an original smaller than the
>      box (no upscaling), an original with the same ratio as the box
>    - fit with placeholder
>    - the `-f` filename in both `GetFileName` and `LookForImage`
>    - the stretch fix
>
> 4. Docs:
>    - `swagger.yaml`: add `fit` to every image route, and correct the `stretch` description
>    - the client-facing skill doc at `~/.claude/skills/magrathea-images-api/SKILL.md`: add `fit`
>      to the "Image modifier query parameters" table, and state that by default `/x/{WxH}` crops
>      to fill the box exactly (it currently doesn't say so) and that a single dimension returns
>      the thumbnail
>    - changelog/version bump, following this repo's conventions
>
> Don't commit anything; Paulo handles git. When you're done, report the exact URL format a client
> should use, and anything that needs doing at deploy time.
