#!/usr/bin/env bash
# Builds the plugin as it is shipped: build/queue-triage-for-action-scheduler/ and a zip next to it.
# Only the files a site needs go in; tests, docs and tooling stay out.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="queue-triage-for-action-scheduler"
out="$root/build/$slug"

rm -rf "$root/build"
mkdir -p "$out/includes" "$out/assets"
cp "$root/$slug.php" "$root/uninstall.php" "$root/readme.txt" "$root/LICENSE" "$out/"
cp "$root"/includes/*.php "$out/includes/"
cp "$root/assets/admin.css" "$root/assets/admin.js" "$out/assets/"

version="$(sed -n 's/^ \* Version: *//p' "$root/$slug.php")"
stable="$(sed -n 's/^Stable tag: *//p' "$root/readme.txt")"
if [ "$version" != "$stable" ]; then
  echo "Version $version in the plugin header does not match Stable tag $stable in readme.txt" >&2
  exit 1
fi

(cd "$root/build" && python3 - "$slug" <<'PY'
import os, sys, zipfile
slug = sys.argv[1]
with zipfile.ZipFile(slug + ".zip", "w", zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(slug):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(base, name)
            z.write(path, path)
PY
)
echo "Built build/$slug.zip (version $version)"
