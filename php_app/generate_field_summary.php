<?php
include 'config.php';
include 'header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
.fs-card{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:14px;margin-bottom:12px;}
.fs-title{font-size:13px;font-weight:700;color:#111;display:flex;align-items:center;gap:6px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f0f0f0;}
.fs-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.fs-field{display:flex;flex-direction:column;flex:1;min-width:150px;}
.fs-label{font-size:10px;font-weight:700;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.3px;}
.fs-input{height:34px;padding:0 9px;border:1px solid #d0d0d0;border-radius:4px;font-size:13px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box;}
.fs-input:focus{outline:none;border-color:#222;box-shadow:0 0 0 2px rgba(0,0,0,.05);}
.req{color:#e53935;}
.fs-input-wrap{position:relative;}
.fs-auto-badge{position:absolute;right:7px;top:50%;transform:translateY(-50%);font-size:9px;font-weight:700;background:#f0f0f0;border:1px solid #ddd;border-radius:3px;padding:1px 5px;color:#888;pointer-events:none;opacity:0;transition:opacity .2s;}
#field_summary_code[data-auto="true"]~.fs-auto-badge{opacity:1;}
#field_summary_code_dp[data-auto="true"]~.fs-auto-badge{opacity:1;}

/* select2 */
.select2-container{width:100%!important;}
.select2-container--default .select2-selection--single{height:34px;border:1px solid #d0d0d0;border-radius:4px;background:#fff;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:34px;padding-left:9px;font-size:13px;color:#222;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px;right:5px;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#222;box-shadow:0 0 0 2px rgba(0,0,0,.05);outline:none;}
.select2-dropdown{border:1px solid #d0d0d0;border-radius:4px;font-size:13px;font-family:inherit;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#111;}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #d0d0d0;border-radius:3px;padding:4px 8px;font-size:12px;}

/* dropdown option */
.emp-opt{display:flex;align-items:center;gap:6px;padding:1px 0;}
.emp-code-badge{font-size:10px;font-weight:700;background:#f0f0f0;border:1px solid #e0e0e0;border-radius:3px;padding:1px 5px;color:#444;white-space:nowrap;flex-shrink:0;font-family:monospace;}
.emp-name-text{font-size:13px;color:#111;}
.emp-desig-text{font-size:11px;color:#999;}

/* status */
.fs-status{font-size:10px;min-height:14px;margin-top:2px;line-height:1.3;}
.st-loading{color:#f59e0b;}
.st-ok{color:#22c55e;font-weight:700;}
.st-err{color:#e53935;}

/* info strip */
.info-strip{display:none;align-items:center;gap:8px;background:#f8f8f8;border:1px solid #e8e8e8;border-radius:4px;padding:5px 9px;margin-top:4px;}
.info-strip.show{display:flex;}
.strip-avatar{width:24px;height:24px;border-radius:50%;background:#e8e8e8;display:flex;align-items:center;justify-content:center;font-size:11px;color:#666;flex-shrink:0;}
.strip-body{flex:1;line-height:1.2;}
.strip-name{font-size:11px;font-weight:700;color:#111;}
.strip-meta{font-size:10px;color:#888;}
.strip-ok{font-size:10px;font-weight:700;color:#22c55e;white-space:nowrap;}
.strip-warn{font-size:10px;font-weight:700;color:#e65100;white-space:nowrap;}

/* actions */
.fs-actions{display:flex;gap:6px;align-items:center;padding-top:10px;border-top:1px solid #f0f0f0;margin-top:10px;}
.btn{display:inline-flex;align-items:center;gap:4px;padding:7px 13px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s,opacity .15s;line-height:1;}
.btn-primary{background:#111;color:#fff;}
.btn-primary:hover{background:#333;}
.btn-primary:disabled{opacity:.4;cursor:not-allowed;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #ddd;}
.btn-secondary:hover{background:#e8e8e8;}

/* alert */
.fs-alert{display:flex;align-items:center;gap:7px;padding:8px 12px;border-radius:4px;margin-bottom:10px;font-size:12px;}
.fs-alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.fs-alert-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* spinner */
.spin{display:inline-block;width:12px;height:12px;border:2px solid #eee;border-top-color:#555;border-radius:50%;animation:sp 1s linear infinite;vertical-align:middle;}
@keyframes sp{to{transform:rotate(360deg)}}

/* section divider */
.section-divider{display:flex;align-items:center;gap:10px;margin:18px 0 14px;}
.section-divider::before,.section-divider::after{content:'';flex:1;height:1px;background:#e0e0e0;}
.section-divider-label{font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap;}

/* section header badge */
.fs-section-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:8px;vertical-align:middle;}
.badge-sr{background:#e0f2fe;color:#0369a1;}
.badge-dp{background:#f0fdf4;color:#166534;}
</style>

<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-file-invoice"></i> Generate Field Summary</h2>
    <p class="page-subtitle">Create new field summary from loading summary data</p>
</div>

<!-- ─────────────────────────────────────────────────────────────────
     SECTION 1 — CREATE FIELD SUMMARY BY SR CODES
────────────────────────────────────────────────────────────────── -->

<div id="alertBox"></div>

<div class="fs-card">
    <div class="fs-title">
        <i class="fa-solid fa-barcode"></i>
        Create Field Summary by SR Codes
        <span class="fs-section-badge badge-sr">SR Code Based</span>
    </div>

    <form id="fsForm">

        <!-- Row 1: Date | SR Code | Field Summary Code -->
        <div class="fs-row">
            <div class="fs-field">
                <label class="fs-label">Delivery Date <span class="req">*</span></label>
                <input type="date" name="delivery_date" id="delivery_date" class="fs-input" required>
            </div>
            <div class="fs-field">
                <label class="fs-label">SR Code <span class="req">*</span></label>
                <select name="sr_code" id="sr_code" required>
                    <option value="">-- Select SR Code --</option>
                </select>
                <span class="fs-status" id="srStatus"></span>
            </div>
            <div class="fs-field">
                <label class="fs-label">Field Summary Code <span class="req">*</span></label>
                <div class="fs-input-wrap">
                    <input type="text" name="field_summary_code" id="field_summary_code"
                           class="fs-input" placeholder="Auto-filled" style="padding-right:50px;" required>
                    <span class="fs-auto-badge">AUTO</span>
                </div>
            </div>
        </div>

        <!-- Row 2: Delivery Person | Employee -->
        <div class="fs-row">

            <div class="fs-field">
                <label class="fs-label">
                    Delivery Person <span class="req">*</span>
                    <span id="dpSpinner" style="display:none;margin-left:3px;"><span class="spin"></span></span>
                </label>
                <select name="delivery_person" id="dpSelect" required>
                    <option value="">-- Select Delivery Person --</option>
                </select>
                <span class="fs-status" id="dpStatus"></span>
                <div class="info-strip" id="dpStrip">
                    <div class="strip-avatar"><i class="fa-solid fa-person-biking"></i></div>
                    <div class="strip-body">
                        <div class="strip-name" id="dpStripName"></div>
                        <div class="strip-meta">Delivery Person</div>
                    </div>
                    <span class="strip-ok"><i class="fa-solid fa-circle-check"></i> Selected</span>
                </div>
            </div>

            <div class="fs-field">
                <label class="fs-label">Employee <span class="req">*</span></label>
                <select name="employee_id" id="empSelect" required>
                    <option value="">-- Select Employee --</option>
                </select>
                <span class="fs-status" id="empStatus"></span>
                <div class="info-strip" id="empStrip">
                    <div class="strip-avatar"><i class="fa-solid fa-user"></i></div>
                    <div class="strip-body">
                        <div class="strip-name" id="empStripName"></div>
                        <div class="strip-meta" id="empStripMeta"></div>
                    </div>
                    <span class="strip-ok" id="empStripBadge"></span>
                </div>
            </div>

        </div>

        <!-- Actions -->
        <div class="fs-actions">
            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                <i class="fa-solid fa-check"></i> Create Field Summary
            </button>
            <button type="button" class="btn btn-secondary"
                    onclick="window.location.href='field_summary_list.php'">
                <i class="fa-solid fa-list"></i> View All
            </button>
        </div>

    </form>
</div>


<!-- ─────────────────────────────────────────────────────────────────
     SECTION 2 — CREATE FIELD SUMMARY BY DELIVERY PERSON
────────────────────────────────────────────────────────────────── -->

<div id="alertBoxDp"></div>

<div class="fs-card">
    <div class="fs-title">
        <i class="fa-solid fa-person-biking"></i>
        Create Field Summary by Delivery Person
        <span class="fs-section-badge badge-dp">Delivery Person Based</span>
    </div>

    <form id="fsFormDp">

        <!-- Row 1: Date | Delivery Person | Field Summary Code -->
        <div class="fs-row">
            <div class="fs-field">
                <label class="fs-label">Delivery Date <span class="req">*</span></label>
                <input type="date" name="delivery_date" id="delivery_date_dp" class="fs-input" required>
            </div>
            <div class="fs-field">
                <label class="fs-label">
                    Delivery Person <span class="req">*</span>
                    <span id="dpSpinnerDp" style="display:none;margin-left:3px;"><span class="spin"></span></span>
                </label>
                <select name="delivery_person" id="dpSelectDp" required>
                    <option value="">-- Select Delivery Person --</option>
                </select>
                <span class="fs-status" id="dpStatusDp"></span>
                <div class="info-strip" id="dpStripDp">
                    <div class="strip-avatar"><i class="fa-solid fa-person-biking"></i></div>
                    <div class="strip-body">
                        <div class="strip-name" id="dpStripNameDp"></div>
                        <div class="strip-meta">Delivery Person</div>
                    </div>
                    <span class="strip-ok"><i class="fa-solid fa-circle-check"></i> Selected</span>
                </div>
            </div>
            <div class="fs-field">
                <label class="fs-label">Field Summary Code <span class="req">*</span></label>
                <div class="fs-input-wrap">
                    <input type="text" name="field_summary_code" id="field_summary_code_dp"
                           class="fs-input" placeholder="Auto-filled" style="padding-right:50px;" required>
                    <span class="fs-auto-badge">AUTO</span>
                </div>
            </div>
        </div>

        <!-- Row 2: Employee -->
        <div class="fs-row">
            <div class="fs-field">
                <label class="fs-label">Employee <span class="req">*</span></label>
                <select name="employee_id" id="empSelectDp" required>
                    <option value="">-- Select Employee --</option>
                </select>
                <span class="fs-status" id="empStatusDp"></span>
                <div class="info-strip" id="empStripDp">
                    <div class="strip-avatar"><i class="fa-solid fa-user"></i></div>
                    <div class="strip-body">
                        <div class="strip-name" id="empStripNameDp"></div>
                        <div class="strip-meta" id="empStripMetaDp"></div>
                    </div>
                    <span class="strip-ok" id="empStripBadgeDp"></span>
                </div>
            </div>
            <!-- spacer to keep layout balanced -->
            <div class="fs-field" style="visibility:hidden;"></div>
            <div class="fs-field" style="visibility:hidden;"></div>
        </div>

        <!-- Actions -->
        <div class="fs-actions">
            <button type="submit" class="btn btn-primary" id="submitBtnDp" disabled>
                <i class="fa-solid fa-check"></i> Create Field Summary
            </button>
            <button type="button" class="btn btn-secondary"
                    onclick="window.location.href='field_summary_list.php'">
                <i class="fa-solid fa-list"></i> View All
            </button>
        </div>

    </form>
</div>


<script>
/* ══════════════════════════════════════════════════════════════════
   SHARED HELPERS
══════════════════════════════════════════════════════════════════ */

/* Select2 formatters for employee dropdown */
function fmtResult(opt) {
    if (!opt.id) return opt.text;
    var $el   = $(opt.element);
    var code  = $el.data('code')  || '';
    var desig = $el.data('desig') || '';
    var badge = code ? '<span class="emp-code-badge">' + code + '</span>' : '';
    return $('<span class="emp-opt">' + badge
        + '<span><span class="emp-name-text">' + opt.text + '</span>'
        + (desig ? ' <span class="emp-desig-text">· ' + desig + '</span>' : '')
        + '</span></span>');
}
function fmtSelection(opt) {
    if (!opt.id) return opt.text;
    var code = $(opt.element).data('code') || '';
    return code ? '[' + code + '] ' + opt.text : opt.text;
}

/* ── Init Select2s — Section 1 ── */
$('#sr_code').select2({placeholder:'-- Select SR Code --',allowClear:true,width:'100%'});
$('#dpSelect').select2({placeholder:'-- Select Delivery Person --',allowClear:true,width:'100%'});
$('#empSelect').select2({placeholder:'-- Select Employee --',allowClear:true,width:'100%',templateResult:fmtResult,templateSelection:fmtSelection});

/* ── Init Select2s — Section 2 ── */
$('#dpSelectDp').select2({placeholder:'-- Select Delivery Person --',allowClear:true,width:'100%'});
$('#empSelectDp').select2({placeholder:'-- Select Employee --',allowClear:true,width:'100%',templateResult:fmtResult,templateSelection:fmtSelection});

/* ══════════════════════════════════════════════════════════════════
   SECTION 1 — BY SR CODE
══════════════════════════════════════════════════════════════════ */

document.getElementById('delivery_date').valueAsDate = new Date();

/* Load delivery persons (global — used by Section 1 only) */
(function () {
    var spinner = document.getElementById('dpSpinner');
    var st      = document.getElementById('dpStatus');
    spinner.style.display = 'inline';
    st.textContent = '';

    fetch('get_delivery_persons.php')
    .then(function(r){ return r.json(); })
    .then(function(data) {
        spinner.style.display = 'none';
        $('#dpSelect').empty().append('<option value="">-- Select Delivery Person --</option>');
        if (data.success && data.persons && data.persons.length) {
            data.persons.forEach(function (name) {
                $('#dpSelect').append(new Option(name, name));
            });
            $('#dpSelect').trigger('change');
            st.innerHTML = '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.persons.length + ' person(s)</span>';
        } else {
            st.innerHTML = '<span class="st-err">No delivery persons found</span>';
        }
    })
    .catch(function() {
        document.getElementById('dpSpinner').style.display = 'none';
        document.getElementById('dpStatus').innerHTML =
            '<span class="st-err">Error loading delivery persons</span>';
    });
})();

/* Load employees (shared across both sections) */
function loadEmployeesInto(selectId, statusId) {
    var st = document.getElementById(statusId);
    st.innerHTML = '<span class="st-loading"><span class="spin"></span> Loading…</span>';

    fetch('get_all_employees.php')
    .then(function(r){ return r.json(); })
    .then(function(data) {
        $('#' + selectId).empty().append('<option value="">-- Select Employee --</option>');
        if (data.success && data.employees && data.employees.length) {
            data.employees.forEach(function (emp) {
                var opt = new Option(emp.employee_name, emp.id);
                $(opt).attr('data-code',  emp.employee_code || '')
                      .attr('data-desig', emp.designation   || '');
                $('#' + selectId).append(opt);
            });
            $('#' + selectId).trigger('change');
            st.innerHTML = '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.employees.length + ' employee(s)</span>';
        } else {
            st.innerHTML = '<span class="st-err">No employees found</span>';
        }
    })
    .catch(function() {
        document.getElementById(statusId).innerHTML =
            '<span class="st-err">Error loading employees</span>';
    });
}

loadEmployeesInto('empSelect',   'empStatus');
loadEmployeesInto('empSelectDp', 'empStatusDp');

/* Delivery person change — Section 1 */
$('#dpSelect').on('change', function () {
    var val   = $(this).val();
    var strip = document.getElementById('dpStrip');
    checkReady();
    if (val) {
        document.getElementById('dpStripName').textContent = val;
        strip.classList.add('show');
    } else {
        strip.classList.remove('show');
    }
});

/* Employee change — Section 1 */
$('#empSelect').on('change', function () {
    var val   = $(this).val();
    var strip = document.getElementById('empStrip');
    checkReady();
    if (val) {
        var $o = $(this).find('option:selected');
        document.getElementById('empStripName').textContent = $o.text();
        document.getElementById('empStripMeta').textContent =
            [$o.data('code'), $o.data('desig')].filter(Boolean).join(' · ') || 'Employee';
        document.getElementById('empStripBadge').innerHTML =
            '<i class="fa-solid fa-circle-check"></i> Selected';
        strip.classList.add('show');
    } else {
        strip.classList.remove('show');
    }
});

/* Auto-generate field summary code — Section 1 */
function genCode() {
    var sr  = $('#sr_code').val();
    var dt  = document.getElementById('delivery_date').value;
    var inp = document.getElementById('field_summary_code');
    if (sr && dt) {
        inp.value = dt.replace(/-/g, '') + sr;
        inp.setAttribute('data-auto', 'true');
    } else if (inp.getAttribute('data-auto') === 'true') {
        inp.value = '';
        inp.removeAttribute('data-auto');
    }
}
document.getElementById('field_summary_code').addEventListener('input', function () {
    this.removeAttribute('data-auto');
});

/* Date change — Section 1 */
document.getElementById('delivery_date').addEventListener('change', function () {
    loadSRCodes(this.value);
    genCode();
});
loadSRCodes(document.getElementById('delivery_date').value);

/* Load SR Codes */
function loadSRCodes(date) {
    var st = document.getElementById('srStatus');
    $('#sr_code').empty().append('<option value="">-- Select SR Code --</option>').trigger('change');
    checkReady();
    if (!date) { st.textContent = ''; return; }
    st.innerHTML = '<span class="st-loading"><span class="spin"></span> Loading…</span>';
    fetch('get_sr_codes.php?delivery_date=' + encodeURIComponent(date))
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success && data.sr_codes.length) {
            var avail  = data.sr_codes.filter(function(s){ return !s.already_created; });
            var hidden = data.sr_codes.length - avail.length;
            avail.forEach(function(sr){ $('#sr_code').append(new Option(sr.label, sr.code)); });
            $('#sr_code').trigger('change');
            st.innerHTML = avail.length
                ? '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> ' + avail.length
                  + ' available' + (hidden ? ' <span style="color:#aaa;font-weight:400;">('
                  + hidden + ' created)</span>' : '') + '</span>'
                : '<span class="st-err">All SR codes already created</span>';
        } else {
            st.innerHTML = '<span class="st-err">No SR codes for this date</span>';
        }
    })
    .catch(function(){ st.innerHTML = '<span class="st-err">Error loading SR codes</span>'; });
}

/* SR Code change */
$('#sr_code').on('change', function () {
    var sr   = $(this).val();
    var date = document.getElementById('delivery_date').value;
    var st   = document.getElementById('srStatus');
    genCode(); checkReady();
    if (!sr) return;
    st.innerHTML = '<span class="st-loading"><span class="spin"></span> Checking…</span>';
    fetch('get_sr_route.php?sr_code=' + encodeURIComponent(sr)
        + '&delivery_date=' + encodeURIComponent(date))
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            st.innerHTML = '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.record_count + ' record(s)</span>';
        } else {
            st.innerHTML = '<span class="st-err">' + (data.message || 'No records') + '</span>';
        }
    })
    .catch(function(){ st.innerHTML = '<span class="st-err">Error checking SR code</span>'; });
});

/* Check ready — Section 1 */
function checkReady() {
    document.getElementById('submitBtn').disabled =
        !($('#sr_code').val() && $('#dpSelect').val() && $('#empSelect').val());
}

/* Submit — Section 1 */
document.getElementById('fsForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtn');
    var box = document.getElementById('alertBox');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Creating…';
    box.innerHTML = '';

    fetch('process_field_summary.php', { method: 'POST', body: new FormData(this) })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            box.innerHTML = '<div class="fs-alert fs-alert-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.message + '</div>';
            setTimeout(function() {
                window.location.href = 'view_field_summary.php?id=' + data.field_summary_id;
            }, 1500);
        } else {
            box.innerHTML = '<div class="fs-alert fs-alert-err"><i class="fa-solid fa-exclamation-circle"></i> '
                + data.message + '</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Field Summary';
        }
    })
    .catch(function(err) {
        box.innerHTML = '<div class="fs-alert fs-alert-err"><i class="fa-solid fa-exclamation-circle"></i> '
            + err.message + '</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Field Summary';
    });
});


/* ══════════════════════════════════════════════════════════════════
   SECTION 2 — BY DELIVERY PERSON
══════════════════════════════════════════════════════════════════ */

document.getElementById('delivery_date_dp').valueAsDate = new Date();

/* Load delivery persons for a given date — Section 2 */
function loadDeliveryPersonsByDate(date) {
    var spinner = document.getElementById('dpSpinnerDp');
    var st      = document.getElementById('dpStatusDp');
    var strip   = document.getElementById('dpStripDp');

    $('#dpSelectDp').empty().append('<option value="">-- Select Delivery Person --</option>').trigger('change');
    strip.classList.remove('show');
    checkReadyDp();

    if (!date) { st.textContent = ''; return; }

    spinner.style.display = 'inline';
    st.innerHTML = '<span class="st-loading"><span class="spin"></span> Loading…</span>';

    fetch('get_delivery_persons_by_date.php?delivery_date=' + encodeURIComponent(date))
    .then(function(r){ return r.json(); })
    .then(function(data) {
        spinner.style.display = 'none';
        $('#dpSelectDp').empty().append('<option value="">-- Select Delivery Person --</option>');
        if (data.success && data.persons && data.persons.length) {
            data.persons.forEach(function(p) {
                var label = p.name || p;
                var val   = p.name || p;
                $('#dpSelectDp').append(new Option(label, val));
            });
            $('#dpSelectDp').trigger('change');
            st.innerHTML = '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.persons.length + ' person(s)</span>';
        } else {
            st.innerHTML = '<span class="st-err">No delivery persons found for this date</span>';
        }
    })
    .catch(function() {
        document.getElementById('dpSpinnerDp').style.display = 'none';
        document.getElementById('dpStatusDp').innerHTML =
            '<span class="st-err">Error loading delivery persons</span>';
    });
}

/* Auto-generate field summary code — Section 2 */
function genCodeDp() {
    var dp  = $('#dpSelectDp').val();
    var dt  = document.getElementById('delivery_date_dp').value;
    var inp = document.getElementById('field_summary_code_dp');
    if (dp && dt) {
        /* Use date + first 6 chars of delivery person name, uppercase, no spaces */
        var dpSlug = dp.replace(/\s+/g, '').substring(0, 6).toUpperCase();
        inp.value = dt.replace(/-/g, '') + 'DP' + dpSlug;
        inp.setAttribute('data-auto', 'true');
    } else if (inp.getAttribute('data-auto') === 'true') {
        inp.value = '';
        inp.removeAttribute('data-auto');
    }
}
document.getElementById('field_summary_code_dp').addEventListener('input', function () {
    this.removeAttribute('data-auto');
});

/* Date change — Section 2 */
document.getElementById('delivery_date_dp').addEventListener('change', function () {
    loadDeliveryPersonsByDate(this.value);
    genCodeDp();
});
loadDeliveryPersonsByDate(document.getElementById('delivery_date_dp').value);

/* Delivery person change — Section 2 */
$('#dpSelectDp').on('change', function () {
    var val   = $(this).val();
    var strip = document.getElementById('dpStripDp');
    genCodeDp(); checkReadyDp();
    if (val) {
        document.getElementById('dpStripNameDp').textContent = val;
        strip.classList.add('show');

        /* Show record count for this person+date */
        var date = document.getElementById('delivery_date_dp').value;
        var st   = document.getElementById('dpStatusDp');
        st.innerHTML = '<span class="st-loading"><span class="spin"></span> Checking records…</span>';
        fetch('get_dp_records.php?delivery_person=' + encodeURIComponent(val)
            + '&delivery_date=' + encodeURIComponent(date))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success) {
                st.innerHTML = '<span class="st-ok"><i class="fa-solid fa-check-circle"></i> '
                    + data.record_count + ' record(s)</span>';
            } else {
                st.innerHTML = '<span class="st-err">' + (data.message || 'No records found') + '</span>';
            }
        })
        .catch(function(){ st.innerHTML = '<span class="st-err">Error checking records</span>'; });
    } else {
        strip.classList.remove('show');
    }
});

/* Employee change — Section 2 */
$('#empSelectDp').on('change', function () {
    var val   = $(this).val();
    var strip = document.getElementById('empStripDp');
    checkReadyDp();
    if (val) {
        var $o = $(this).find('option:selected');
        document.getElementById('empStripNameDp').textContent = $o.text();
        document.getElementById('empStripMetaDp').textContent =
            [$o.data('code'), $o.data('desig')].filter(Boolean).join(' · ') || 'Employee';
        document.getElementById('empStripBadgeDp').innerHTML =
            '<i class="fa-solid fa-circle-check"></i> Selected';
        strip.classList.add('show');
    } else {
        strip.classList.remove('show');
    }
});

/* Check ready — Section 2 */
function checkReadyDp() {
    document.getElementById('submitBtnDp').disabled =
        !($('#dpSelectDp').val() && $('#empSelectDp').val()
          && document.getElementById('delivery_date_dp').value);
}

/* Submit — Section 2 */
document.getElementById('fsFormDp').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtnDp');
    var box = document.getElementById('alertBoxDp');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Creating…';
    box.innerHTML = '';

    fetch('process_field_summary_by_dp.php', { method: 'POST', body: new FormData(this) })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            box.innerHTML = '<div class="fs-alert fs-alert-ok"><i class="fa-solid fa-check-circle"></i> '
                + data.message + '</div>';
            setTimeout(function() {
                window.location.href = 'view_field_summary.php?id=' + data.field_summary_id;
            }, 1500);
        } else {
            box.innerHTML = '<div class="fs-alert fs-alert-err"><i class="fa-solid fa-exclamation-circle"></i> '
                + data.message + '</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Field Summary';
        }
    })
    .catch(function(err) {
        box.innerHTML = '<div class="fs-alert fs-alert-err"><i class="fa-solid fa-exclamation-circle"></i> '
            + err.message + '</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Field Summary';
    });
});
</script>

<?php include 'footer.php'; ?>