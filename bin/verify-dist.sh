#!/usr/bin/env bash
# Fail if the distribution ZIP contains anything outside the plugin's
# runtime files. Guards against build artefacts (coverage reports, caches,
# dev configs) slipping into a release.
# Usage: bash bin/verify-dist.sh dist/altrelay.zip

set -euo pipefail

ZIP="${1:-dist/altrelay.zip}"
ALLOWED='^altrelay/((altrelay\.php|LICENSE|readme\.txt|uninstall\.php)|(assets/(css|js)/[^/]+\.(css|js))|(includes/(class-altrelay-[a-z-]+|altrelay-functions)\.php)|(languages/[^/]+\.(pot|po|mo|json))|(assets/|assets/css/|assets/js/|includes/|languages/)?)$'

UNEXPECTED=$(unzip -Z1 "$ZIP" | grep -vE "$ALLOWED" || true)

if [ -n "$UNEXPECTED" ]; then
    echo "Unexpected files in $ZIP:" >&2
    echo "$UNEXPECTED" >&2
    exit 1
fi

for required in altrelay/altrelay.php altrelay/readme.txt altrelay/uninstall.php altrelay/LICENSE; do
    if ! unzip -Z1 "$ZIP" | grep -qx "$required"; then
        echo "Missing $required in $ZIP" >&2
        exit 1
    fi
done

echo "$ZIP contains only runtime files."
