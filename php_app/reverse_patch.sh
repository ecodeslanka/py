#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
#  reverse_patch.sh  —  Remove cheque issue feature from return_cheques.php
#  Usage:  bash reverse_patch.sh [path/to/return_cheques.php]
# ═══════════════════════════════════════════════════════════════
set -e

FILE="${1:-return_cheques.php}"
PATCH="return_cheques_issue.patch"

if [ ! -f "$FILE" ]; then
    echo "✗ Error: '$FILE' not found."
    exit 1
fi
if [ ! -f "$PATCH" ]; then
    echo "✗ Error: '$PATCH' not found."
    exit 1
fi

# Check patch has actually been applied
if ! grep -q "openIssueDrawer" "$FILE" 2>/dev/null; then
    echo "⚠  Patch does not appear to be applied (openIssueDrawer not found in $FILE)."
    exit 1
fi

# Dry-run reverse
echo "→ Checking reverse patch compatibility..."
if ! patch --dry-run -p0 -R --fuzz=3 "$FILE" < "$PATCH" > /dev/null 2>&1; then
    echo "✗ Reverse dry-run failed. Attempting fuzzy..."
    if ! patch --dry-run -p0 -R --fuzz=10 "$FILE" < "$PATCH" > /dev/null 2>&1; then
        echo "✗ Cannot auto-reverse. Restore from your .orig backup instead:"
        ls "${FILE}.orig_"* 2>/dev/null | tail -5 || echo "  (no .orig backups found)"
        exit 1
    fi
    FUZZ="--fuzz=10"
else
    FUZZ="--fuzz=3"
fi

# Backup current patched version
BACKUP="${FILE}.patched_$(date +%Y%m%d_%H%M%S)"
cp "$FILE" "$BACKUP"
echo "✓ Backup of patched version: $BACKUP"

# Reverse
patch -p0 -R $FUZZ "$FILE" < "$PATCH"
echo ""
echo "✓ Patch reversed. $FILE restored to original."
