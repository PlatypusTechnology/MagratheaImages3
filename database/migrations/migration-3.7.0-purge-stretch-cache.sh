#!/usr/bin/env bash
# Migration 3.7.0: purge cropped "stretch" (-s) files from the generated cache.
#
# Before 3.7.0, stretch=1 produced the default crop (ImageViewer::Process() set keepAspectRatio
# after Resize() had already run). Those files keep being served from cache until deleted.
# Run it AFTER the 3.7.0 code is live (purging earlier just gets refilled with crops), on every
# instance with its own medias_path. Deleted files regenerate, stretched, on their next request.
# Idempotent: a second run, or a run against shared storage, finds nothing.
#
# Not SQL: run it with bash, not mariadb. It needs nothing from the app (no bootstrap, no DB).
# Usage: bash migration-3.7.0-purge-stretch-cache.sh <medias_path> [--delete]
#        without --delete it only lists what would be removed. Either way it prints one file
#        per line, then a count.
#
# -path '*/generated/*' is the safety bound: raw uploads keep their original name
# (raw/{id}_photo-s.jpg would match '*-s.*'). Generated names are {numeric id|uuid}_{addon},
# and a uuid is hex only, so inside generated/ the patterns can only match the stretch suffix,
# placed before _placeholder ({id}_{W}x{H}-s_placeholder.{ext}).
set -euo pipefail
dir="${1:?usage: $0 <medias_path> [--delete]}"
[ -d "$dir" ] || { echo "not a directory: $dir" >&2; exit 1; }
if [ "${2:-}" = "--delete" ]; then
  action=(-print0 -delete); outcome="deleted"
else
  action=(-print0); outcome="would be deleted (dry run: add --delete to delete them)"
fi
find "$dir" -path '*/generated/*' -type f \
  \( -name '*-s.*' -o -name '*-s_placeholder.*' \) "${action[@]}" | {
  count=0
  while IFS= read -r -d '' file; do printf '%s\n' "$file"; count=$((count + 1)); done
  echo "$count file(s) $outcome"
}
