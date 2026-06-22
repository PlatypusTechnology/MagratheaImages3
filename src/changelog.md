# Changelog

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