# Changelog

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