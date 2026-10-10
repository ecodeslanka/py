# Cheque Issue Feature Patch — `return_cheques.php`

## Files in this package

| File | Purpose |
|------|---------|
| `return_cheques_issue.patch` | Unified diff patch |
| `save_cheque_issue.php` | **New file** — backend AJAX handler |
| `apply_patch.sh` | Apply the patch |
| `reverse_patch.sh` | Reverse / remove the patch |
| `PATCH_README.md` | This file |

---

## What this patch adds

- **Issue Cheques button** — select returned cheques with checkboxes, stage them, issue to SR or CC
- **Issue History button** — searchable history of all cheque issues with view/delete
- **Issue detail modal** — view all cheques in an issue, mark returned, re-issue, remove
- **Two new DB tables** created automatically: `cheque_issues`, `cheque_issue_items`

---

## How to apply

### Step 1 — Deploy the new backend file
```bash
cp save_cheque_issue.php /path/to/your/project/
```

### Step 2 — Apply the patch
```bash
# From your project directory (where return_cheques.php lives):
bash apply_patch.sh return_cheques.php

# Or specify full path:
bash apply_patch.sh /var/www/html/myapp/return_cheques.php
```

### Step 3 — Verify
Open `return_cheques.php` in your browser. You should see two new buttons:
- **Issue Cheques** (indigo)
- **Issue History** (dark navy)

---

## How to reverse (remove the feature)

```bash
bash reverse_patch.sh return_cheques.php
```

Or restore from the `.orig` backup that `apply_patch.sh` creates automatically:
```bash
cp return_cheques.php.orig_YYYYMMDD_HHMMSS return_cheques.php
```

---

## Manual application (if patch command fails)

If your `return_cheques.php` differs from the version this patch was built against,
apply the 3 hunks manually using `return_cheques_additions.php` as a reference:

**Hunk 1** — Find the `/* NORMAL PAGE */` comment block. Paste the `issue_persons` AJAX
handler (lines 15–38 of `return_cheques_additions.php`) immediately before it.

**Hunk 2** — Find the Export Excel `</button>` line. Paste the two new buttons
(the `openIssueDrawer` and `openHistoryDrawer` buttons) immediately after it.

**Hunk 3** — Find `<?php include 'footer.php'; ?>` at the very end. Paste all modals
and `<script>` JS blocks immediately before that line.

---

## Technical notes

- The patch uses `--fuzz=3` (relaxed to `--fuzz=10` automatically if needed)
- Pure additions only — no existing lines are modified or deleted
- Reverse is safe: `patch -p0 -R` cleanly removes all additions
- The checkbox column in the main table is injected via `MutationObserver` — no
  changes to the existing `renderRows()` JS function are required
