#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
#  apply_patch.sh  —  Apply cheque issue feature to return_cheques.php
#  Usage:  bash apply_patch.sh [path/to/return_cheques.php]
# ═══════════════════════════════════════════════════════════════
set -e

FILE="${1:-return_cheques.php}"
PATCH="return_cheques_issue.patch"

if [ ! -f "$FILE" ]; then
    echo "✗ Error: '$FILE' not found."
    echo "  Usage: bash apply_patch.sh [path/to/return_cheques.php]"
    exit 1
fi

if [ ! -f "$PATCH" ]; then
    echo "✗ Error: '$PATCH' not found. Place it in the same directory."
    exit 1
fi

# Check patch is not already applied
if grep -q "openIssueDrawer" "$FILE" 2>/dev/null; then
    echo "⚠  Patch appears already applied (openIssueDrawer found in $FILE)."
    echo "   Run reverse_patch.sh first if you want to re-apply."
    exit 1
fi

# Dry-run first
echo "→ Checking patch compatibility..."
if ! patch --dry-run -p0 --fuzz=3 "$FILE" < "$PATCH" > /dev/null 2>&1; then
    echo "✗ Dry-run failed. Your return_cheques.php may differ from expected."
    echo "  Attempting fuzzy match..."
    if ! patch --dry-run -p0 --fuzz=10 "$FILE" < "$PATCH" > /dev/null 2>&1; then
        echo "✗ Patch incompatible. Apply manually using return_cheques_additions.php instructions."
        exit 1
    fi
    FUZZ="--fuzz=10"
else
    FUZZ="--fuzz=3"
fi

# Backup original
BACKUP="${FILE}.orig_$(date +%Y%m%d_%H%M%S)"
cp "$FILE" "$BACKUP"
echo "✓ Backup created: $BACKUP"

# Apply
patch -p0 $FUZZ "$FILE" < "$PATCH"
echo ""
echo "✓ Patch applied successfully to $FILE"
echo "  Also deploy save_cheque_issue.php to your project root."
echo ""
echo "  To reverse:  bash reverse_patch.sh $FILE"
echo "  To restore:  cp $BACKUP $FILE"
