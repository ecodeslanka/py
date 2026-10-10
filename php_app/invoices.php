<?php
include 'config.php';
include 'header.php';

$routes_res    = mysqli_query($conn, "SELECT DISTINCT route FROM field_summary_details WHERE route IS NOT NULL AND route!='' ORDER BY route");
$customers_res = mysqli_query($conn, "SELECT t_code, shop_name FROM customers ORDER BY shop_name");

$banks_list = [];
$br = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

$emg_reasons = [];
$emg_r = mysqli_query($conn,"SELECT id, reason FROM emergency_credit_reasons WHERE active=1 ORDER BY reason ASC");
if ($emg_r) while ($er = mysqli_fetch_assoc($emg_r)) $emg_reasons[] = $er;

$init = json_encode([
    'search'         => $_GET['search']         ?? '',
    'customer'       => $_GET['customer']        ?? '',
    'route'          => $_GET['route']           ?? '',
    'pmode'          => $_GET['pmode']           ?? '',
    'date_from'      => $_GET['date_from']       ?? '',
    'date_to'        => $_GET['date_to']         ?? '',
    'to_be_delivery' => $_GET['to_be_delivery']  ?? '',
    'status'         => $_GET['status']          ?? '',
]);
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<style>
:root{--cash:#15803d;--credit:#1d4ed8;--cheque:#b45309;--tbd:#0e7490;}

/* ── Filter card ── */
.inv-filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:10px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.inv-filter-row{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;}
.inv-filter-row .fg{display:flex;flex-direction:column;gap:3px;}
.fg-search{flex:1.2;min-width:160px;}
.fg-customer{flex:2;min-width:180px;}
.fg-route{flex:1;min-width:110px;}
.fg-pmode{flex:.9;min-width:100px;}
.fg-date{flex:.9;min-width:110px;}
.flabel{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.finput{width:100%;padding:6px 9px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111;background:#fafafa;box-sizing:border-box;transition:border .2s;}
.finput:focus{outline:none;border-color:#111;background:#fff;}
.select2-container--default .select2-selection--single{height:32px!important;border:1px solid #d1d5db!important;border-radius:6px!important;background:#fafafa!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px!important;padding-left:9px!important;font-size:12px!important;color:#111!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:30px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#111!important;}
.select2-results__option--highlighted{background:#111!important;}
.select2-dropdown{z-index:99999!important;}

/* ── Filter action buttons (same row) ── */
.filter-btns{display:flex;gap:6px;align-items:flex-end;flex-shrink:0;}
.btn-search{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:#111;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;white-space:nowrap;}
.btn-search:hover{background:#333;}
.btn-ghost{display:inline-flex;align-items:center;gap:4px;background:#f5f5f5;color:#555;border:1px solid #e5e5e5;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;white-space:nowrap;}
.btn-ghost:hover{background:#e5e5e5;}
.btn-export{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;white-space:nowrap;}
.btn-export:hover{background:#15803d;}
.btn-export:disabled{opacity:.55;cursor:default;}
.xls-spin{width:12px;height:12px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .5s linear infinite;display:none;}
.btn-export.loading .xls-spin{display:inline-block;}
.btn-export.loading .xls-lbl{opacity:.7;}

/* ── Stats ── */
.inv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:6px;margin-bottom:10px;}
.inv-stat{background:#fff;border:1px solid #e5e7eb;border-radius:7px;padding:7px 10px;text-align:center;}
.ist-lbl{font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;}
.ist-val{font-size:16px;font-weight:800;color:#111;margin-top:1px;}
.g{color:#15803d!important;}.r{color:#dc2626!important;}.a{color:#d97706!important;}.t{color:#0e7490!important;}
.inv-stat.stat-tbd{border-color:#a5f3fc;background:#ecfeff;}
.inv-stat.stat-tbd .ist-lbl{color:#0891b2;}
.inv-stat.stat-tbd .ist-val{color:#0e7490;}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
.shimmer{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:400px 100%;animation:shimmer 1.2s infinite;border-radius:3px;color:transparent!important;min-height:20px;display:inline-block;width:70%;}

/* ── Tabs ── */
.inv-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px;}
.inv-tab{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid #e5e7eb;color:#6b7280;background:#f9fafb;cursor:pointer;transition:all .2s;user-select:none;}
.inv-tab:hover{border-color:#9ca3af;color:#374151;}
.inv-tab.active{background:#111;color:#fff;border-color:#111;}
.inv-tab.tbd-tab{border-color:#a5f3fc;color:#0e7490;background:#ecfeff;}
.inv-tab.tbd-tab.active{background:#0e7490;color:#fff;border-color:#0e7490;}

/* ── Table card ── */
.inv-table-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.inv-table-top{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid #e5e7eb;gap:8px;flex-wrap:wrap;}
.inv-table-top h3{font-size:13px;font-weight:700;color:#111;margin:0;display:flex;align-items:center;gap:6px;}
.tbl-wrap{overflow-x:auto;}

table.inv-tbl{width:100%;border-collapse:collapse;font-size:11.5px;}
table.inv-tbl thead{background:#f9fafb;border-bottom:2px solid #e5e7eb;}
table.inv-tbl th{padding:6px 9px;font-size:10px;font-weight:700;color:#374151;white-space:nowrap;text-align:center;letter-spacing:.3px;text-transform:uppercase;}
table.inv-tbl td{padding:5px 9px;border-bottom:1px solid #f3f4f6;color:#1f2937;white-space:nowrap;vertical-align:middle;}
table.inv-tbl tbody tr:hover{filter:brightness(.97);}
tr.rm-cash  {border-left:3px solid var(--cash);}
tr.rm-credit{border-left:3px solid var(--credit);}
tr.rm-cheque{border-left:3px solid var(--cheque);}
tr.rm-overdue{background:#fff5f5!important;}
tr.rm-tbd{background:#ecfeff!important;border-left:3px solid var(--tbd)!important;}
tr.rm-tbd td{color:#164e63;}

/* ── Skeleton rows ── */
.skel-row td{padding:7px 9px;}
.skel-cell{height:13px;border-radius:3px;background:linear-gradient(90deg,#f0f0f0 25%,#e8e8e8 50%,#f0f0f0 75%);background-size:800px 100%;animation:shimmer 1.2s infinite;}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:2px;padding:2px 6px;border-radius:20px;font-size:10px;font-weight:700;}
.bg-cash   {background:#dcfce7;color:#14532d;border:1px solid #86efac;}
.bg-credit {background:#dbeafe;color:#1e3a5f;border:1px solid #93c5fd;}
.bg-cheque {background:#fef9c3;color:#713f12;border:1px solid #fde047;}
.bg-paid   {background:#dcfce7;color:#14532d;}
.bg-unpaid {background:#fee2e2;color:#7f1d1d;}
.bg-partial{background:#fef3c7;color:#78350f;}
.bg-tbd{background:#cffafe;color:#0e7490;border:1px solid #67e8f9;display:inline-flex;align-items:center;gap:3px;padding:2px 5px;border-radius:20px;font-size:10px;font-weight:700;}

.due-ok  {color:#15803d;font-size:11px;font-weight:600;}
.due-warn{color:#d97706;font-size:11px;font-weight:600;}
.due-late{color:#dc2626;font-size:11px;font-weight:700;}
.due-na  {color:#9ca3af;font-size:11px;}
.amt{text-align:right;font-variant-numeric:tabular-nums;}
.amt-paid{color:#15803d;font-weight:700;}
.amt-pos{color:#dc2626;font-weight:700;}
.amt-zero{color:#15803d;font-weight:700;}

.btn-summary{display:inline-flex;align-items:center;gap:3px;background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;padding:2px 7px;font-size:10.5px;border-radius:4px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s;font-family:monospace;}
.btn-summary:hover{background:#ede9fe;}
.btn-view{display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:2px 7px;font-size:11px;border-radius:4px;font-weight:700;text-decoration:none;cursor:pointer;white-space:nowrap;}
.btn-view:hover{background:#dbeafe;}

.btn-pay-tbl{display:inline-flex;align-items:center;gap:3px;background:#22c55e;color:#fff;border:none;padding:2px 8px;font-size:11px;border-radius:4px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;}
.btn-pay-tbl:hover{background:#16a34a;}
.btn-pay-tbl.partial{background:#f59e0b;}.btn-pay-tbl.partial:hover{background:#d97706;}
.btn-pay-tbl.settled{background:#6b7280;cursor:default;}
.btn-view-pay-tbl{display:inline-flex;align-items:center;gap:2px;background:#faf5ff;color:#7c3aed;border:1px solid #ddd6fe;padding:2px 7px;font-size:11px;border-radius:4px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;}
.btn-view-pay-tbl:hover{background:#f3e8ff;}
.vp-count-tbl{background:#7c3aed;color:#fff;border-radius:10px;padding:0 4px;font-size:10px;font-weight:700;min-width:13px;text-align:center;line-height:15px;}
.btn-del{display:inline-flex;align-items:center;background:#fff0f0;color:#dc2626;border:1px solid #fecaca;padding:2px 7px;font-size:11px;border-radius:4px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;}
.btn-del:hover{background:#fee2e2;}
.act-cell{display:flex;gap:3px;align-items:center;flex-wrap:nowrap;}

/* ── Pagination ── */
.pager-wrap{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-top:1px solid #f3f4f6;flex-wrap:wrap;gap:6px;background:#fafafa;}
.pager-info{font-size:11px;color:#6b7280;}
.pager-info strong{color:#111;}
.pager-btns{display:flex;gap:3px;align-items:center;flex-wrap:wrap;}
.pg-btn{min-width:26px;height:26px;padding:0 6px;border:1px solid #e5e7eb;background:#fff;border-radius:4px;font-size:11px;font-weight:600;color:#374151;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .12s;line-height:1;}
.pg-btn:hover:not(:disabled){background:#f3f4f6;border-color:#9ca3af;}
.pg-btn.active{background:#111;color:#fff;border-color:#111;}
.pg-btn:disabled{opacity:.3;cursor:default;}
.pg-btn.pg-ellipsis{cursor:default;border:none;background:none;color:#9ca3af;}

#tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.65);z-index:10;align-items:center;justify-content:center;border-radius:8px;}
#tbl-loading.show{display:flex;}
.spin{width:24px;height:24px;border:3px solid #e5e7eb;border-top-color:#111;border-radius:50%;animation:spin .55s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.tbl-pos-wrap{position:relative;}

.search-wrap{position:relative;}
.search-wrap .clear-x{position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;font-size:11px;display:none;padding:0;}
.search-wrap .clear-x.show{display:block;}
.search-wrap input{padding-right:24px;}

.legend{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:11px;color:#6b7280;}
.leg-item{display:flex;align-items:center;gap:4px;}
.leg-dot{width:8px;height:8px;border-radius:2px;}

/* ── Delete modal ── */
.del-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.del-modal-backdrop.show{display:flex;}
.del-modal{background:#fff;border-radius:10px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
.del-modal-head{padding:14px 18px;border-bottom:1px solid #fee2e2;background:#fff5f5;display:flex;align-items:center;gap:8px;}
.del-modal-head h4{margin:0;font-size:14px;font-weight:700;color:#b91c1c;}
.del-modal-body{padding:16px 18px;}
.del-inv-box{background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;padding:9px 12px;margin-bottom:12px;font-size:12px;}
.del-inv-box .inv-no{font-weight:800;font-family:monospace;font-size:13px;color:#111;}
.del-inv-box .inv-meta{color:#6b7280;margin-top:2px;font-size:11px;}
.del-warning{background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:8px 11px;font-size:11.5px;color:#713f12;margin-bottom:12px;display:flex;gap:7px;}
.del-warning.red{background:#fee2e2;border-color:#fca5a5;color:#991b1b;}
.del-reason-label{font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block;}
.del-reason-input{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;resize:vertical;min-height:60px;box-sizing:border-box;}
.del-reason-input:focus{outline:none;border-color:#dc2626;}
.del-modal-foot{padding:10px 18px;border-top:1px solid #f3f4f6;display:flex;gap:7px;justify-content:flex-end;}
.btn-cancel-del{padding:6px 14px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-confirm-del{padding:6px 16px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:5px;}
.btn-confirm-del:hover{background:#b91c1c;}
.btn-confirm-del:disabled{opacity:.5;cursor:default;}
.del-spin{width:12px;height:12px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .5s linear infinite;display:none;}
.btn-confirm-del.loading .del-spin{display:block;}
.btn-confirm-del.loading .del-btn-txt{opacity:.6;}

/* ═══════════════ PAYMENT MODAL ═══════════════ */
.modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e5e5e5;background:#fafafa;flex-shrink:0;}
.modal-header-left{display:flex;flex-direction:column;gap:3px;flex:1;}
.modal-header-left h3{font-size:16px;font-weight:700;color:#1f2937;margin:0;}
.inv-summary-strip{display:flex;flex-wrap:wrap;margin-top:8px;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:8px 14px;border-right:1px solid #e5e5e5;min-width:100px;}
.inv-sum-item:last-child{border-right:none;}
.inv-sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.inv-sum-value{font-size:15px;font-weight:800;color:#1f2937;}
.inv-sum-value.green{color:#166534;}.inv-sum-value.red{color:#dc2626;}
.pay-mode-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;margin-top:3px;}
.pay-mode-badge.cash{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
.pay-mode-badge.cheque{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.pay-mode-badge.credit{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.modal-close{width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:10px;}
.modal-close:hover{background:#f5f5f5;color:#000;}
.credit-bypass-notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#1e40af;display:flex;align-items:flex-start;gap:7px;}
.modal-body{overflow-y:auto;flex:1;padding:0;}
.pay-section-wrap{padding:16px 20px;}
.pay-block{margin-bottom:16px;}
.pay-block-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:8px 12px;border-radius:6px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.pay-block-title.cash{background:#dcfce7;color:#166634;border-left:3px solid #22c55e;}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:3px solid #3b82f6;}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:12px;font-weight:600;color:#374151;}
.fg label .req{color:#ef4444;margin-left:2px;}
.fctrl{padding:7px 9px;border:1px solid #e0e0e0;border-radius:6px;font-size:12px;font-family:inherit;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#000;}
select.fctrl{cursor:pointer;}
input[type=number].fctrl{-moz-appearance:textfield;}
input[type=number].fctrl::-webkit-outer-spin-button,input[type=number].fctrl::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.gr{display:grid;gap:10px;margin-bottom:10px;}
.gr2{grid-template-columns:1fr 1fr;}.gr3{grid-template-columns:1fr 1fr 1fr;}.gr4{grid-template-columns:1fr 1fr 1fr 1fr;}
.pay-divider{text-align:center;position:relative;margin:14px 0;}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e5e5e5;}
.pay-divider span{position:relative;background:#fff;padding:0 10px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;}
.emg-row{display:flex;align-items:center;gap:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:7px 11px;margin-bottom:8px;}
.emg-label{font-size:12px;font-weight:600;color:#78350f;cursor:pointer;flex:1;display:flex;align-items:center;gap:5px;}
.toggle-switch{position:relative;width:36px;height:20px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:20px;cursor:pointer;transition:.3s;}
.toggle-slider::before{content:'';position:absolute;height:14px;width:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s;}
.toggle-switch input:checked+.toggle-slider{background:#f59e0b;}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(16px);}
#pmToBeDeliveryChk:checked+.toggle-slider{background:#3b82f6!important;}
.emg-box{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:10px;margin-bottom:8px;}
.upload-zone{border:2px dashed #d1d5db;border-radius:7px;padding:12px;text-align:center;background:#f9fafb;cursor:pointer;transition:all .2s;position:relative;margin-top:8px;}
.upload-zone:hover{border-color:#7c3aed;background:#faf5ff;}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-zone i{font-size:18px;color:#9ca3af;margin-bottom:3px;display:block;}
.upload-zone span{font-size:11px;font-weight:600;color:#374151;display:block;margin-bottom:1px;}
.upload-zone small{font-size:10px;color:#9ca3af;}
.file-previews{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;}
.file-chip{display:inline-flex;align-items:center;gap:3px;background:#f3f4f6;border-radius:4px;padding:3px 7px;font-size:11px;border:1px solid #e5e5e5;}
.file-chip-del{background:#ef4444;color:#fff;border:none;border-radius:50%;width:13px;height:13px;cursor:pointer;font-size:9px;display:inline-flex;align-items:center;justify-content:center;}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:7px;padding:12px;margin-bottom:10px;}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:5px;}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:2px 8px;border-radius:4px;font-size:11px;cursor:pointer;font-family:inherit;}
.btn-add-cheque{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:4px 8px;font-size:11px;color:#991b1b;display:none;margin-top:3px;}
.dup-cheque-warn.show{display:block;}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:12px 16px;margin-top:14px;}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:12px;color:#374151;border-bottom:1px solid #f0f0f0;}
.bal-sum-row:last-child{border-bottom:none;}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:7px;margin-top:3px;border-top:2px solid #e2e8f0;border-bottom:none;}
.modal-footer{padding:11px 20px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;}
.modal-footer-right{display:flex;gap:7px;}
.btn-modal-cancel{padding:7px 14px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;}
.btn-modal-submit{padding:8px 18px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:5px;}
.btn-modal-submit:hover{background:#6d28d9;}
.btn-modal-submit:disabled{opacity:.5;cursor:default;}

/* ── View Payments modal ── */
.view-pay-backdrop{position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;padding:16px;}
.view-pay-backdrop.open{display:flex;}
.view-pay-dialog{background:#fff;border-radius:12px;width:100%;max-width:700px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
.view-pay-header{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #e5e5e5;background:#fafafa;}
.view-pay-header h3{font-size:14px;font-weight:700;color:#1f2937;margin:0;display:flex;align-items:center;gap:7px;}
.view-pay-body{overflow-y:auto;flex:1;padding:14px 18px;}
.view-pay-empty{text-align:center;padding:26px;color:#9ca3af;font-size:12px;}
.view-pay-card{background:#f9fafb;border:1px solid #e5e5e5;border-radius:7px;padding:10px 12px;margin-bottom:8px;}
.vpc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
.vpc-method{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;}
.vpc-method.cash{background:#dcfce7;color:#166534;}
.vpc-method.cheque{background:#dbeafe;color:#1e40af;}
.vpc-amt{font-size:14px;font-weight:800;color:#1f2937;}
.vpc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;font-size:11px;}
.vpc-grid-item{display:flex;flex-direction:column;}
.vpc-grid-item .vl{font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;}
.vpc-grid-item .vv{color:#374151;font-weight:500;margin-top:1px;}
.vpc-cheques{margin-top:7px;padding-top:7px;border-top:1px dashed #e5e5e5;}
.vpc-chq-row{display:flex;align-items:center;justify-content:space-between;padding:3px 7px;font-size:11px;color:#374151;background:#fff;border:1px solid #e5e5e5;border-radius:4px;margin-bottom:3px;}
.vpc-chq-status{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;text-transform:uppercase;}
.vpc-chq-status.pending{background:#fef3c7;color:#92400e;}
.vpc-chq-status.cleared{background:#dcfce7;color:#166534;}
.vpc-chq-status.returned{background:#fef2f2;color:#991b1b;}
.vpc-emg{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:9px 12px;margin-bottom:8px;font-size:12px;color:#78350f;display:flex;align-items:center;gap:7px;}

/* ── Toast ── */
#payToast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:10000000;padding:12px 24px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.22);transition:opacity .35s,transform .35s;white-space:nowrap;pointer-events:none;display:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}

@media(max-width:700px){.gr2,.gr3,.gr4{grid-template-columns:1fr;}.inv-summary-strip{flex-wrap:wrap;}.inv-sum-item{min-width:50%;}.inv-filter-row{flex-direction:column;}.filter-btns{width:100%;}}
</style>

<div class="page-header" style="margin-bottom:8px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 class="page-title" style="margin:0;"><i class="fa-solid fa-file-invoice-dollar"></i> Invoice List</h2>
            <p class="page-subtitle" style="margin:2px 0 0;">All delivery invoices with payment status &amp; due dates</p>
        </div>
        <div class="legend">
            <span class="leg-item"><span class="leg-dot" style="background:var(--cash)"></span>Cash</span>
            <span class="leg-item"><span class="leg-dot" style="background:var(--credit)"></span>Credit</span>
            <span class="leg-item"><span class="leg-dot" style="background:var(--cheque)"></span>Cheque</span>
            <span class="leg-item"><span class="leg-dot" style="background:#fca5a5"></span>Overdue</span>
            <span class="leg-item"><span class="leg-dot" style="background:#67e8f9;border:1px solid #0e7490;"></span>TBD</span>
        </div>
    </div>
</div>

<!-- FILTERS — single compact row with all buttons inline -->
<div class="inv-filter-card">
    <div class="inv-filter-row">
        <div class="fg fg-search">
            <span class="flabel"><i class="fa-solid fa-magnifying-glass"></i> Search</span>
            <div class="search-wrap">
                <input type="text" id="fSearch" class="finput" placeholder="Invoice, customer, T-Code…" autocomplete="off">
                <button class="clear-x" id="btnClearSearch" title="Clear"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="fg fg-customer">
            <span class="flabel"><i class="fa-solid fa-user"></i> Customer</span>
            <select id="fCustomer" class="finput">
                <option value="">— All Customers —</option>
                <?php if($customers_res) while($c=mysqli_fetch_assoc($customers_res)): ?>
                <option value="<?php echo htmlspecialchars($c['t_code']); ?>"><?php echo htmlspecialchars($c['t_code'].' — '.$c['shop_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg fg-route">
            <span class="flabel"><i class="fa-solid fa-route"></i> Route</span>
            <select id="fRoute" class="finput">
                <option value="">— All —</option>
                <?php if($routes_res) while($r=mysqli_fetch_assoc($routes_res)): ?>
                <option value="<?php echo htmlspecialchars($r['route']); ?>"><?php echo htmlspecialchars($r['route']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg fg-pmode">
            <span class="flabel"><i class="fa-solid fa-wallet"></i> Mode</span>
            <select id="fPmode" class="finput">
                <option value="">— All —</option>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div class="fg fg-date">
            <span class="flabel"><i class="fa-regular fa-calendar"></i> From</span>
            <input type="date" id="fDateFrom" class="finput">
        </div>
        <div class="fg fg-date">
            <span class="flabel"><i class="fa-regular fa-calendar"></i> To</span>
            <input type="date" id="fDateTo" class="finput">
        </div>
        <!-- All buttons inline -->
        <div class="filter-btns">
            <div style="display:flex;flex-direction:column;gap:3px;">
                <span class="flabel">&nbsp;</span>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button class="btn-search" id="btnSearch"><i class="fa-solid fa-search"></i> Search</button>
                    <button class="btn-ghost" id="btnReset" title="Reset"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                    <button class="btn-export" id="btnExport" title="Export all filtered results to Excel">
                        <div class="xls-spin"></div>
                        <span class="xls-lbl"><i class="fa-solid fa-file-excel"></i> Export</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- STATS -->
<div class="inv-stats">
    <div class="inv-stat"><div class="ist-lbl">Total</div><div class="ist-val" id="sTotal"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Net Value</div><div class="ist-val" id="sNet"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Collected</div><div class="ist-val g" id="sPaid"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Balance</div><div class="ist-val r" id="sBal"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Paid</div><div class="ist-val g" id="sCntPaid"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Unpaid</div><div class="ist-val r" id="sCntUnpaid"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Partial</div><div class="ist-val a" id="sCntPartial"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat"><div class="ist-lbl">Overdue</div><div class="ist-val r" id="sCntOverdue"><span class="shimmer">&nbsp;</span></div></div>
    <div class="inv-stat stat-tbd"><div class="ist-lbl"><i class="fa-solid fa-truck"></i> TBD</div><div class="ist-val t" id="sCntTbd"><span class="shimmer">&nbsp;</span></div></div>
</div>

<!-- TABS -->
<div class="inv-tabs">
    <span class="inv-tab active" data-tab="">All <span id="tabAll"></span></span>
    <span class="inv-tab" data-tab="unpaid">Unpaid <span id="tabUnpaid"></span></span>
    <span class="inv-tab" data-tab="partial">Partial <span id="tabPartial"></span></span>
    <span class="inv-tab" data-tab="paid">Paid <span id="tabPaid"></span></span>
    <span class="inv-tab tbd-tab" data-tab="tbd"><i class="fa-solid fa-truck"></i> TBD <span id="tabTbd"></span></span>
</div>

<!-- TABLE -->
<div class="inv-table-card">
    <div class="inv-table-top">
        <h3 id="tblTitle"><i class="fa-solid fa-table-list"></i> Invoices</h3>
        <div style="font-size:11px;color:#6b7280;" id="tblPageInfo"></div>
    </div>
    <div class="tbl-pos-wrap">
        <div id="tbl-loading"><div class="spin"></div></div>
        <div class="tbl-wrap">
            <table class="inv-tbl">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Invoice No</th>
                        <th>Del. Date</th>
                        <th>Bill Date</th>
                        <th>T-Code</th>
                        <th>Customer</th>
                        <th>Route</th>
                        <th>SR</th>
                        <th>Summary</th>
                        <th>Mode</th>
                        <th>Due Date</th>
                        <th class="amt">Net Value</th>
                        <th class="amt">Paid</th>
                        <th class="amt">Balance</th>
                        <th>Status</th>
                        <th style="min-width:170px;"></th>
                    </tr>
                </thead>
                <tbody id="invTbody">
                    <?php for($i=0;$i<10;$i++): ?>
                    <tr class="skel-row"><?php for($j=0;$j<16;$j++): ?><td><div class="skel-cell" style="width:<?php echo [20,75,65,65,48,110,55,50,65,42,65,55,55,55,50,75][$j]; ?>px;"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="pager-wrap" id="pagerWrap">
        <div class="pager-info" id="pagerInfo"></div>
        <div class="pager-btns" id="pagerBtns"></div>
    </div>
</div>

<!-- SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    $('#fCustomer').select2({placeholder:'— All Customers —',allowClear:true,width:'100%'});
    $('#fRoute').select2({placeholder:'— All —',allowClear:true,width:'100%'});

    const INIT = <?php echo $init; ?>;

    /* state */
    let pageRows     = [];   /* rows for current visible page (server sends exactly one page) */
    let activeTab    = INIT.status || '';
    let isTbdTab     = INIT.to_be_delivery === '1';
    let curPage      = 1;
    let totalPages   = 1;
    let totalCount   = 0;
    const PAGE_SIZE  = 200;
    let xhrReq       = null;

    /* init filter values */
    if(INIT.search)    { $('#fSearch').val(INIT.search).siblings('.clear-x').addClass('show'); }
    if(INIT.customer)  { $('#fCustomer').val(INIT.customer).trigger('change.select2'); }
    if(INIT.route)     { $('#fRoute').val(INIT.route).trigger('change.select2'); }
    if(INIT.pmode)     { $('#fPmode').val(INIT.pmode); }
    if(INIT.date_from) { $('#fDateFrom').val(INIT.date_from); }
    if(INIT.date_to)   { $('#fDateTo').val(INIT.date_to); }
    if(isTbdTab) activeTab = 'tbd';
    syncTabUI();

    /* helpers */
    function fmt(n){ return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
    function cap(s){ return s ? s[0].toUpperCase()+s.slice(1) : ''; }
    function fmtDate(d){ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
    function fmtDatePlain(d){ if(!d) return ''; const dt=new Date(d+'T00:00:00'); return dt.getDate().toString().padStart(2,'0')+'/'+(dt.getMonth()+1).toString().padStart(2,'0')+'/'+dt.getFullYear(); }

    function getFilters(){
        return {
            search        : $('#fSearch').val().trim(),
            customer      : $('#fCustomer').val()||'',
            route         : $('#fRoute').val()||'',
            pmode         : $('#fPmode').val(),
            date_from     : $('#fDateFrom').val(),
            date_to       : $('#fDateTo').val(),
            to_be_delivery: isTbdTab ? '1' : '',
            status        : (!isTbdTab && activeTab) ? activeTab : '',
        };
    }

    /* ── Single entry point for every load: filters change, tab click,
       reset, or page turn — always asks the server for exactly one
       page (server does filtering + ordering + LIMIT/OFFSET together,
       no ID list ever built or sent to the browser). ── */
    function loadPage(pg){
        if(xhrReq) xhrReq.abort();
        $('#tbl-loading').addClass('show');
        const filters = getFilters();
        const f = Object.assign({}, filters, {page: pg});
        xhrReq = $.ajax({
            url:'invoices_data.php', data:$.param(f), dataType:'json',
            success:function(resp){
                pageRows   = resp.invoices;
                curPage    = resp.pagination.page;
                totalPages = resp.pagination.total_pages;
                totalCount = resp.pagination.total_count;
                updateStats(resp.stats);
                renderTabs(resp.stats);
                renderTable();
                renderPager();
                updateURL(filters);
            },
            error:function(xhr){ if(xhr.statusText!=='abort') showError(); },
            complete:function(){ $('#tbl-loading').removeClass('show'); }
        });
    }

    /* fetchAll = go back to page 1 (used for filter/tab/reset changes) */
    function fetchAll(){ loadPage(1); }
    window.fetchData = fetchAll;

    /* fetchPage = jump straight to any page number */
    function fetchPage(pg){
        if(pg<1||pg>totalPages) return;
        loadPage(pg);
    }

    /* ── Render current pageRows ── */
    function renderTable(){
        const rows  = pageRows;
        const start = (curPage-1)*PAGE_SIZE;
        const tbody = document.getElementById('invTbody');

        const tabLabel = activeTab==='tbd'
            ? ' <span class="bg-tbd" style="margin-left:4px;font-size:10px;"><i class="fa-solid fa-truck"></i> TBD</span>' : '';
        document.getElementById('tblTitle').innerHTML =
            '<i class="fa-solid fa-table-list"></i> Invoices'
            +' <span style="color:#6b7280;font-size:12px;font-weight:500;">('+totalCount.toLocaleString()+')</span>'+tabLabel;

        if(!rows || !rows.length){
            tbody.innerHTML='<tr><td colspan="16" style="text-align:center;color:#9ca3af;padding:36px 20px;">'
                +'<i class="fa-solid fa-inbox" style="font-size:26px;display:block;margin-bottom:7px;"></i>No invoices found.</td></tr>';
            renderPager(); return;
        }

        const frag = document.createDocumentFragment();
        rows.forEach(function(inv,i){
            const gi    = start+i+1;
            const pm    = (inv.payment_mode||'cash').toLowerCase();
            const isTbd = parseInt(inv.to_be_delivery)===1;
            const rCls  = isTbd ? 'rm-tbd'
                : 'rm-'+(['cash','credit','cheque'].includes(pm)?pm:'cash')+(inv.overdue?' rm-overdue':'');

            const ikeaAmt = parseFloat(inv.ikea_value)||0;
            const paid    = parseFloat(inv.paid_amount)||0;
            const balance = Math.max(0, ikeaAmt-paid);

            let dC='due-na', dH='—';
            if(inv.due_date){
                const d=parseInt(inv.days_diff);
                if(inv.inv_status==='paid'){dC='due-ok';dH=fmtDate(inv.due_date);}
                else if(d>0){dC='due-late';dH=fmtDate(inv.due_date)+' <small style="font-size:9px;">('+d+'d)</small>';}
                else if(d>=-3){dC='due-warn';dH=fmtDate(inv.due_date)+(d<0?' <small style="font-size:9px;">('+Math.abs(d)+'d)</small>':' <small style="font-size:9px;">(today)</small>');}
                else{dC='due-ok';dH=fmtDate(inv.due_date);}
            }

            const sb = balance<=0.005
                ? '<span class="badge bg-paid"><i class="fa-solid fa-check"></i> Paid</span>'
                : paid>0
                    ? '<span class="badge bg-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>'
                    : '<span class="badge bg-unpaid"><i class="fa-solid fa-clock"></i> Unpaid</span>';

            const payBtnCls = balance<=0.005 ? 'settled' : paid>0 ? 'partial' : '';
            const payBtnLbl = balance<=0.005 ? '<i class="fa-solid fa-check"></i> Paid' : '<i class="fa-solid fa-coins"></i> Pay';
            const payCount  = parseInt(inv.pay_count||0);
            const fsId      = inv.field_summary_id;
            const fsCode    = esc(inv.field_summary_code||'—');

            const tr = document.createElement('tr');
            tr.className = rCls;
            tr.dataset.id           = inv.detail_id;
            tr.dataset.fsid         = inv.field_summary_id;
            tr.dataset.tcode        = inv.t_code;
            tr.dataset.invoice      = inv.invoice_num;
            tr.dataset.customer     = inv.display_customer;
            tr.dataset.adjust       = ikeaAmt;
            tr.dataset.paid         = paid;
            tr.dataset.balance      = balance;
            tr.dataset.paymode      = inv.payment_mode||'';
            tr.dataset.creditdays   = inv.credit_days||0;
            tr.dataset.deliverydate = inv.delivery_date||'';
            tr.dataset.paycount     = payCount;

            tr.innerHTML =
                '<td style="color:#9ca3af;font-size:10px;">'+gi+'</td>'
                +'<td><span style="font-weight:700;font-family:monospace;font-size:11px;">'+esc(inv.invoice_num)+'</span>'
                    +(isTbd?' <span class="bg-tbd"><i class="fa-solid fa-truck"></i></span>':'')
                +'</td>'
                +'<td>'+fmtDate(inv.delivery_date)+'</td>'
                +'<td>'+(inv.bill_date?fmtDate(inv.bill_date):'<span style="color:#d1d5db">—</span>')+'</td>'
                +'<td><code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:10.5px;">'+esc(inv.t_code)+'</code></td>'
                +'<td style="font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis;">'+esc(inv.display_customer)+'</td>'
                +'<td style="color:#6b7280;">'+esc(inv.route||'—')+'</td>'
                +'<td><code style="background:#f0f9ff;color:#0369a1;padding:1px 5px;border-radius:3px;font-size:10.5px;border:1px solid #bae6fd;">'+esc(inv.sr_code||'—')+'</code></td>'
                +'<td>'+(fsId?'<a href="edit_field_summary.php?id='+fsId+'" class="btn-summary"><i class="fa-solid fa-clipboard-list"></i> '+fsCode+'</a>':'<span style="color:#d1d5db">—</span>')+'</td>'
                +'<td><span class="badge bg-'+pm+'">'+cap(pm)+'</span></td>'
                +'<td class="'+dC+'">'+dH+'</td>'
                +'<td class="amt">'+fmt(ikeaAmt)+'</td>'
                +'<td class="amt amt-paid" data-paid-cell="'+inv.detail_id+'">'+fmt(paid)+'</td>'
                +'<td class="amt '+(balance>0?'amt-pos':'amt-zero')+'" data-bal-cell="'+inv.detail_id+'">'+fmt(balance)+'</td>'
                +'<td data-status-cell="'+inv.detail_id+'">'+sb+'</td>'
                +'<td class="act-cell">'
                    +'<a href="view_invoice.php?id='+inv.detail_id+'" class="btn-view"><i class="fa-solid fa-eye"></i></a>'
                    +'<button class="btn-pay-tbl '+payBtnCls+'" onclick="openInvPayModal(this)">'+payBtnLbl+'</button>'
                    +(payCount>0
                        ?'<button class="btn-view-pay-tbl" onclick="openInvViewPayments(this)"><i class="fa-solid fa-receipt"></i><span class="vp-count-tbl">'+payCount+'</span></button>'
                        :'<button class="btn-view-pay-tbl" onclick="openInvViewPayments(this)"><i class="fa-solid fa-receipt"></i></button>'
                    )
                    +'<button class="btn-del btn-del-action"'
                        +' data-id="'+inv.detail_id+'"'
                        +' data-invoice="'+esc(inv.invoice_num)+'"'
                        +' data-customer="'+esc(inv.display_customer)+'"'
                        +' data-paid="'+paid+'"'
                        +' data-balance="'+balance+'">'
                        +'<i class="fa-solid fa-trash"></i></button>'
                +'</td>';
            frag.appendChild(tr);
        });
        tbody.innerHTML='';
        tbody.appendChild(frag);
    }

    /* ── Pager — uses server totalCount/totalPages ── */
    function renderPager(){
        const info = document.getElementById('pagerInfo');
        const btns = document.getElementById('pagerBtns');
        const from = Math.min((curPage-1)*PAGE_SIZE+1, totalCount);
        const to   = Math.min(curPage*PAGE_SIZE, totalCount);
        info.innerHTML = 'Page <strong>'+curPage+'</strong> of <strong>'+totalPages+'</strong>'
            +' &nbsp;·&nbsp; <strong>'+from+'–'+to+'</strong> of <strong>'+totalCount.toLocaleString()+'</strong>';
        document.getElementById('tblPageInfo').textContent = '200 per page';

        if(totalPages<=1){btns.innerHTML='';return;}
        let html='';
        html+='<button class="pg-btn" id="pgFirst" '+(curPage===1?'disabled':'')+'>«</button>';
        html+='<button class="pg-btn" id="pgPrev"  '+(curPage===1?'disabled':'')+'>‹</button>';
        buildPageNums(curPage,totalPages,2).forEach(p=>{
            html += p==='…'
                ? '<span class="pg-btn pg-ellipsis">…</span>'
                : '<button class="pg-btn'+(p===curPage?' active':'')+'" data-p="'+p+'">'+p+'</button>';
        });
        html+='<button class="pg-btn" id="pgNext" '+(curPage===totalPages?'disabled':'')+'>›</button>';
        html+='<button class="pg-btn" id="pgLast" '+(curPage===totalPages?'disabled':'')+'>»</button>';
        btns.innerHTML=html;
        btns.querySelectorAll('[data-p]').forEach(b=>b.addEventListener('click',()=>goPage(parseInt(b.dataset.p))));
        const q=id=>btns.querySelector('#'+id);
        q('pgFirst').addEventListener('click',()=>goPage(1));
        q('pgPrev').addEventListener('click',()=>goPage(curPage-1));
        q('pgNext').addEventListener('click',()=>goPage(curPage+1));
        q('pgLast').addEventListener('click',()=>goPage(totalPages));
    }

    function buildPageNums(cur,total,delta){
        const left=Math.max(2,cur-delta), right=Math.min(total-1,cur+delta);
        const out=[1];
        if(left>2) out.push('…');
        for(let i=left;i<=right;i++) out.push(i);
        if(right<total-1) out.push('…');
        if(total>1) out.push(total);
        return out;
    }

    function goPage(p){
        if(p<1||p>totalPages) return;
        fetchPage(p);
        document.querySelector('.inv-table-card').scrollIntoView({behavior:'smooth',block:'start'});
    }

    /* stats */
    function updateStats(s){
        $('#sTotal').text(parseInt(s.total).toLocaleString());
        $('#sNet').text(fmt(s.net));
        $('#sPaid').text(fmt(s.paid));
        $('#sBal').text(fmt(s.balance));
        $('#sCntPaid').text(s.cnt_paid);
        $('#sCntUnpaid').text(s.cnt_unpaid);
        $('#sCntPartial').text(s.cnt_partial);
        $('#sCntOverdue').text(s.cnt_overdue);
        $('#sCntTbd').text(s.cnt_tbd);
    }
    function renderTabs(s){
        $('#tabAll').text('('+(s.cnt_paid+s.cnt_unpaid+s.cnt_partial)+')');
        $('#tabUnpaid').text('('+s.cnt_unpaid+')');
        $('#tabPartial').text('('+s.cnt_partial+')');
        $('#tabPaid').text('('+s.cnt_paid+')');
        $('#tabTbd').text('('+s.cnt_tbd+')');
    }

    /* tab click — fetch from server with tab filter */
    $('.inv-tab').on('click',function(){
        activeTab=$(this).data('tab');
        isTbdTab=activeTab==='tbd';
        syncTabUI();
        fetchAll();
    });
    function syncTabUI(){
        $('.inv-tab').removeClass('active');
        $('.inv-tab[data-tab="'+activeTab+'"]').addClass('active');
    }

    /* ── Search: only button click or Enter key triggers fetch ── */
    $('#btnSearch').on('click',function(){ fetchAll(); });
    $('#fSearch').on('keydown',function(e){ if(e.key==='Enter') fetchAll(); });
    $('#fSearch').on('input',function(){
        $(this).siblings('.clear-x').toggleClass('show',$(this).val().length>0);
    });
    $('#btnClearSearch').on('click',function(){
        $('#fSearch').val('').focus().siblings('.clear-x').removeClass('show');
        /* does NOT auto-fetch — user must click Search */
    });

    /* dropdowns and dates do NOT auto-fetch — user clicks Search */
    /* (no change handlers on #fCustomer, #fRoute, #fPmode, #fDateFrom, #fDateTo) */

    /* reset — clears all fields then fetches once */
    $('#btnReset').on('click',function(){
        $('#fSearch').val('').siblings('.clear-x').removeClass('show');
        /* Use val() + trigger('change') only for Select2 UI update, but block the
           fetchPage call by temporarily unbinding, then rebinding after */
        $('#fCustomer').val(null).trigger('change.select2');
        $('#fRoute').val(null).trigger('change.select2');
        $('#fPmode').val('');
        $('#fDateFrom').val('');
        $('#fDateTo').val('');
        activeTab=''; isTbdTab=false;
        syncTabUI();
        fetchAll();   /* single fetch after full reset */
    });

    /* URL sync */
    function updateURL(f){
        const p={};
        if(f.search)    p.search=f.search;
        if(f.customer)  p.customer=f.customer;
        if(f.route)     p.route=f.route;
        if(f.pmode)     p.pmode=f.pmode;
        if(f.date_from) p.date_from=f.date_from;
        if(f.date_to)   p.date_to=f.date_to;
        if(isTbdTab)    p.to_be_delivery='1';
        else if(activeTab) p.status=activeTab;
        history.replaceState(null,'','invoices.php'+(Object.keys(p).length?'?'+new URLSearchParams(p).toString():''));
    }

    function showError(){
        document.getElementById('invTbody').innerHTML=
            '<tr><td colspan="16" style="text-align:center;color:#dc2626;padding:28px;">Failed to load. Please try again.</td></tr>';
    }

    /* ══════════════════════════════════════════
       EXPORT TO EXCEL — fetches ALL filtered rows
       (mode=export, no pagination limit)
    ══════════════════════════════════════════ */
    document.getElementById('btnExport').addEventListener('click', function(){
        const btn = this;
        btn.classList.add('loading'); btn.disabled=true;
        invShowToast('Preparing export…','ok');

        const f = Object.assign({}, getFilters(), {mode:'export'});
        $.ajax({
            url:'invoices_data.php', data:$.param(f), dataType:'json',
            success:function(resp){
                const all = resp.invoices;
                if(!all.length){ invShowToast('No data to export.','err'); btn.classList.remove('loading'); btn.disabled=false; return; }

                const headers = ['#','Invoice No','Delivery Date','Bill Date','T-Code','Customer','Route','SR Code','Summary','Pay Mode','Due Date','Net Value','Paid','Balance','Status','TBD'];
                const rows = all.map(function(inv,i){
                    const net  = parseFloat(inv.ikea_value)||0;
                    const paid = parseFloat(inv.paid_amount)||0;
                    const bal  = Math.max(0,net-paid);
                    const st   = bal<=0.005?'Paid':paid>0?'Partial':'Unpaid';
                    return [i+1, inv.invoice_num||'', fmtDatePlain(inv.delivery_date), fmtDatePlain(inv.bill_date),
                        inv.t_code||'', inv.display_customer||'', inv.route||'', inv.sr_code||'',
                        inv.field_summary_code||'', cap(inv.payment_mode||'cash'),
                        fmtDatePlain(inv.due_date), net, paid, bal, st,
                        parseInt(inv.to_be_delivery)===1?'Yes':'No'];
                });

                const ws   = XLSX.utils.aoa_to_sheet([headers,...rows]);
                ws['!cols']= [{wch:5},{wch:16},{wch:13},{wch:13},{wch:10},{wch:26},{wch:10},{wch:9},{wch:15},{wch:9},{wch:13},{wch:14},{wch:13},{wch:13},{wch:9},{wch:5}];
                /* numeric format for amount columns */
                rows.forEach(function(r,ri){
                    [11,12,13].forEach(function(ci){
                        const ref=XLSX.utils.encode_cell({r:ri+1,c:ci});
                        if(ws[ref]) ws[ref].t='n';
                    });
                });
                const wb  = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Invoices');
                const now = new Date();
                const ts  = now.getFullYear()+String(now.getMonth()+1).padStart(2,'0')+String(now.getDate()).padStart(2,'0')
                          +'_'+String(now.getHours()).padStart(2,'0')+String(now.getMinutes()).padStart(2,'0');
                const tabLabel = activeTab?'_'+activeTab:'';
                XLSX.writeFile(wb,'invoices'+tabLabel+'_'+ts+'.xlsx');
                invShowToast('Exported '+all.length+' invoices ✓','ok');
            },
            error:function(){ invShowToast('Export failed.','err'); },
            complete:function(){ btn.classList.remove('loading'); btn.disabled=false; }
        });
    });

    /* ── Delete button delegation ── */
    document.getElementById('invTbody').addEventListener('click',function(e){
        const btn=e.target.closest('.btn-del-action');
        if(!btn) return;
        openDelModal(btn.dataset.id,btn.dataset.invoice,btn.dataset.customer,
            parseFloat(btn.dataset.paid||0),parseFloat(btn.dataset.balance||0));
    });

    /* initial load — fetch all rows once, page client-side */
    fetchAll();
});

/* ═══════════════════════════════════════════
   INLINE PAYMENT MODAL
═══════════════════════════════════════════ */
const BANKS       = <?php echo json_encode($banks_list); ?>;
const EMG_REASONS = <?php echo json_encode($emg_reasons); ?>;
let _ARD={}, _activeRow=null, _chequeCounter=0, _tbdActive=false;
const _dupCache={};

function openInvPayModal(btn){
    const row=btn.closest('tr');
    _activeRow=row;
    const ikeaAmt=parseFloat(row.dataset.adjust||0);
    const paid=parseFloat(row.dataset.paid||0);
    const balance=Math.max(0,ikeaAmt-paid);
    _ARD={id:row.dataset.id,fsid:row.dataset.fsid,tcode:row.dataset.tcode,invoice:row.dataset.invoice,
          customer:row.dataset.customer,ikea:ikeaAmt,paid:paid,balance:balance,
          payMode:row.dataset.paymode||'',creditDays:row.dataset.creditdays||0,
          deliveryDate:row.dataset.deliverydate||new Date().toISOString().slice(0,10)};
    const pm=_ARD.payMode.toLowerCase();
    document.getElementById('pmModalSubtitle').textContent='Invoice: '+_ARD.invoice+' | '+_ARD.customer;
    document.getElementById('pmHdrInv').textContent='Rs. '+ikeaAmt.toFixed(2);
    document.getElementById('pmHdrPaid').textContent='Rs. '+paid.toFixed(2);
    document.getElementById('pmHdrBal').textContent='Rs. '+balance.toFixed(2);
    const pmIcon=pm==='cash'?'coins':pm==='cheque'?'money-check':'credit-card';
    const pmLabel=pm?pm.charAt(0).toUpperCase()+pm.slice(1):'N/A';
    document.getElementById('pmHdrPayMode').innerHTML='<span class="pay-mode-badge '+pm+'"><i class="fa-solid fa-'+pmIcon+'"></i> '+pmLabel+'</span>';
    document.getElementById('pmCreditBypass').style.display=(pm==='credit'||pm==='cheque')?'flex':'none';
    const defDate=_ARD.deliveryDate;
    document.getElementById('pmCashDate').value=defDate;
    document.getElementById('pmChqPayDate').value=defDate;
    document.getElementById('pmCashAmount').value='';
    document.getElementById('pmCashToBank').value='';
    document.getElementById('pmCashRef').value='';
    document.getElementById('pmCashRemarks').value='';
    document.getElementById('pmChqRef').value='';
    document.getElementById('pmChqRemarks').value='';
    document.getElementById('pmChqModeSelect').value='payee_only';
    document.getElementById('pmEmgToggle').checked=false;
    document.getElementById('pmEmgBox').style.display='none';
    document.getElementById('pmEmgReason').value='';
    document.getElementById('pmEmgFilePreviews').innerHTML='';
    document.getElementById('pmEmgFiles').value='';
    document.getElementById('pmChequesContainer').innerHTML='';
    _chequeCounter=0;
    pmAddCheque();
    pmSyncPreviews();
    pmResetTbd();
    const submitBtn=document.getElementById('pmSubmitPayBtn');
    submitBtn.disabled=false;
    submitBtn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';
    document.getElementById('pmSumInvoiceBalance').textContent='Rs. '+balance.toFixed(2);
    document.getElementById('pmPayModal').classList.add('open');
    document.body.style.overflow='hidden';
}
function closeInvPayModal(){ document.getElementById('pmPayModal').classList.remove('open'); document.body.style.overflow=''; }

function pmSyncPreviews(){
    const remaining=Math.max(0,(_ARD.ikea||0)-(_ARD.paid||0));
    const cashAmt=Math.max(0,parseFloat(document.getElementById('pmCashAmount').value)||0);
    let chqTotal=0;
    document.querySelectorAll('#pmChequesContainer .pm-chq-amt').forEach(i=>{chqTotal+=parseFloat(i.value)||0;});
    const totalPaying=cashAmt+chqTotal;
    const newBal=Math.max(0,remaining-totalPaying);
    document.getElementById('pmSumInvoiceBalance').textContent='Rs. '+remaining.toFixed(2);
    document.getElementById('pmSumCash').textContent='Rs. '+cashAmt.toFixed(2);
    document.getElementById('pmSumCheque').textContent='Rs. '+chqTotal.toFixed(2);
    document.getElementById('pmSumTotal').textContent='Rs. '+totalPaying.toFixed(2);
    document.getElementById('pmSumBalance').textContent='Rs. '+newBal.toFixed(2);
    document.getElementById('pmHdrBal').textContent='Rs. '+newBal.toFixed(2);
    const overpay=parseFloat((totalPaying-remaining).toFixed(2));
    const opRow=document.getElementById('pmSumOverpayRow');
    if(overpay>0.005){opRow.style.display='flex';document.getElementById('pmSumOverpay').textContent='Rs. +'+overpay.toFixed(2);document.getElementById('pmSumBalance').style.color='#6b7280';}
    else{opRow.style.display='none';document.getElementById('pmSumBalance').style.color='#dc2626';}
}

function pmAddCheque(){
    _chequeCounter++;
    const idx=_chequeCounter;
    let bankOpts='<option value="">— Select Bank —</option>';
    BANKS.forEach(b=>{bankOpts+='<option value="'+b.bank_code+'" data-name="'+b.bank_name+'">'+b.bank_code+' – '+b.bank_name+'</option>';});
    const card=document.createElement('div');
    card.className='cheque-card'; card.id='pm-cheque-'+idx;
    card.innerHTML='<div class="cheque-card-header"><span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #'+idx+'</span>'
        +(idx>1?'<button type="button" class="btn-remove-cheque" onclick="document.getElementById(\'pm-cheque-'+idx+'\').remove();pmSyncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>':'')+'</div>'
        +'<div class="gr gr3"><div class="fg"><label>Cheque No. <span class="req">*</span></label><input type="text" class="fctrl" id="pmChqNo-'+idx+'" placeholder="e.g. 001234" oninput="pmCheckDupCheque('+idx+')"><div class="dup-cheque-warn" id="pm-dup-warn-'+idx+'"></div></div>'
        +'<div class="fg"><label>Cheque Date</label><input type="date" class="fctrl" id="pmChqDate-'+idx+'"></div>'
        +'<div class="fg"><label>Amount (Rs.) <span class="req">*</span></label><input type="number" class="fctrl pm-chq-amt" id="pmChqAmt-'+idx+'" step="0.01" min="0" placeholder="0.00" oninput="pmSyncPreviews();pmCheckDupCheque('+idx+')"></div></div>'
        +'<div class="gr gr2"><div class="fg"><label>Bank <span class="req">*</span></label><select class="fctrl" id="pm-chq-bank-'+idx+'">'+bankOpts+'</select></div>'
        +'<div class="fg"><label>Branch</label><select class="fctrl" id="pm-chq-branch-'+idx+'"><option value="">— Select Branch —</option></select></div></div>';
    document.getElementById('pmChequesContainer').appendChild(card);
    $('#pm-chq-bank-'+idx).select2({width:'100%',dropdownParent:$('#pmPayModal')}).on('change',function(){pmLoadBranches(this.value,idx);});
    $('#pm-chq-branch-'+idx).select2({width:'100%',dropdownParent:$('#pmPayModal')});
}

function pmCheckDupCheque(idx){
    const no=(document.getElementById('pmChqNo-'+idx)?.value||'').trim();
    const amt=parseFloat(document.getElementById('pmChqAmt-'+idx)?.value||0);
    const warn=document.getElementById('pm-dup-warn-'+idx);
    if(!no||!warn) return;
    if(_dupCache[no]!==undefined){pmShowDupWarn(warn,no,_dupCache[no],amt);return;}
    fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no)).then(r=>r.json()).then(data=>{_dupCache[no]=data.exists?data.total_amount:null;pmShowDupWarn(warn,no,_dupCache[no],amt);}).catch(()=>{});
}
function pmShowDupWarn(warn,no,existingTotal,newAmt){
    if(existingTotal!==null&&existingTotal!==undefined){const nt=(parseFloat(existingTotal)||0)+(parseFloat(newAmt)||0);warn.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Cheque #'+no+' exists (Rs. '+parseFloat(existingTotal).toFixed(2)+'). New total: Rs. '+nt.toFixed(2)+'.';warn.classList.add('show');}
    else{warn.classList.remove('show');warn.innerHTML='';}
}
function pmLoadBranches(bankCode,idx){
    const sel=document.getElementById('pm-chq-branch-'+idx);
    sel.innerHTML='<option value="">Loading…</option>';
    $(sel).select2('destroy');
    if(!bankCode){sel.innerHTML='<option value="">— Select Branch —</option>';$(sel).select2({width:'100%',dropdownParent:$('#pmPayModal')});return;}
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode)).then(r=>r.json()).then(data=>{let opts='<option value="">— Select Branch —</option>';data.forEach(b=>{opts+='<option value="'+b.branch_code+'" data-name="'+b.branch_name+'">'+b.branch_code+' – '+b.branch_name+'</option>';});sel.innerHTML=opts;$(sel).select2({width:'100%',dropdownParent:$('#pmPayModal')});}).catch(()=>{sel.innerHTML='<option value="">Error</option>';$(sel).select2({width:'100%',dropdownParent:$('#pmPayModal')});});
}

function pmToggleTbd(){_tbdActive=!_tbdActive;const btn=document.getElementById('pmTbdFooterBtn');if(_tbdActive){btn.style.background='#3b82f6';btn.style.color='#fff';btn.style.borderColor='#3b82f6';btn.innerHTML='<i class="fa-solid fa-truck"></i> To Be Delivery ✓';}else{btn.style.background='#fff';btn.style.color='#3b82f6';btn.style.borderColor='#bfdbfe';btn.innerHTML='<i class="fa-solid fa-truck"></i> Mark as To Be Delivery';}}
function pmResetTbd(){_tbdActive=false;const btn=document.getElementById('pmTbdFooterBtn');if(btn){btn.style.background='#fff';btn.style.color='#3b82f6';btn.style.borderColor='#bfdbfe';btn.innerHTML='<i class="fa-solid fa-truck"></i> Mark as To Be Delivery';}}
function pmUpdateTbd(detailId,callback){const fd=new FormData();fd.append('detail_id',detailId);fd.append('to_be_delivery','1');fetch('mark_to_be_delivery.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(!data.success)invShowToast('TBD error.','err');if(callback)callback();}).catch(e=>{invShowToast('TBD error: '+e.message,'err');if(callback)callback();});}

function pmRefreshRow(row,newPaid){
    const ikeaAmt=parseFloat(row.dataset.adjust||0);
    const newBal=Math.max(0,ikeaAmt-newPaid);
    const detId=row.dataset.id;
    row.dataset.paid=newPaid; row.dataset.balance=newBal;
    const pc=document.querySelector('[data-paid-cell="'+detId+'"]');
    if(pc) pc.textContent=newPaid.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const bc=document.querySelector('[data-bal-cell="'+detId+'"]');
    if(bc){bc.textContent=newBal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});bc.className='amt '+(newBal>0?'amt-pos':'amt-zero');}
    const sc=document.querySelector('[data-status-cell="'+detId+'"]');
    if(sc){if(newBal<=0.005)sc.innerHTML='<span class="badge bg-paid"><i class="fa-solid fa-check"></i> Paid</span>';else if(newPaid>0)sc.innerHTML='<span class="badge bg-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>';}
    const payBtn=row.querySelector('.btn-pay-tbl');
    if(payBtn){if(newBal<=0.005){payBtn.className='btn-pay-tbl settled';payBtn.innerHTML='<i class="fa-solid fa-check"></i> Paid';}else{payBtn.className='btn-pay-tbl partial';payBtn.innerHTML='<i class="fa-solid fa-coins"></i> Pay';}}
    const vpBtn=row.querySelector('.btn-view-pay-tbl');
    if(vpBtn){let cnt=parseInt(row.dataset.paycount||'0')+1;row.dataset.paycount=cnt;let badge=vpBtn.querySelector('.vp-count-tbl');if(badge){badge.textContent=cnt;}else{badge=document.createElement('span');badge.className='vp-count-tbl';badge.textContent=cnt;vpBtn.appendChild(badge);}}
}

async function pmSubmitPayment(){
    const btn=document.getElementById('pmSubmitPayBtn');
    const cashAmt=parseFloat(document.getElementById('pmCashAmount').value)||0;
    const cashDate=document.getElementById('pmCashDate').value;
    const cashEmg=document.getElementById('pmEmgToggle').checked;
    const chqCards=document.querySelectorAll('#pmChequesContainer .cheque-card');
    let chqTotal=0,chqValid=true,cheques=[];
    chqCards.forEach(card=>{
        const idx=parseInt(card.id.replace('pm-cheque-',''));
        const no=(document.getElementById('pmChqNo-'+idx)?.value||'').trim();
        const amt=parseFloat(document.getElementById('pmChqAmt-'+idx)?.value||0);
        const dt=document.getElementById('pmChqDate-'+idx)?.value||'';
        const bkCode=$('#pm-chq-bank-'+idx).val()||'';
        const bkSel=document.getElementById('pm-chq-bank-'+idx);
        const bkName=bkSel?.selectedOptions[0]?.dataset.name||'';
        const brCode=$('#pm-chq-branch-'+idx).val()||'';
        const brSel=document.getElementById('pm-chq-branch-'+idx);
        const brName=brSel?.selectedOptions[0]?.dataset.name||'';
        if(amt>0){if(!no)chqValid=false;chqTotal+=amt;cheques.push({cheque_no:no,cheque_date:dt,amount:amt,bank_code:bkCode,bank_name:bkName,branch_code:brCode,branch_name:brName});}
    });
    if(cashAmt<=0&&chqTotal<=0&&!cashEmg){invShowToast('Enter cash, cheque, or tick Emergency Credit.','err');return;}
    if(cashAmt>0&&!cashDate){invShowToast('Select a Payment Date for cash.','err');return;}
    if(chqTotal>0&&!chqValid){invShowToast('Fill in all Cheque Numbers.','err');return;}
    if(cashEmg&&!document.getElementById('pmEmgReason').value.trim()){invShowToast('Select a reason for Emergency Credit.','err');return;}
    function basePayload(){const fd=new FormData();fd.append('field_summary_id',_ARD.fsid);fd.append('field_summary_detail_id',_ARD.id);fd.append('t_code',_ARD.tcode);fd.append('invoice_num',_ARD.invoice);return fd;}
    btn.disabled=true;btn.innerHTML='<span class="spin" style="width:13px;height:13px;border-width:2px;display:inline-block;margin-right:5px;vertical-align:middle;border-top-color:#fff;"></span> Saving…';
    let lastPayData=null,saved=[];const combinedTotal=cashAmt+chqTotal;
    try{
        if(cashAmt>0){const fd=basePayload();fd.append('payment_method','cash');fd.append('payment_date',cashDate||new Date().toISOString().slice(0,10));fd.append('amount',cashAmt);fd.append('amount_to_bank',document.getElementById('pmCashToBank').value||0);fd.append('reference_no',document.getElementById('pmCashRef').value||'');fd.append('collected_by',document.getElementById('pmCashCollectedBy').value);fd.append('remarks',document.getElementById('pmCashRemarks').value||'');fd.append('has_emergency_credit',cashEmg?'1':'0');fd.append('combined_total',combinedTotal);const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){invShowToast('Cash error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';return;}lastPayData=data;saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));}
        if(chqTotal>0){const fd=basePayload();fd.append('payment_method','cheque');fd.append('payment_date',document.getElementById('pmChqPayDate').value||new Date().toISOString().slice(0,10));fd.append('amount',chqTotal);fd.append('reference_no',document.getElementById('pmChqRef').value||'');fd.append('cheque_mode',document.getElementById('pmChqModeSelect').value||'payee_only');fd.append('collected_by','cc');fd.append('remarks',document.getElementById('pmChqRemarks').value||'');fd.append('has_emergency_credit',cashEmg?'1':'0');fd.append('combined_total',combinedTotal);cheques.forEach((q,i)=>{fd.append('cheques['+i+'][cheque_no]',q.cheque_no);fd.append('cheques['+i+'][cheque_date]',q.cheque_date);fd.append('cheques['+i+'][amount]',q.amount);fd.append('cheques['+i+'][bank_code]',q.bank_code);fd.append('cheques['+i+'][bank_name]',q.bank_name);fd.append('cheques['+i+'][branch_code]',q.branch_code);fd.append('cheques['+i+'][branch_name]',q.branch_name);});const res=await fetch('save_payment.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){invShowToast('Cheque error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';return;}lastPayData=data;saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));}
        if(cashEmg){const fd=basePayload();fd.append('reason',document.getElementById('pmEmgReason').value||'');const emgFiles=document.getElementById('pmEmgFiles').files;for(let i=0;i<emgFiles.length;i++)fd.append('documents[]',emgFiles[i]);const res=await fetch('save_emergency_credit.php',{method:'POST',body:fd});const data=await res.json();if(!data.success){invShowToast('Emergency credit error: '+(data.error||'Unknown'),'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';return;}saved.push('🔴 Emergency Credit logged');}
        if(lastPayData&&_activeRow){const newPaid=parseFloat(lastPayData.new_paid||0);pmRefreshRow(_activeRow,newPaid);_ARD.paid=newPaid;_ARD.balance=Math.max(0,_ARD.ikea-newPaid);}
        invShowToast(saved.join(' + ')+' ✓','ok');
        if(_tbdActive){pmUpdateTbd(_ARD.id,()=>{setTimeout(()=>closeInvPayModal(),900);});}else{setTimeout(()=>closeInvPayModal(),900);}
    }catch(err){invShowToast('Network error: '+err.message,'err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';
}

/* ═══════════════ VIEW PAYMENTS ═══════════════ */
function openInvViewPayments(btn){
    const row=btn.closest('tr');
    document.getElementById('pmVpInvoiceLabel').textContent=(row.dataset.invoice||'')+'  —  '+(row.dataset.customer||'');
    document.getElementById('pmVpBody').innerHTML='<div class="view-pay-empty"><div class="spin" style="margin:0 auto;width:20px;height:20px;border-width:2px;"></div></div>';
    document.getElementById('pmViewPayModal').classList.add('open');
    fetch('get_row_payments.php?detail_id='+encodeURIComponent(row.dataset.id)).then(r=>r.json()).then(data=>{if(!data.success){document.getElementById('pmVpBody').innerHTML='<div class="view-pay-empty">Error loading payments.</div>';return;}pmRenderViewPayments(data);}).catch(()=>{document.getElementById('pmVpBody').innerHTML='<div class="view-pay-empty">Network error.</div>';});
}
function pmRenderViewPayments(data){
    const body=document.getElementById('pmVpBody');
    let html='';
    function _e(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
    if(data.emergency_credits&&data.emergency_credits.length>0)data.emergency_credits.forEach(ec=>{html+='<div class="vpc-emg"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> <strong>Emergency Credit</strong> — '+_e(ec.reason)+'</div>';});
    if(!data.payments||data.payments.length===0){if(!data.emergency_credits||!data.emergency_credits.length)html='<div class="view-pay-empty"><i class="fa-solid fa-inbox" style="font-size:22px;display:block;margin-bottom:7px;color:#d1d5db;"></i>No payments recorded.</div>';body.innerHTML=html;return;}
    data.payments.forEach(p=>{html+='<div class="view-pay-card"><div class="vpc-top"><span class="vpc-method '+_e(p.payment_method)+'"><i class="fa-solid fa-'+(p.payment_method==='cash'?'coins':'money-check')+'"></i> '+_e(p.payment_method)+'</span><span class="vpc-amt">Rs. '+parseFloat(p.amount).toFixed(2)+'</span></div><div class="vpc-grid"><div class="vpc-grid-item"><span class="vl">Date</span><span class="vv">'+_e(p.payment_date||'—')+'</span></div><div class="vpc-grid-item"><span class="vl">Reference</span><span class="vv">'+_e(p.reference_no||'—')+'</span></div><div class="vpc-grid-item"><span class="vl">Collected By</span><span class="vv">'+_e(p.collected_by||'—')+'</span></div>'+(p.remarks?'<div class="vpc-grid-item"><span class="vl">Remarks</span><span class="vv">'+_e(p.remarks)+'</span></div>':'')+'<div class="vpc-grid-item"><span class="vl">Recorded</span><span class="vv">'+_e(p.created_at||'—')+'</span></div></div>';
    if(p.cheques&&p.cheques.length>0){html+='<div class="vpc-cheques"><div style="font-size:11px;font-weight:700;color:#1e40af;margin-bottom:3px;"><i class="fa-solid fa-money-check"></i> Cheques</div>';p.cheques.forEach(chq=>{const sc=(chq.cheque_status||'pending').toLowerCase();html+='<div class="vpc-chq-row"><span><strong>#'+_e(chq.cheque_no)+'</strong> — Rs. '+parseFloat(chq.amount).toFixed(2)+'</span><span>'+(chq.bank_name?'<span style="font-size:11px;color:#6b7280;margin-right:5px;">'+_e(chq.bank_name)+'</span>':'')+'<span class="vpc-chq-status '+sc+'">'+_e(chq.cheque_status||'pending')+'</span></span></div>';});html+='</div>';}
    html+='</div>';});
    body.innerHTML=html;
}
function closeInvViewPayments(){document.getElementById('pmViewPayModal').classList.remove('open');}

function invShowToast(msg,type){
    let t=document.getElementById('payToast');
    if(!t){t=document.createElement('div');t.id='payToast';document.body.appendChild(t);}
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.innerHTML='<i class="fa-solid fa-'+(type==='ok'?'check-circle':'exclamation-circle')+'" style="margin-right:5px;"></i>'+msg;
    t.style.display='block';t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer=setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(-50%) translateY(-12px)';setTimeout(()=>{t.style.display='none';},380);},2800);
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        if(document.getElementById('pmViewPayModal').classList.contains('open'))closeInvViewPayments();
        else if(document.getElementById('pmPayModal').classList.contains('open'))closeInvPayModal();
    }
});
</script>

<!-- ═══════════ PAYMENT MODAL ═══════════ -->
<div class="modal-backdrop" id="pmPayModal">
<div class="modal-dialog">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-money-bill-transfer" style="color:#7c3aed;margin-right:3px;"></i> Record Payment</h3>
      <p id="pmModalSubtitle" style="font-size:11px;color:#6b7280;margin:0;">—</p>
      <div class="inv-summary-strip">
        <div class="inv-sum-item"><span class="inv-sum-label">Net Value</span><span class="inv-sum-value" id="pmHdrInv">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Total Paid</span><span class="inv-sum-value green" id="pmHdrPaid">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Balance</span><span class="inv-sum-value red" id="pmHdrBal">—</span></div>
        <div class="inv-sum-item"><span class="inv-sum-label">Mode</span><span id="pmHdrPayMode"><span class="pay-mode-badge">—</span></span></div>
      </div>
    </div>
    <button class="modal-close" onclick="closeInvPayModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body"><div class="pay-section-wrap">
    <div class="credit-bypass-notice" id="pmCreditBypass" style="display:none;"><i class="fa-solid fa-info-circle"></i><div><strong>Credit / Cheque Customer</strong> — you may still record a partial cash or cheque payment.</div></div>
    <div class="pay-block">
      <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
      <div class="gr gr4">
        <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="pmCashDate"></div>
        <div class="fg"><label>Cash Amount (Rs.)</label><input type="number" class="fctrl" id="pmCashAmount" step="0.01" min="0" placeholder="0.00" oninput="pmSyncPreviews()"></div>
        <div class="fg"><label>Amount to Bank</label><input type="number" class="fctrl" id="pmCashToBank" step="0.01" min="0" placeholder="0.00"></div>
        <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="pmCashRef" placeholder="Optional"></div>
      </div>
      <div class="gr gr2">
        <div class="fg"><label>Collected By</label>
          <select class="fctrl" id="pmCashCollectedBy">
            <option value="cc" selected>CC — Cash Collector</option>
            <option value="sr">SR — Sales Rep</option>
            <option value="area_manager">Area Manager</option>
            <option value="office">Office</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="fg"><label>Remarks</label><input type="text" class="fctrl" id="pmCashRemarks" placeholder="Notes..."></div>
      </div>
    </div>
    <div class="pay-divider"><span>+ Cheque Payment (optional)</span></div>
    <div class="pay-block">
      <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> Cheque Payment</div>
      <div class="gr gr3">
        <div class="fg"><label>Cheque Payment Date</label><input type="date" class="fctrl" id="pmChqPayDate"></div>
        <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="pmChqRef" placeholder="Optional"></div>
        <div class="fg"><label>Cheque Mode</label>
          <select class="fctrl" id="pmChqModeSelect">
            <option value="payee_only">Payee Only</option>
            <option value="cash">Cash</option>
            <option value="third_party_cash">Third Party Cash</option>
          </select>
        </div>
      </div>
      <div id="pmChequesContainer"></div>
      <button type="button" class="btn-add-cheque" onclick="pmAddCheque()"><i class="fa-solid fa-plus"></i> Add Cheque</button>
      <div class="fg" style="margin-top:8px;"><label>Remarks</label><input type="text" class="fctrl" id="pmChqRemarks" placeholder="Notes..."></div>
    </div>
    <div class="pay-divider"><span>Emergency Credit</span></div>
    <div class="pay-block">
      <div class="emg-row">
        <label class="emg-label" for="pmEmgToggle"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> Mark as Emergency Credit</label>
        <label class="toggle-switch"><input type="checkbox" id="pmEmgToggle" onchange="document.getElementById('pmEmgBox').style.display=this.checked?'block':'none'"><span class="toggle-slider"></span></label>
      </div>
      <div id="pmEmgBox" style="display:none;" class="emg-box">
        <div class="fg"><label>Reason <span class="req">*</span></label>
          <select class="fctrl" id="pmEmgReason">
            <option value="">— Select a reason —</option>
            <?php foreach ($emg_reasons as $er): ?><option value="<?php echo htmlspecialchars($er['reason']); ?>"><?php echo htmlspecialchars($er['reason']); ?></option><?php endforeach; ?>
            <?php if(empty($emg_reasons)): ?><option value="" disabled>No reasons configured</option><?php endif; ?>
          </select>
        </div>
        <div class="upload-zone" style="margin-top:8px;">
          <input type="file" id="pmEmgFiles" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx"
            onchange="Array.from(this.files).forEach(f=>{const c=document.createElement('div');c.className='file-chip';c.innerHTML='<i class=\'fa-solid fa-file\' style=\'color:#6b7280;\'></i>'+f.name+'<button type=\'button\' class=\'file-chip-del\' onclick=\'this.parentElement.remove()\'>&times;</button>';document.getElementById('pmEmgFilePreviews').appendChild(c);})">
          <i class="fa-solid fa-cloud-arrow-up"></i><span>Upload Supporting Documents</span><small>JPG, PNG, PDF, DOC — max 10MB</small>
        </div>
        <div class="file-previews" id="pmEmgFilePreviews"></div>
      </div>
    </div>
    <div class="pay-divider"><span>Delivery Options</span></div>
    <div class="pay-block" style="margin-bottom:6px;">
      <div class="emg-row" style="background:#eff6ff;border-color:#bfdbfe;">
        <label class="emg-label" style="color:#1e40af;"><i class="fa-solid fa-truck" style="color:#3b82f6;"></i> Mark as To Be Delivery</label>
        <label class="toggle-switch">
          <input type="checkbox" id="pmToBeDeliveryChk" onchange="_tbdActive=this.checked;var btn=document.getElementById('pmTbdFooterBtn');if(this.checked){btn.style.background='#3b82f6';btn.style.color='#fff';btn.style.borderColor='#3b82f6';btn.innerHTML='<i class=\'fa-solid fa-truck\'></i> To Be Delivery ✓';}else{btn.style.background='#fff';btn.style.color='#3b82f6';btn.style.borderColor='#bfdbfe';btn.innerHTML='<i class=\'fa-solid fa-truck\'></i> Mark as To Be Delivery';}">
          <span class="toggle-slider"></span>
        </label>
      </div>
    </div>
    <div class="bal-summary">
      <div class="bal-sum-row"><span><i class="fa-solid fa-file-invoice" style="color:#6b7280;"></i> Net Value Balance</span><strong id="pmSumInvoiceBalance" style="color:#374151;">Rs. —</strong></div>
      <div class="bal-sum-row"><span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span><strong id="pmSumCash">Rs. 0.00</strong></div>
      <div class="bal-sum-row"><span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span><strong id="pmSumCheque">Rs. 0.00</strong></div>
      <div class="bal-sum-row total"><span><i class="fa-solid fa-sigma"></i> Total payment</span><strong id="pmSumTotal">Rs. 0.00</strong></div>
      <div class="bal-sum-row"><span><i class="fa-solid fa-hourglass-half"></i> Remaining</span><strong id="pmSumBalance" style="color:#dc2626;">Rs. —</strong></div>
      <div id="pmSumOverpayRow" style="display:none;background:#fef2f2;border-radius:5px;padding:7px 9px;margin-top:5px;border:1px solid #fecaca;">
        <span style="color:#991b1b;font-weight:700;display:flex;align-items:center;gap:5px;"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Overpayment</span>
        <strong id="pmSumOverpay" style="color:#dc2626;">Rs. 0.00</strong>
      </div>
    </div>
  </div></div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Cash + cheque saved per invoice.</div>
    <div class="modal-footer-right">
      <button type="button" id="pmTbdFooterBtn" onclick="pmToggleTbd()"
        style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:6px;border:2px solid #bfdbfe;background:#fff;color:#3b82f6;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;">
        <i class="fa-solid fa-truck"></i> Mark as To Be Delivery
      </button>
      <button class="btn-modal-cancel" onclick="closeInvPayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-submit" id="pmSubmitPayBtn" onclick="pmSubmitPayment()"><i class="fa-solid fa-paper-plane"></i> Submit Payment</button>
    </div>
  </div>
</div></div>

<!-- ═══════════ VIEW PAYMENTS MODAL ═══════════ -->
<div class="view-pay-backdrop" id="pmViewPayModal">
<div class="view-pay-dialog">
  <div class="view-pay-header">
    <h3><i class="fa-solid fa-receipt" style="color:#7c3aed;"></i> Payment History</h3>
    <div style="display:flex;align-items:center;gap:8px;">
      <span id="pmVpInvoiceLabel" style="font-size:11px;color:#6b7280;"></span>
      <button class="modal-close" onclick="closeInvViewPayments()"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="view-pay-body" id="pmVpBody"><div class="view-pay-empty">Loading…</div></div>
</div></div>

<!-- ═══════════ DELETE MODAL ═══════════ -->
<div class="del-modal-backdrop" id="delModalBackdrop">
  <div class="del-modal">
    <div class="del-modal-head"><i class="fa-solid fa-triangle-exclamation" style="font-size:15px;"></i><h4>Delete Invoice</h4></div>
    <div class="del-modal-body">
      <div class="del-inv-box"><div class="inv-no" id="delInvNo">—</div><div class="inv-meta" id="delInvMeta">—</div></div>
      <div class="del-warning red" id="delPaidWarn" style="display:none;"><i class="fa-solid fa-circle-exclamation"></i><span>This invoice has payments. All payments, cheques, and credit requests will also be permanently deleted.</span></div>
      <div class="del-warning"><i class="fa-solid fa-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
        <div><div style="font-weight:700;margin-bottom:5px;">Records that will be deleted:</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;">
            <?php foreach(['field_summary_details','invoice_payments','invoice_payment_cheques','cheques','credit_requests','credit_documents','credit_bill_issue_items','payment_reversals','payment_reversal_cheques'] as $tbl): ?>
            <div style="display:flex;align-items:center;gap:4px;"><span style="width:5px;height:5px;border-radius:50%;background:#b45309;flex-shrink:0;display:inline-block;"></span><code style="font-size:10px;background:#fef9c3;padding:1px 4px;border-radius:3px;"><?php echo $tbl; ?></code></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <label class="del-reason-label">Reason <span style="color:#dc2626">*</span></label>
      <textarea class="del-reason-input" id="delReason" placeholder="Enter reason…"></textarea>
    </div>
    <div class="del-modal-foot">
      <button class="btn-cancel-del" id="delModalCancel">Cancel</button>
      <button class="btn-confirm-del" id="delModalConfirm"><div class="del-spin"></div><span class="del-btn-txt"><i class="fa-solid fa-trash"></i> Delete</span></button>
    </div>
  </div>
</div>

<script>
(function(){
    let _detailId=null,_invoiceNum=null;
    window.openDelModal=function(detailId,invoiceNum,customer,paid,balance){
        _detailId=detailId;_invoiceNum=invoiceNum;
        var hasPaid=parseFloat(paid)>0;
        document.getElementById('delInvNo').textContent=invoiceNum;
        document.getElementById('delInvMeta').textContent=customer+(parseFloat(balance)>0?'  |  Bal: '+parseFloat(balance).toLocaleString('en-US',{minimumFractionDigits:2}):'  |  Fully Paid');
        document.getElementById('delPaidWarn').style.display=hasPaid?'flex':'none';
        document.getElementById('delReason').value='';
        var btn=document.getElementById('delModalConfirm');btn.classList.remove('loading');btn.disabled=false;
        document.getElementById('delModalBackdrop').classList.add('show');
        setTimeout(function(){document.getElementById('delReason').focus();},120);
    };
    document.getElementById('delModalCancel').addEventListener('click',closeModal);
    document.getElementById('delModalBackdrop').addEventListener('click',function(e){if(e.target===this)closeModal();});
    function closeModal(){document.getElementById('delModalBackdrop').classList.remove('show');_detailId=null;_invoiceNum=null;}
    document.getElementById('delModalConfirm').addEventListener('click',function(){
        var reason=document.getElementById('delReason').value.trim();
        if(reason.length<3){document.getElementById('delReason').focus();document.getElementById('delReason').style.borderColor='#dc2626';setTimeout(function(){document.getElementById('delReason').style.borderColor='';},1600);return;}
        if(!_detailId||!_invoiceNum) return;
        var savedDetailId=_detailId,savedInvNum=_invoiceNum;
        var btn=this;btn.classList.add('loading');btn.disabled=true;
        fetch('delete_invoice.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'detail_id='+encodeURIComponent(savedDetailId)+'&invoice_num='+encodeURIComponent(savedInvNum)+'&reason='+encodeURIComponent(reason)})
        .then(function(r){return r.json();})
        .then(function(resp){
            btn.classList.remove('loading');btn.disabled=false;
            if(resp.success){
                closeModal();
                document.querySelectorAll('#invTbody tr').forEach(function(tr){if(tr.dataset&&String(tr.dataset.id)===String(savedDetailId))tr.remove();});
                invShowToast('Invoice '+savedInvNum+' deleted. ('+resp.payments_del+' payment(s), '+resp.credits_del+' credit(s))','ok');
                if(typeof window.fetchData==='function')window.fetchData();
            }else{invShowToast(resp.message||'Delete failed.','err');}
        })
        .catch(function(){btn.classList.remove('loading');btn.disabled=false;invShowToast('Network error.','err');});
    });
})();
</script>
<?php include 'footer.php'; ?>