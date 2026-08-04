# Plan: fix bugs found while writing tests

Three latent bugs were found while adding unit tests for the current version (see
`src/api/tests/`). None of them currently crash anything — they were caught because the
tests pin down *actual* behavior, and in two cases the actual behavior is clearly not the
intended one. This plan fixes all three, in order of blast radius (smallest/safest first).

Each section can be done as its own commit/PR — they're unrelated to each other.

---

## 1. `ApikeyControl::createKey()` — swapped arguments break uniqueness check

**File:** `src/api/features/Apikey/ApikeyControl.php:27`

```php
public function assertKeyNotInUse($key, bool $private = true): bool {   // line 44
```
```php
if(!$this->assertKeyNotInUse($private, $key)) {                        // line 27, call site
```

`createKey()` calls `assertKeyNotInUse($private, $key)` but the method signature is
`assertKeyNotInUse($key, $private)`. The boolean flag lands in `$key` and the freshly
generated random string lands in `$private` (coerced to bool). The resulting SQL query
filters `WHERE private_key = 1` (or `0`), never the actual candidate key — so the
uniqueness check silently never checks the generated key for collisions. With 25/12
alphanumeric characters the collision odds are astronomically low, which is almost
certainly why this has never been noticed in practice.

**Impact:** low probability of ever mattering, but the uniqueness guarantee the code
claims to provide (retry up to 5 times on collision) does not actually exist.

**Fix:**
```php
if(!$this->assertKeyNotInUse($key, $private)) {
```

**Test updates:**
- `ApikeyControlTest::testCreateKeyReturnsExpectedLengths` — drop the NOTE comment about
  the swapped arguments (no longer true).
- Add a `testCreateKeyRetriesOnCollision()` case: mock/stub `assertKeyNotInUse()` (or the
  DB layer) to report "in use" once, then free, and assert `createKey()` returns a second
  attempt rather than the first. This is the first test that would have actually caught
  the bug — the current suite only checks output *length*, which the bug doesn't affect.

**Risk:** none — this only tightens an existing, currently-inert safety check. No
behavior change for any caller under normal (non-colliding) conditions.

---

## 2. `Apikey::ValidateKey()` — expiration check is inverted

**File:** `src/api/features/Apikey/Apikey.php:28`

```php
if($this->expiration != null && $this->expiration > now()) $error = "key expired";
```

`now()` returns `Y-m-d H:i:s`. Comparing strings lexically, `$this->expiration > now()`
is true when the expiration is *in the future* — i.e. this flags a key as expired
precisely when it is still valid, and (by omission) treats a key whose expiration has
already passed as fine.

**Fix:**
```php
if($this->expiration != null && $this->expiration < now()) $error = "key expired";
```

**Test updates:**
- `ApikeyTest::testValidateKeyFlagsFutureExpirationAsExpired` → rename/rewrite to
  `testValidateKeyOkWhenExpirationIsInTheFuture` and assert `ok == true`.
- `ApikeyTest::testValidateKeyTreatsPastExpirationAsOk` → rename/rewrite to
  `testValidateKeyFailsWhenExpirationIsInThePast` and assert `ok == false`,
  `data == "key expired"`.
- Drop the NOTE comments in both once the assertions match the fixed behavior.

**Risk / blast radius:** `ValidateKey()` is currently **not called from anywhere** in
`ApikeyApi.php`, `ImagesApi.php`, or `ImageUploader.php` — grep confirms the only
callers are `Apikey.php` itself (the definition) and the new test file. So fixing this
has zero effect on any live request path today. Two things worth deciding before/along
with the fix, flagged for Paulo rather than assumed:
1. Was `ValidateKey()` meant to be wired into the upload/read path (e.g. in
   `ImageUploader::Upload()` or `ImagesApi`) and that wiring was simply never finished?
   If so, fixing the inversion alone won't change behavior until it's actually called
   somewhere.
2. If it's dead code, worth a decision on whether to leave it as intended
   future-use validation or remove it — out of scope for this bug-fix pass either way.

---

## 3. `Helper::GetSize()` — MB branch is unreachable

**File:** `src/api/shared/Helper.php:6`

```php
public static function GetSize($size): string {
    if(empty($size)) return "-";
    $kb = $size / 1024;
    if($kb < 1024) return round($kb, 2)." KB";
    $mb = $kb / 1024;
    if($kb < 1024) return round($mb, 2)." MB";   // <-- checks $kb again, not $mb
    $gb = $mb / 1024;
    return round($gb, 2)."GB";
}
```

By the time execution reaches the second `if`, the first `if` has already proven
`$kb >= 1024`, so `if($kb < 1024)` is always false. Every size ≥ 1MB falls straight
through to the GB branch, e.g. a 2MB file is reported as `"0GB"` instead of `"2 MB"`.

**Fix:**
```php
if($mb < 1024) return round($mb, 2)." MB";
```

**Callers affected (all display-only, no logic depends on the string format):**
- `Images::GetSize()` → `admin/MediaManager/views/home.php` (admin file list column)
- `admin/GeneratedFileManager/views/files.php`
- `ImageViewer::DebugView()` (debug endpoint output)

**Test updates:**
- `HelperTest::testGetSizeOneMegabyteFallsThroughToGB` → rename to
  `testGetSizeUnderOneGbReturnsMB` and assert `"1 MB"` for `1024*1024` bytes and
  something like `"2.5 MB"` for `1024*1024*2.5`.
- `HelperTest::testGetSizeOneGigabyteReturnsGB` — keep as-is (this one already exercises
  the now-correctly-reached GB branch and should keep passing unchanged).
- Drop the "latent bug" NOTE comment once the assertions reflect the fix.

**Risk:** purely cosmetic/display — changes what size string shows up in two admin
views and one debug endpoint. No stored data, no API response shape changes (not used
in any JSON API response, only server-rendered admin views).

---

## Suggested order of work

1. **`Helper::GetSize()`** — smallest, purely cosmetic, easiest to eyeball-verify in the
   admin UI after the change.
2. **`ApikeyControl::createKey()` args** — one-line swap, inert today, safe.
3. **`Apikey::ValidateKey()` inversion** — also one line, but confirm with Paulo first
   whether `ValidateKey()` was ever meant to be wired into the request path (see open
   question above) before treating this as "just a bug fix."

After each fix: update the corresponding NOTE-commented test(s) listed above (they were
deliberately written to describe *current* behavior, bug included, specifically so a fix
here is a visible, intentional test diff rather than a silent behavior change) and rerun
the full suite:

```
cd src/api && php ../vendor/bin/phpunit
```
