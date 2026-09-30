#!/usr/bin/env bash
# Builds an installable ZIP of the plugin for "Site administration > Plugins > Install plugins".
# The archive contains a single top-level "syllabus" folder, as required by the Moodle installer.
# Usage: ./build.sh [output-dir]
set -euo pipefail

cd "$(dirname "$0")"
outdir="${1:-dist}"
release=$(sed -n "s/^\$plugin->release *= *'\(.*\)';.*/\1/p" version.php)
mkdir -p "$outdir"
zipfile="$outdir/local_syllabus-${release}.zip"
rm -f "$zipfile"

# Files marked export-ignore in .gitattributes are left out.
git archive --format=zip --prefix=syllabus/ -o "$zipfile" HEAD
echo "$zipfile"
