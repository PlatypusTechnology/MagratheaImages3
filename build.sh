#!/bin/bash

info_file="src/configs/git_info.txt"
output_file="src/configs/version.php"

timestamp=$(date '+%Y-%m-%dT%H:%M:%S')

git log -1 > $info_file

# Read the content of the file
content=$(cat "$info_file")

# Extract information
commit_hash=$(echo "$content" | grep -oP "commit \K[0-9a-f]+")
author=$(echo "$content" | grep -oP "Author: \K(.+)")
date=$(git log -1 --format="%at" | xargs -I{} date -d @{} +%Y-%m-%dT%H:%M:%S)
oneline=$(git log -1 --oneline)

echo "generating version file on $output_file"

echo "<?php" > $output_file
echo "// auto generated file" >> $output_file
echo "\$version = [" >> $output_file
echo "  'timestamp' => '$timestamp'," >> $output_file
echo "  'commit' => '$commit_hash'," >> $output_file
echo "  'commit_author' => '$author'," >> $output_file
echo "  'commit_date' => '$date'," >> $output_file
echo "  'commit_line' => '$oneline'" >> $output_file
echo "];" >> $output_file
