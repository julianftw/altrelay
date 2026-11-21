#!/usr/bin/env bash
# Fail when languages/altrelay.pot no longer matches the source.
#
# A string that is added, changed or removed in the code has to show up in
# the translation template, or translators never see it. The template is
# regenerated to a temporary file and compared with the committed one. Only
# the strings and the files they are used in are compared: the header carries
# a creation date and the WP-CLI version, which differ on every run.
#
# Usage: bash bin/check-pot.sh
# Needs WP-CLI ("wp") on the PATH, or set WP_CLI_BIN. Regenerate the template with:
#   wp i18n make-pot . languages/altrelay.pot --domain=altrelay --exclude=node_modules,vendor,tests,dist,build

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# WP-CLI from the PATH, or another command named in WP_CLI_BIN.
WP="${WP_CLI_BIN:-wp}"

if ! command -v "$WP" >/dev/null 2>&1; then
    echo "This check needs WP-CLI (https://wp-cli.org) on the PATH, or its command in WP_CLI_BIN." >&2
    echo "It runs in CI on every push; locally it can be skipped." >&2
    exit 2
fi
COMMITTED="$ROOT_DIR/languages/altrelay.pot"
FRESH="$(mktemp)"

trap 'rm -f "$FRESH" "$FRESH.a" "$FRESH.b"' EXIT

"$WP" i18n make-pot "$ROOT_DIR" "$FRESH" \
    --domain=altrelay \
    --exclude=node_modules,vendor,tests,dist,build \
    --quiet

# Everything after the header (which ends at the first blank line), without
# line numbers in the references, and sorted: the order of the entries
# follows the order in which files were read, which is not worth failing on.
body() {
    awk 'found { print } /^$/ { found = 1 }' "$1" \
        | sed -E 's/^(#: .*):[0-9]+$/\1/' \
        | grep -v '^$' \
        | LC_ALL=C sort
}

body "$COMMITTED" > "$FRESH.a"
body "$FRESH" > "$FRESH.b"

if ! diff -u "$FRESH.a" "$FRESH.b"; then
    echo >&2
    echo "languages/altrelay.pot is out of date. Regenerate it with:" >&2
    echo "  wp i18n make-pot . languages/altrelay.pot --domain=altrelay --exclude=node_modules,vendor,tests,dist,build" >&2
    exit 1
fi

echo "languages/altrelay.pot matches the source ($(grep -c '^msgid ' "$COMMITTED") entries)."
