<?php
/*
 * ═══════════════════════════════════════════════════════════════════
 *  RETURN_CHEQUES.PHP  —  ISSUE ADDITIONS
 *  Instructions: Paste each section into return_cheques.php at the
 *  marked injection point.
 * ═══════════════════════════════════════════════════════════════════
 *
 * INJECTION 1 of 3:
 *   Paste this block just BEFORE the comment:
 *     "NORMAL PAGE"  (the line that says:  include 'config.php';  )
 *
 * ───────────────────────────────────────────────────────────────────
 */

/* ══════════════════════════════════════════════════════
   AJAX — get CC/SR persons + employees for issue modal
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'issue_persons') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');
    $cc_r = mysqli_query($conn, "SELECT DISTINCT delivery_person AS code, delivery_person AS label
        FROM loading_summary_import_details
        WHERE delivery_person IS NOT NULL AND delivery_person <> ''
        ORDER BY delivery_person");
    $cc = [];
    if ($cc_r) while ($r = mysqli_fetch_assoc($cc_r)) $cc[] = $r;
    $sr_r = mysqli_query($conn, "SELECT DISTINCT sr_code AS code, sr_code AS label FROM field_summary ORDER BY sr_code");
    $sr = [];
    if ($sr_r) while ($r = mysqli_fetch_assoc($sr_r)) $sr[] = $r;
    $emp_r = mysqli_query($conn, "SELECT e.id, e.employee_id, e.employee_full_name,
        COALESCE(d.designation_name,'') AS designation_name
        FROM employees e LEFT JOIN designations d ON d.id=e.designation_id
        WHERE e.active=1 ORDER BY e.employee_full_name");
    $emp = [];
    if ($emp_r) while ($r = mysqli_fetch_assoc($emp_r)) $emp[] = $r;
    echo json_encode(['success'=>true,'cc_persons'=>$cc,'sr_persons'=>$sr,'employees'=>$emp]);
    exit;
}

/*
 * ─ END INJECTION 1 ──────────────────────────────────────────────────
 *
 *
 * INJECTION 2 of 3:
 *   Find this line in the PAGE HEADER section:
 *     <button onclick="exportExcel()" class="btn btn-success btn-sm">
 *   Add the following button RIGHT AFTER the Export Excel </button> closing tag:
 *
 * ───────────────────────────────────────────────────────────────────
 */
?>
    <button onclick="openIssueDrawer()" class="btn btn-sm" style="background:#6366f1;color:#fff;display:inline-flex;align-items:center;gap:5px;">
        <i class="fa-solid fa-paper-plane"></i> Issue Cheques
    </button>
    <button onclick="openHistoryDrawer()" class="btn btn-sm" style="background:#1e1b4b;color:#fff;display:inline-flex;align-items:center;gap:5px;">
        <i class="fa-solid fa-clock-rotate-left"></i> Issue History
    </button>
<?php
/*
 * ─ END INJECTION 2 ──────────────────────────────────────────────────
 *
 *
 * INJECTION 3 of 3:
 *   In the main table <thead>, add a checkbox column as the FIRST <th>:
 *     <th style="width:32px;" class="tc no-print">
 *         <input type="checkbox" id="chqSelectAll" onchange="toggleChqSelectAll()" style="accent-color:#6366f1;">
 *     </th>
 *   Then in each tbody <tr> (in renderRows JS function), add a checkbox td at the start of the html string.
 *   See the JS section below for the updated renderRows snippet.
 *
 *   Then paste EVERYTHING below (from the "ISSUE + HISTORY MODALS" comment)
 *   just BEFORE  <?php include 'footer.php'; ?>
 *
 * ───────────────────────────────────────────────────────────────────
 */
?>

<!-- ══════════════════════════════════════════════════════════════
     CHEQUE ISSUE DRAWER  (slide-in from right)
══════════════════════════════════════════════════════════════════ -->
<div id="issueDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeIssueDrawer()"></div>
<div id="issueDrawer" style="position:fixed;right:0;top:0;bottom:0;width:480px;max-width:96vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:16px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-paper-plane" style="color:#a5b4fc;"></i>
            Issue Returned Cheques
            <span id="issDrawerCount" style="background:rgba(255,255,255,.15);padding:1px 10px;border-radius:10px;font-size:12px;">0 selected</span>
        </div>
        <button onclick="closeIssueDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f0f0f0;flex-shrink:0;">
        <div style="padding:12px 16px;text-align:center;border-right:1px solid #f0f0f0;">
            <div id="issDrawerStatCount" style="font-size:20px;font-weight:800;color:#6366f1;">0</div>
            <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Cheques</div>
        </div>
        <div style="padding:12px 16px;text-align:center;">
            <div id="issDrawerStatAmt" style="font-size:20px;font-weight:800;color:#dc2626;">Rs. 0.00</div>
            <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;">Total Amount</div>
        </div>
    </div>
    <div id="issDrawerList" style="flex:1;overflow-y:auto;padding:8px 0;">
        <div id="issDrawerEmpty" style="text-align:center;padding:50px 20px;color:#9ca3af;">
            <i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
            <p style="font-size:13px;color:#6b7280;margin:0 0 6px;">No cheques selected</p>
            <small>Check rows in the table then click "Issue Cheques"</small>
        </div>
    </div>
    <div style="padding:14px 16px;border-top:2px solid #f0f0f0;display:flex;gap:8px;background:#fafafa;flex-shrink:0;">
        <button onclick="clearIssueSelection()" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center;">
            <i class="fa-solid fa-trash"></i> Clear
        </button>
        <button id="issDrawerProceedBtn" onclick="openIssueConfirmModal()" disabled
                style="flex:2;background:#6366f1;color:#fff;border:none;border-radius:7px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;opacity:.5;">
            <i class="fa-solid fa-paper-plane"></i> Process &amp; Issue (<span id="issDrawerProceedCount">0</span>)
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ISSUE CONFIRM MODAL
══════════════════════════════════════════════════════════════════ -->
<div id="issueConfirmBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10020;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:680px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.35);">
    <div style="padding:16px 22px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;justify-content:space-between;background:#f5f3ff;">
        <div style="font-size:16px;font-weight:800;color:#3730a3;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-paper-plane" style="color:#6366f1;"></i> Confirm &amp; Issue Cheques
        </div>
        <button onclick="closeIssueConfirmModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;">&#x2715;</button>
    </div>
    <div style="padding:22px;">
        <!-- Summary strip -->
        <div style="background:#f8fafc;border:1px solid #e5e5e5;border-radius:9px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="display:flex;flex-direction:column;gap:2px;">
                <div style="font-size:16px;font-weight:800;color:#6366f1;" id="icmCount">0</div>
                <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Cheques</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;">
                <div style="font-size:16px;font-weight:800;color:#dc2626;" id="icmTotal">Rs. 0.00</div>
                <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div>
            </div>
        </div>
        <!-- Row 1: date + type toggle -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-calendar-day"></i> Issue Date *</label>
                <input type="date" id="icmDate" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;outline:none;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-user-tie"></i> Issue To Type *</label>
                <div style="display:flex;border:1px solid #e5e5e5;border-radius:7px;overflow:hidden;">
                    <button type="button" class="icm-type-btn sr" data-type="SR" onclick="icmSetType('SR')"
                        style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;">
                        <i class="fa-solid fa-id-badge"></i> SR
                    </button>
                    <button type="button" class="icm-type-btn cc" data-type="CC" onclick="icmSetType('CC')"
                        style="flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#f9fafb;color:#6b7280;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;">
                        <i class="fa-solid fa-wallet"></i> CC
                    </button>
                </div>
            </div>
        </div>
        <!-- SR section -->
        <div id="icmSrSection" style="border:1px solid #6366f1;border-radius:9px;padding:16px;margin-bottom:14px;background:#faf5ff;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span style="background:#6366f1;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">SR</span>
                Select Sales Representative
            </div>
            <select id="icmSrSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmSrCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmSrEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <!-- CC section -->
        <div id="icmCcSection" style="display:none;border:1px solid #d97706;border-radius:9px;padding:16px;margin-bottom:14px;background:#fffbeb;">
            <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span style="background:#d97706;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;">CC</span>
                Select Delivery Person
            </div>
            <select id="icmCcSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            <div id="icmCcCard" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff;border:1px solid #e5e5e5;font-size:12px;color:#374151;"></div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;">
                <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;"><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <select id="icmEmpSelect" style="width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;"></select>
            </div>
        </div>
        <!-- Notes -->
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><i class="fa-solid fa-note-sticky"></i> Notes (optional)</label>
            <textarea id="icmNotes" rows="2" style="border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;resize:vertical;" placeholder="Any notes…"></textarea>
        </div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;">
        <button onclick="closeIssueConfirmModal()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
        <button id="icmSaveBtn" onclick="saveChequelssue()" style="padding:9px 22px;border-radius:6px;border:none;background:#6366f1;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue
        </button>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ISSUE HISTORY DRAWER
══════════════════════════════════════════════════════════════════ -->
<div id="histDrawerBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10010;" onclick="closeHistoryDrawer()"></div>
<div id="histDrawer" style="position:fixed;right:0;top:0;bottom:0;width:780px;max-width:98vw;background:#fff;z-index:10011;display:flex;flex-direction:column;box-shadow:-6px 0 40px rgba(0,0,0,.2);transform:translateX(110%);transition:transform .32s cubic-bezier(.4,0,.2,1);">
    <div style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-clock-rotate-left" style="color:#a5b4fc;"></i>
            Cheque Issue History
        </div>
        <button onclick="closeHistoryDrawer()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <!-- Filters -->
    <div style="padding:10px 16px;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex-shrink:0;background:#fafafa;">
        <input type="text" id="histSearch" placeholder="Search code, person…"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;width:180px;"
            oninput="histFetchDebounced()">
        <select id="histTypeFilter"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;"
            onchange="histFetch()">
            <option value="">All Types</option>
            <option value="SR">SR</option>
            <option value="CC">CC</option>
        </select>
        <input type="date" id="histDateFilter"
            style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;"
            onchange="histFetch()">
        <button onclick="histClearFilters()" style="border:1px solid #e5e5e5;border-radius:7px;padding:6px 10px;font-size:11px;background:#fff;color:#6b7280;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </button>
    </div>
    <div id="histBody" style="flex:1;overflow-y:auto;padding:0;">
        <div id="histLoading" style="text-align:center;padding:50px 20px;color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i>
            <p style="font-size:13px;margin:0;">Loading…</p>
        </div>
    </div>
    <div id="histPager" style="padding:8px 16px;border-top:1px solid #f0f0f0;display:flex;justify-content:center;gap:6px;flex-shrink:0;background:#fafafa;"></div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ISSUE DETAIL MODAL  (view items of an issue)
══════════════════════════════════════════════════════════════════ -->
<div id="issDetailBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10030;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:14px;width:860px;max-width:98vw;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);overflow:hidden;">
    <div id="issDetailHeader" style="padding:14px 20px;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="font-size:14px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-file-invoice"></i> Issue Details
            </div>
            <span id="issDetailCode" style="font-family:monospace;background:rgba(255,255,255,.15);padding:3px 12px;border-radius:8px;font-size:13px;color:#e0e7ff;"></span>
            <span id="issDetailPerson" style="font-size:11px;color:#c7d2fe;"></span>
            <span id="issDetailDate" style="font-size:11px;color:#a5b4fc;"></span>
        </div>
        <button onclick="closeIssDetail()" style="background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;">&#x2715;</button>
    </div>
    <div id="issDetailBody" style="flex:1;overflow-y:auto;padding:16px 20px;">
        <div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div>
    </div>
    <div style="padding:12px 20px;border-top:2px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;flex-shrink:0;">
        <button onclick="closeIssDetail()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-xmark"></i> Close
        </button>
    </div>
</div>
</div>

<!-- DELETE ISSUE CONFIRM -->
<div id="issDeleteBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10040;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:14px;width:420px;max-width:95vw;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">
    <div style="background:#dc2626;padding:16px 20px;display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-triangle-exclamation" style="color:#fff;font-size:20px;"></i>
        <span style="color:#fff;font-size:15px;font-weight:800;">Delete Issue</span>
    </div>
    <div style="padding:22px 20px;">
        <p style="font-size:13px;color:#374151;margin:0 0 8px;">You are about to permanently delete:</p>
        <div id="issDeleteCode" style="font-family:monospace;font-size:14px;font-weight:800;color:#dc2626;background:#fee2e2;padding:6px 12px;border-radius:7px;display:inline-block;margin:6px 0;"></div>
        <p style="margin-top:8px;font-size:13px;color:#374151;">This will remove the issue and all <strong id="issDeleteCount"></strong> associated cheque(s).</p>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;margin-top:10px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This action <strong>cannot be undone</strong>.</span>
        </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;">
        <button onclick="closeDeleteIssue()" style="padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button id="issDeleteConfirmBtn" onclick="executeDeleteIssue()"
            style="padding:8px 16px;border-radius:6px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;">
            <i class="fa-solid fa-trash"></i> Yes, Delete
        </button>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT — Cheque Issue System
══════════════════════════════════════════════════════════════════ -->
<script>
/* ── state ── */
let CHQ_SELECTED = {};     /* chequeId → {cheque_no, customer_name, amount, bank_name, cheque_date} */
let ICM_TYPE     = 'SR';
let ICM_PERSONS  = {cc:[], sr:[], emp:[]};
let HIST_PAGE    = 1;
let HIST_TIMER   = null;
let ISS_DEL_ID   = null;
let ISS_DEL_CODE = null;

/* ── safe fetch JSON ── */
function ciFetch(url, opts) {
    return fetch(url, opts).then(r => r.text()).then(text => {
        try { return JSON.parse(text); }
        catch(e) { throw new Error('Server error: ' + text.replace(/<[^>]*>/g,'').substring(0,200)); }
    });
}

/* ── HTML escape ── */
function ciEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ════════════════════════════════════
   CHECKBOX COLUMN IN MAIN TABLE
   Add this to renderRows() in the existing JS,
   inserting a checkbox td at the start of each row.
   ════════════════════════════════════ */

/* Patch renderRows to inject checkboxes */
(function patchRenderRows() {
    const origBuildHtml = window._origBuildHtml;  /* no-op if already patched */
})();

/* Override the data-table row rendering to add checkboxes.
   We hook into tbody innerHTML setter via MutationObserver. */
const _ciObserver = new MutationObserver(() => {
    document.querySelectorAll('#mainTbody tr[data-id]:not([data-ci-cb])').forEach(tr => {
        tr.setAttribute('data-ci-cb', '1');
        const cid   = tr.dataset.id;
        const amt   = parseFloat(tr.querySelector('.amt-cell')?.textContent?.replace(/Rs\.?\s*/gi,'').replace(/,/g,'') || 0);
        const chqNo = tr.querySelector('.mono')?.textContent?.trim() || '';
        const cust  = tr.querySelector('.cust-sub')?.textContent?.trim() || '';
        const bankEl= tr.querySelectorAll('td')[6];
        const bank  = bankEl?.querySelector('div')?.textContent?.trim() || '';
        /* insert checkbox td at start */
        const cbTd = document.createElement('td');
        cbTd.className = 'tc no-print';
        cbTd.style.cssText = 'width:32px;vertical-align:middle;';
        cbTd.innerHTML = `<input type="checkbox" class="ci-cb" data-id="${cid}" data-chqno="${ciEsc(chqNo)}" data-cust="${ciEsc(cust)}" data-amt="${amt}" data-bank="${ciEsc(bank)}" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;" onchange="ciOnCheck(this)">`;
        tr.insertBefore(cbTd, tr.firstChild);
        /* restore checked state if already in CHQ_SELECTED */
        if (CHQ_SELECTED[cid]) cbTd.querySelector('input').checked = true;
    });
});
_ciObserver.observe(document.getElementById('mainTbody'), {childList:true, subtree:false});

/* Also patch the header to add cb column */
document.addEventListener('DOMContentLoaded', () => {
    const thead = document.querySelector('#mainTable thead tr');
    if (thead && !thead.querySelector('[data-ci-hdr]')) {
        const th = document.createElement('th');
        th.setAttribute('data-ci-hdr','1');
        th.className = 'tc no-print';
        th.style.width = '32px';
        th.innerHTML = `<input type="checkbox" id="ciSelAll" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;" onchange="ciToggleAll()" title="Select all visible">`;
        thead.insertBefore(th, thead.firstChild);
    }
});

function ciOnCheck(cb) {
    const cid = cb.dataset.id;
    if (cb.checked) {
        CHQ_SELECTED[cid] = {
            cheque_no:     cb.dataset.chqno,
            customer_name: cb.dataset.cust,
            amount:        parseFloat(cb.dataset.amt),
            bank_name:     cb.dataset.bank,
        };
    } else {
        delete CHQ_SELECTED[cid];
    }
    refreshIssueDrawerUI();
}

function ciToggleAll() {
    const all = document.getElementById('ciSelAll')?.checked;
    document.querySelectorAll('#mainTbody .ci-cb').forEach(cb => {
        cb.checked = !!all;
        ciOnCheck(cb);
    });
}

/* ════════════════════════════════════
   ISSUE DRAWER
   ════════════════════════════════════ */
function openIssueDrawer() {
    refreshIssueDrawerUI();
    const dr = document.getElementById('issueDrawer');
    dr.style.transform = 'translateX(0)';
    document.getElementById('issueDrawerBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeIssueDrawer() {
    document.getElementById('issueDrawer').style.transform = 'translateX(110%)';
    document.getElementById('issueDrawerBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}

function refreshIssueDrawerUI() {
    const ids  = Object.keys(CHQ_SELECTED);
    const count= ids.length;
    const total= ids.reduce((s,k) => s + (CHQ_SELECTED[k].amount || 0), 0);

    document.getElementById('issDrawerCount').textContent      = count + ' selected';
    document.getElementById('issDrawerStatCount').textContent  = count;
    document.getElementById('issDrawerStatAmt').textContent    = 'Rs. ' + total.toFixed(2);
    document.getElementById('issDrawerProceedCount').textContent = count;
    const btn = document.getElementById('issDrawerProceedBtn');
    btn.disabled = count === 0;
    btn.style.opacity = count > 0 ? '1' : '.5';
    btn.style.cursor  = count > 0 ? 'pointer' : 'not-allowed';

    const list  = document.getElementById('issDrawerList');
    const empty = document.getElementById('issDrawerEmpty');
    list.querySelectorAll('.iss-dl-item').forEach(el => el.remove());
    if (!count) { empty.style.display = ''; return; }
    empty.style.display = 'none';
    ids.forEach(cid => {
        const item = CHQ_SELECTED[cid];
        const div  = document.createElement('div');
        div.className = 'iss-dl-item';
        div.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid #f9fafb;';
        div.innerHTML = `
            <span style="font-family:monospace;font-size:12px;font-weight:700;color:#991b1b;min-width:90px;">${ciEsc(item.cheque_no)}</span>
            <span style="flex:1;font-size:12px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${ciEsc(item.customer_name)}">${ciEsc(item.customer_name)}</span>
            <span style="font-size:10px;color:#6b7280;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${ciEsc(item.bank_name)}</span>
            <span style="font-size:12px;font-weight:800;color:#dc2626;min-width:80px;text-align:right;">Rs.&nbsp;${item.amount.toFixed(2)}</span>
            <button onclick="ciRemove('${cid}')" style="background:none;border:none;color:#d1d5db;cursor:pointer;padding:3px;border-radius:4px;font-size:12px;" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>`;
        list.appendChild(div);
    });
}

function ciRemove(cid) {
    delete CHQ_SELECTED[cid];
    const cb = document.querySelector(`.ci-cb[data-id="${cid}"]`);
    if (cb) cb.checked = false;
    refreshIssueDrawerUI();
    if (!Object.keys(CHQ_SELECTED).length) closeIssueDrawer();
}

function clearIssueSelection() {
    CHQ_SELECTED = {};
    document.querySelectorAll('.ci-cb').forEach(cb => cb.checked = false);
    const sa = document.getElementById('ciSelAll');
    if (sa) sa.checked = false;
    refreshIssueDrawerUI();
    closeIssueDrawer();
}

/* ════════════════════════════════════
   ISSUE CONFIRM MODAL
   ════════════════════════════════════ */
async function openIssueConfirmModal() {
    const ids = Object.keys(CHQ_SELECTED);
    if (!ids.length) { showToast('No cheques selected','err'); return; }

    /* load persons if not yet loaded */
    if (!ICM_PERSONS.cc.length && !ICM_PERSONS.sr.length) {
        try {
            const d = await ciFetch('return_cheques.php?ajax=issue_persons');
            if (d.success) { ICM_PERSONS.cc = d.cc_persons; ICM_PERSONS.sr = d.sr_persons; ICM_PERSONS.emp = d.employees; }
        } catch(e) { showToast('Could not load persons: '+e.message,'err'); return; }
    }

    /* populate selects */
    function mkOpts(items, placeholder) {
        let o = `<option value="">— ${placeholder} —</option>`;
        items.forEach(item => { o += `<option value="${ciEsc(item.code)}">${ciEsc(item.code)}${item.label && item.label !== item.code ? ' — '+ciEsc(item.label) : ''}</option>`; });
        return o;
    }
    document.getElementById('icmSrSelect').innerHTML  = mkOpts(ICM_PERSONS.sr,  'Select SR Code');
    document.getElementById('icmCcSelect').innerHTML  = mkOpts(ICM_PERSONS.cc,  'Select Delivery Person');
    const empPlaceholder = '— Select Employee (optional) —';
    const empOpts = `<option value="">${empPlaceholder}</option>` +
        ICM_PERSONS.emp.map(e => `<option value="${e.id}">${ciEsc(e.employee_id)} — ${ciEsc(e.employee_full_name)}${e.designation_name?' ('+ciEsc(e.designation_name)+')':''}</option>`).join('');
    document.getElementById('icmSrEmpSelect').innerHTML = empOpts;
    document.getElementById('icmEmpSelect').innerHTML   = empOpts;

    /* bind person card preview */
    ['icmSrSelect','icmCcSelect'].forEach(selId => {
        const sel = document.getElementById(selId);
        sel.onchange = function() {
            const cardId = selId === 'icmSrSelect' ? 'icmSrCard' : 'icmCcCard';
            const card   = document.getElementById(cardId);
            if (this.value) {
                card.style.display = 'flex';
                card.innerHTML = `<span style="width:32px;height:32px;border-radius:50%;background:${selId==='icmSrSelect'?'#ede9fe':'#fef3c7'};color:${selId==='icmSrSelect'?'#6366f1':'#d97706'};display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;"><i class="fa-solid ${selId==='icmSrSelect'?'fa-id-badge':'fa-wallet'}"></i></span><div><div style="font-weight:700;color:#1f2937;font-size:13px;">${ciEsc(this.value)}</div><div style="font-size:11px;color:#9ca3af;">${selId==='icmSrSelect'?'Sales Representative':'Delivery Person (CC)'}</div></div>`;
            } else { card.style.display = 'none'; }
        };
    });

    const total = ids.reduce((s,k) => s + (CHQ_SELECTED[k].amount || 0), 0);
    document.getElementById('icmCount').textContent = ids.length;
    document.getElementById('icmTotal').textContent = 'Rs. ' + total.toFixed(2);
    document.getElementById('icmDate').value  = new Date().toISOString().split('T')[0];
    document.getElementById('icmNotes').value = '';
    document.getElementById('icmSrCard').style.display = 'none';
    document.getElementById('icmCcCard').style.display = 'none';

    icmSetType('SR');  /* default SR */

    const bd = document.getElementById('issueConfirmBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeIssueConfirmModal() {
    document.getElementById('issueConfirmBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}

function icmSetType(type) {
    ICM_TYPE = type;
    document.querySelectorAll('.icm-type-btn').forEach(b => {
        const isActive = b.dataset.type === type;
        b.style.background = isActive ? (type==='SR'?'#6366f1':'#d97706') : '#f9fafb';
        b.style.color       = isActive ? '#fff' : '#6b7280';
    });
    document.getElementById('icmSrSection').style.display = type==='SR' ? '' : 'none';
    document.getElementById('icmCcSection').style.display = type==='CC' ? '' : 'none';
}

async function saveChequelssue() {
    const ids  = Object.keys(CHQ_SELECTED);
    const date = document.getElementById('icmDate').value;
    const notes= document.getElementById('icmNotes').value.trim();
    if (!date)      { showToast('Please select issue date','err'); return; }
    if (!ids.length){ showToast('No cheques selected','err'); return; }

    let personCode = '', personName = '', employeeId = '';
    if (ICM_TYPE === 'SR') {
        personCode = document.getElementById('icmSrSelect').value;
        if (!personCode) { showToast('Please select an SR','err'); return; }
        personName = personCode;
        employeeId = document.getElementById('icmSrEmpSelect').value || '';
    } else {
        personCode = document.getElementById('icmCcSelect').value;
        if (!personCode) { showToast('Please select a Delivery Person (CC)','err'); return; }
        personName = personCode;
        employeeId = document.getElementById('icmEmpSelect').value || '';
    }

    const btn = document.getElementById('icmSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('action',      'save_issue');
    fd.append('issue_date',  date);
    fd.append('person_type', ICM_TYPE);
    fd.append('person_code', personCode);
    fd.append('person_name', personName);
    if (employeeId) fd.append('employee_id', employeeId);
    fd.append('notes', notes);
    ids.forEach(id => fd.append('cheque_ids[]', id));

    try {
        const data = await ciFetch('save_cheque_issue.php', {method:'POST', body:fd});
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';
        if (data.success) {
            showToast('✓ Issue saved: ' + data.issue_code, 'ok');
            closeIssueConfirmModal();
            closeIssueDrawer();
            clearIssueSelection();
        } else {
            showToast(data.error || 'Save failed', 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue';
        showToast('Network error: ' + e.message, 'err');
    }
}

/* ════════════════════════════════════
   HISTORY DRAWER
   ════════════════════════════════════ */
function openHistoryDrawer() {
    const dr = document.getElementById('histDrawer');
    dr.style.transform = 'translateX(0)';
    document.getElementById('histDrawerBackdrop').style.display = 'block';
    document.body.style.overflow = 'hidden';
    HIST_PAGE = 1;
    histFetch();
}
function closeHistoryDrawer() {
    document.getElementById('histDrawer').style.transform = 'translateX(110%)';
    document.getElementById('histDrawerBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}

function histClearFilters() {
    document.getElementById('histSearch').value    = '';
    document.getElementById('histTypeFilter').value = '';
    document.getElementById('histDateFilter').value = '';
    HIST_PAGE = 1;
    histFetch();
}

function histFetchDebounced() {
    clearTimeout(HIST_TIMER);
    HIST_TIMER = setTimeout(() => { HIST_PAGE=1; histFetch(); }, 350);
}

async function histFetch() {
    document.getElementById('histBody').innerHTML = '<div id="histLoading" style="text-align:center;padding:50px 20px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i><p style="font-size:13px;margin:0;">Loading…</p></div>';
    const q     = document.getElementById('histSearch').value.trim();
    const type  = document.getElementById('histTypeFilter').value;
    const date  = document.getElementById('histDateFilter').value;
    const params= new URLSearchParams({action:'load_history', page:HIST_PAGE, q, person_type:type, issue_date:date});
    try {
        const data = await ciFetch('save_cheque_issue.php?' + params.toString());
        if (!data.success) throw new Error(data.error||'Server error');
        histRender(data);
    } catch(e) {
        document.getElementById('histBody').innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${ciEsc(e.message)}</div>`;
    }
}

function histRender(data) {
    const rows  = data.rows || [];
    const body  = document.getElementById('histBody');
    const pager = document.getElementById('histPager');
    if (!rows.length) {
        body.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#9ca3af;"><i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i><p style="font-size:13px;">No issue records found.</p></div>';
        pager.innerHTML = ''; return;
    }
    let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
    html += '<thead><tr style="background:#1e1b4b;color:#e0e7ff;">'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Code</th>'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Date</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Type</th>'
        + '<th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;">Issued To</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Cheques</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Issued</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Returned</th>'
        + '<th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;">Amount</th>'
        + '<th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;">Actions</th>'
        + '</tr></thead><tbody>';

    rows.forEach(r => {
        const isCC     = r.person_type === 'CC';
        const pillCls  = isCC
            ? 'background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;'
            : 'background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;';
        const total_items   = parseInt(r.total_items)||0;
        const issued_cnt    = parseInt(r.issued_cnt)||0;
        const returned_cnt  = parseInt(r.returned_cnt)||0;
        const total_amt     = parseFloat(r.total_amount||0);

        html += `<tr id="hist-row-${r.id}" style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
            <td style="padding:8px 10px;"><span style="font-family:monospace;font-size:11px;font-weight:700;color:#4338ca;">${ciEsc(r.issue_code)}</span></td>
            <td style="padding:8px 10px;font-size:11px;color:#374151;white-space:nowrap;">${ciEsc(r.issue_date)}</td>
            <td style="padding:8px 10px;text-align:center;">
                <span style="${pillCls}">${ciEsc(r.person_type)}</span>
            </td>
            <td style="padding:8px 10px;">
                <div style="font-size:12px;font-weight:700;color:#1f2937;">${ciEsc(r.person_code)}</div>
                ${r.person_name && r.person_name !== r.person_code ? `<div style="font-size:10px;color:#6b7280;">${ciEsc(r.person_name)}</div>` : ''}
                ${r.emp_name ? `<div style="font-size:10px;color:#0891b2;"><i class="fa-solid fa-user-check" style="font-size:9px;"></i> ${ciEsc(r.emp_name)}${r.emp_desig?' ('+ciEsc(r.emp_desig)+')':''}</div>` : ''}
            </td>
            <td style="padding:8px 10px;text-align:center;font-weight:700;">${total_items}</td>
            <td style="padding:8px 10px;text-align:center;">
                ${issued_cnt > 0 ? `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> ${issued_cnt}</span>` : '<span style="color:#d1d5db;font-size:11px;">—</span>'}
            </td>
            <td style="padding:8px 10px;text-align:center;">
                ${returned_cnt > 0 ? `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> ${returned_cnt}</span>` : '<span style="color:#d1d5db;font-size:11px;">—</span>'}
            </td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:#dc2626;font-size:11px;">Rs.&nbsp;${total_amt.toFixed(2)}</td>
            <td style="padding:8px 10px;text-align:center;">
                <div style="display:flex;align-items:center;gap:4px;justify-content:center;">
                    <button style="background:#0ea5e9;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;"
                        onclick="openIssDetail(${r.id},'${ciEsc(r.issue_code)}','${ciEsc(r.person_type)}','${ciEsc(r.issue_date)}','${ciEsc(r.person_code)}','${ciEsc(r.person_name)}','${ciEsc(r.emp_name)}','${ciEsc(r.emp_desig)}')">
                        <i class="fa-solid fa-eye"></i> View
                    </button>
                    <button style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                        onclick="confirmDeleteIssue(${r.id},'${ciEsc(r.issue_code)}',${total_items})">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    body.innerHTML = html;

    /* pager */
    if (data.pages <= 1) { pager.innerHTML = ''; return; }
    let ph = '';
    for (let p = 1; p <= data.pages; p++) {
        const active = p === data.page;
        ph += `<button onclick="histGoPage(${p})" style="border:1.5px solid ${active?'#1e1b4b':'#e5e5e5'};background:${active?'#1e1b4b':'#fff'};color:${active?'#fff':'#374151'};border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;">${p}</button>`;
    }
    pager.innerHTML = ph;
}

function histGoPage(p) { HIST_PAGE = p; histFetch(); }

/* ════════════════════════════════════
   ISSUE DETAIL MODAL
   ════════════════════════════════════ */
function openIssDetail(issId, code, type, date, personCode, personName, empName, empDesig) {
    document.getElementById('issDetailCode').textContent   = code;
    document.getElementById('issDetailPerson').textContent = (type==='CC'?'CC:':'SR:') + ' ' + personCode + (personName&&personName!==personCode?' ('+personName+')':'');
    document.getElementById('issDetailDate').innerHTML     = '<i class="fa-solid fa-calendar-day" style="font-size:10px;"></i> ' + date;
    document.getElementById('issDetailBody').innerHTML     = '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i></div>';

    const bd = document.getElementById('issDetailBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    ciFetch('save_cheque_issue.php?action=load_items&issue_id=' + issId)
    .then(data => {
        if (!data.success) throw new Error(data.error||'Server error');
        renderIssDetailItems(issId, data.items);
    })
    .catch(e => {
        document.getElementById('issDetailBody').innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626;font-size:13px;"><i class="fa-solid fa-triangle-exclamation"></i> ${ciEsc(e.message)}</div>`;
    });
}

function closeIssDetail() {
    document.getElementById('issDetailBackdrop').style.display = 'none';
    document.body.style.overflow = '';
}

function renderIssDetailItems(issId, items) {
    if (!items.length) {
        document.getElementById('issDetailBody').innerHTML = '<div style="text-align:center;padding:50px;color:#9ca3af;font-size:13px;"><i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3;"></i> No items found.</div>';
        return;
    }
    let totAmt = 0, issuedCnt = 0, returnedCnt = 0;
    items.forEach(i => { totAmt += parseFloat(i.amount||0); if(i.status==='issued') issuedCnt++; else returnedCnt++; });

    let html = `<div style="display:flex;gap:12px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 16px;margin-bottom:14px;">
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#1f2937;">Rs.&nbsp;${totAmt.toFixed(2)}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total Amount</div></div>
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#d97706;">${issuedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Issued</div></div>
        <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-size:15px;font-weight:800;color:#16a34a;">${returnedCnt}</div><div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Returned</div></div>
    </div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr style="background:#1e1b4b;color:#e0e7ff;">
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">#</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Cheque No.</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Customer</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Bank</th>
        <th style="padding:8px;text-align:left;font-size:10px;font-weight:700;">Cheque Date</th>
        <th style="padding:8px;text-align:right;font-size:10px;font-weight:700;">Amount</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Status</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Updated</th>
        <th style="padding:8px;text-align:center;font-size:10px;font-weight:700;">Actions</th>
    </tr></thead><tbody>`;

    items.forEach((item, i) => {
        const isIssued   = item.status === 'issued';
        const isReturned = item.status === 'returned';
        const settled    = parseInt(item.return_settled||0);
        const rowBg      = isReturned ? '#f9fafb' : '';

        let statusBadge = '';
        if (isIssued)   statusBadge = `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;
        else            statusBadge = `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;

        let actionCell = '';
        if (isIssued) {
            actionCell = `<button onclick="ciMarkReturned(${item.id}, this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;">
                <i class="fa-solid fa-rotate-left"></i> Return
            </button>
            <button onclick="ciRemoveItem(${item.id}, ${issId}, this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;margin-left:4px;">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        } else {
            actionCell = `<button onclick="ciReissueItem(${item.id}, this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;">
                <i class="fa-solid fa-rotate-right"></i> Re-issue
            </button>`;
        }

        html += `<tr id="iss-item-row-${item.id}" style="border-bottom:1px solid #f3f4f6;background:${rowBg};">
            <td style="padding:7px 8px;color:#9ca3af;font-size:11px;">${i+1}</td>
            <td style="padding:7px 8px;"><span style="font-family:monospace;font-weight:700;color:#991b1b;font-size:12px;">${ciEsc(item.cheque_no)}</span></td>
            <td style="padding:7px 8px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">${ciEsc(item.customer_name)}</td>
            <td style="padding:7px 8px;font-size:11px;"><div style="font-weight:600;">${ciEsc(item.bank_name||'')}</div>${item.branch_name?`<div style="color:#6b7280;font-size:10px;">${ciEsc(item.branch_name)}</div>`:''}</td>
            <td style="padding:7px 8px;font-size:11px;color:#374151;white-space:nowrap;">${ciEsc(item.cheque_date||'—')}</td>
            <td style="padding:7px 8px;text-align:right;font-weight:700;color:#dc2626;">Rs.&nbsp;${parseFloat(item.amount).toFixed(2)}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-status-${item.id}">${statusBadge}</td>
            <td style="padding:7px 8px;text-align:center;font-size:10px;color:#6b7280;" id="iss-item-upd-${item.id}">${item.returned_at||'—'}</td>
            <td style="padding:7px 8px;text-align:center;" id="iss-item-action-${item.id}">${actionCell}</td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    document.getElementById('issDetailBody').innerHTML = html;
}

/* ── item actions in detail modal ── */
function ciMarkReturned(itemId, btn) {
    if (!confirm('Mark this cheque as returned?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','mark_returned'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            showToast('Marked as returned ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) tr.style.background = '#f9fafb';
            const ss = document.getElementById('iss-item-status-'+itemId);
            if (ss) ss.innerHTML = `<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
            const ua = document.getElementById('iss-item-upd-'+itemId);
            if (ua) ua.textContent = new Date().toLocaleString('en-GB');
            const ac = document.getElementById('iss-item-action-'+itemId);
            if (ac) ac.innerHTML = `<button onclick="ciReissueItem(${itemId}, this)" style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-right"></i> Re-issue</button>`;
        } else {
            btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Return';
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return'; showToast(e.message,'err'); });
}

function ciReissueItem(itemId, btn) {
    if (!confirm('Re-issue this cheque?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','reissue_item'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            showToast('Re-issued ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) tr.style.background = '';
            const ss = document.getElementById('iss-item-status-'+itemId);
            if (ss) ss.innerHTML = `<span style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;
            const ua = document.getElementById('iss-item-upd-'+itemId);
            if (ua) ua.textContent = '—';
            const ac = document.getElementById('iss-item-action-'+itemId);
            if (ac) ac.innerHTML = `<button onclick="ciMarkReturned(${itemId}, this)" style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-rotate-left"></i> Return</button><button onclick="ciRemoveItem(${itemId}, 0, this)" style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;margin-left:4px;"><i class="fa-solid fa-trash"></i></button>`;
        } else {
            btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Re-issue';
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-issue'; showToast(e.message,'err'); });
}

function ciRemoveItem(itemId, issId, btn) {
    if (!confirm('Remove this cheque from the issue?')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action','remove_item'); fd.append('item_id', itemId);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        if (data.success) {
            showToast('Removed ✓', 'ok');
            const tr = document.getElementById('iss-item-row-'+itemId);
            if (tr) { tr.style.opacity='0'; tr.style.transition='opacity .3s'; setTimeout(()=>tr.remove(), 320); }
        } else {
            btn.disabled = false;
            showToast(data.error||'Failed', 'err');
        }
    })
    .catch(e => { btn.disabled=false; showToast(e.message,'err'); });
}

/* ════════════════════════════════════
   DELETE ISSUE
   ════════════════════════════════════ */
function confirmDeleteIssue(issId, issCode, billCount) {
    ISS_DEL_ID   = issId;
    ISS_DEL_CODE = issCode;
    document.getElementById('issDeleteCode').textContent  = issCode;
    document.getElementById('issDeleteCount').textContent = billCount;
    const bd = document.getElementById('issDeleteBackdrop');
    bd.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeDeleteIssue() {
    document.getElementById('issDeleteBackdrop').style.display = 'none';
    document.body.style.overflow = '';
    ISS_DEL_ID = null;
}
function executeDeleteIssue() {
    if (!ISS_DEL_ID) return;
    const btn = document.getElementById('issDeleteConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd = new FormData();
    fd.append('action','delete_issue'); fd.append('issue_id', ISS_DEL_ID);
    ciFetch('save_cheque_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
        if (data.success) {
            showToast('Issue ' + ISS_DEL_CODE + ' deleted', 'ok');
            closeDeleteIssue();
            /* remove from history list */
            const row = document.getElementById('hist-row-' + ISS_DEL_ID);
            if (row) { row.style.opacity='0'; row.style.transition='opacity .3s'; setTimeout(()=>row.remove(),320); }
            ISS_DEL_ID = null;
        } else {
            showToast(data.error||'Delete failed', 'err');
        }
    })
    .catch(e => {
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';
        showToast(e.message,'err');
    });
}

/* Keyboard close */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeIssueConfirmModal();
        closeIssDetail();
        closeDeleteIssue();
        closeHistoryDrawer();
        closeIssueDrawer();
    }
});
</script>
