#!/usr/bin/env bash
# Build distribution zip for AltRelay.
# Usage: bash build-zip.sh
#
# Files are selected by .distignore, which is the single source of truth for
# what ships (build-zip.ps1 reads the same file). A leading "/" anchors a
# pattern to the plugin root; a trailing "/" marks a directory.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$DIST_DIR/altrelay"
ZIP_PATH="$DIST_DIR/altrelay.zip"
DISTIGNORE="$ROOT_DIR/.distignore"

if [ ! -f "$DISTIGNORE" ]; then
    echo "Error: .distignore not found at $DISTIGNORE" >&2
    exit 1
fi

# Without a zip tool (for example Git Bash on Windows), use the PowerShell
# build, which produces an identical archive.
if ! command -v zip >/dev/null 2>&1; then
    if command -v powershell.exe >/dev/null 2>&1; then
        echo "zip not found; delegating to build-zip.ps1."
        exec powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ROOT_DIR/build-zip.ps1"
    fi
    echo "Error: the zip command is required." >&2
    exit 1
fi

# Read the patterns, ignoring blank lines and comments.
PATTERNS=()
while IFS= read -r line || [ -n "$line" ]; do
    line="${line%%$'\r'}"
    line="$(echo "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    [ -z "$line" ] && continue
    case "$line" in
        \#*) continue ;;
    esac
    PATTERNS+=("$line")
done < "$DISTIGNORE"

# Clean previous build.
rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR"

# Stage production files.
if command -v rsync >/dev/null 2>&1; then
    EXCLUDES=()
    for pattern in "${PATTERNS[@]}"; do
        # rsync anchors a leading "/" to the transfer root, as .distignore does.
        EXCLUDES+=("--exclude=$pattern")
    done
    rsync -a "${EXCLUDES[@]}" "$ROOT_DIR/" "$STAGE_DIR/"
else
    # Fallback: copy each top-level entry that is not excluded, then remove
    # any remaining (nested or wildcard) matches from the staging copy.
    shopt -s dotglob nullglob
    for entry in "$ROOT_DIR"/*; do
        name="$(basename "$entry")"
        skip=0
        for pattern in "${PATTERNS[@]}"; do
            bare="${pattern#/}"
            bare="${bare%/}"
            if [ "$bare" = "$name" ]; then
                skip=1
                break
            fi
        done
        [ "$skip" -eq 1 ] && continue
        cp -R "$entry" "$STAGE_DIR/"
    done
    for pattern in "${PATTERNS[@]}"; do
        target="${pattern#/}"
        target="${target%/}"
        # Unquoted on purpose so wildcards in .distignore expand.
        # shellcheck disable=SC2086
        rm -rf "$STAGE_DIR"/$target
    done
    shopt -u dotglob nullglob
fi

# Create zip with altrelay/ prefix.
cd "$DIST_DIR"
zip -rqX "$ZIP_PATH" altrelay/

# Sanity check: the main plugin file must be in the archive.
if ! unzip -l "$ZIP_PATH" | grep -q "altrelay/altrelay.php"; then
    echo "Error: altrelay/altrelay.php is missing from the archive." >&2
    exit 1
fi

SIZE=$(du -k "$ZIP_PATH" | cut -f1)
COUNT=$(find "$STAGE_DIR" -type f | wc -l | tr -d ' ')
echo "Staged $COUNT files."
echo "Created dist/altrelay.zip ($SIZE KB)"
