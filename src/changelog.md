# Changelog

## 3.5.0
2026-08-04
- **fix:** `ApikeyControl::createKey()` was calling `assertKeyNotInUse()` with the key and private-flag arguments swapped, so the uniqueness check never actually queried for the generated key
- **fix:** `Helper::GetSize()` MB branch re-checked the KB value instead of the MB value, so any size ≥ 1MB always fell through to the GB branch (e.g. a 2MB file showed as "0GB")
- **fix:** `Apikey::ValidateKey()` had an inverted expiration comparison, flagging valid keys as expired and expired keys as valid; now fixed and wired into the upload path (`ImagesApi::GetApiKeyByValue()`) so inactive, expired, or over-usage-limit keys are rejected on upload (uploads by file and by URL)

## 3.4.2
2026-08-01
- **fix:** SVG uploads (unreadable by `getimagesize`) stored null width/height, crashing resizing with a fixed size (`ResampleCalculator` TypeError); now non-resizable images fall back to raw and uploads no longer persist null dimensions

## 3.4.1
2026-07-20
- **fix:** using Open Api admin feature instead of custom Swagger
- **new:** health-check endpoint
- **improvements:** using Magrathea v.2.2.1

## 3.4.0
2026-07-13
- **fix:** removing double data encapsulation on upload image
- **new:** api key deletion function on admin

## 3.3.3
2026-06-23
- **fix:** fixing pagination on /key/images
- **fix:** htaccess fixed

## 3.3.2
2026-04-12
- **new:** swagger Admin Feature.
- **new:** docker creation and destroy by session
- **fix:** now `upload-url` don't block uploads without valid extenstion; checks mime type instead.

## 3.3.1
2026-01-01
- **new:** get images by subfolder
- **fix:** getting svg raw files

## 3.3.0
2025-12-29
- **new:** subfolder for images

## 3.2.2
2025-12-21
- **new:** implementing sentry
- **new:** caddy sample file
- **fix:** invalid variable in `upload-url` error

## 3.2.1
2025-02-06
- png images generate webp images, not png (this will improve size and avoid looking for two image types)
- code cleaning: removing code that was not being called anymore due to 3.2.0 update
- improved performance
- TODO: remove medias by file patterns

## 3.2.0
2025-01-20
**new resize processing functions**
-	now considering png transparency;
-	better performance;
-	removing unnecessary resizes;
-	tests;

## 3.1.7
fixed return on image upload with url: removed duplicate layer of success/data object