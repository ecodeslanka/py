<?php
/* ═══════════════════════════════════════════════════════════════
   import_credit_notes.php  —  Bulk Credit Note Importer
   Drop this file in your project root alongside config.php
═══════════════════════════════════════════════════════════════ */
include 'config.php';

/* ── Ensure credit_notes table & note_date column exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$chk = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_notes' AND COLUMN_NAME='note_date' LIMIT 1");
if ($chk && mysqli_num_rows($chk) === 0)
    mysqli_query($conn,"ALTER TABLE credit_notes ADD COLUMN `note_date` DATE NOT NULL DEFAULT (CURDATE()) AFTER reason");

/* ── AJAX handler ── */
if (!empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action    = $_POST['ajax_action'];
    $note_date = trim($_POST['note_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $note_date)) $note_date = date('Y-m-d');

    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows)) { echo json_encode(['success'=>false,'error'=>'Invalid JSON rows']); exit; }

    $results = [];
    $found = $not_found = $skipped = $inserted = 0;

    foreach ($rows as $i => $row) {
        $inv = trim($row['invoice_num'] ?? '');
        $amt = floatval($row['amount']  ?? 0);
        $rea = trim($row['reason']      ?? '');

        if ($inv === '' || $amt <= 0) {
            $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                          'status'=>'skipped','msg'=>'Empty invoice or zero amount',
                          'customer'=>'','net_value'=>0,'detail_id'=>0];
            $skipped++;
            continue;
        }

        $ie  = mysqli_real_escape_string($conn, $inv);
        $sql = "SELECT fsd.id,
                       COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS cname,
                       COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value
                FROM   field_summary_details fsd
                LEFT   JOIN customers c ON c.t_code = fsd.t_code
                LEFT   JOIN (
                    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                    FROM   secondary_invoice_import_details GROUP BY bill_no
                ) siid ON siid.bill_no = fsd.invoice_num
                WHERE  fsd.invoice_num = '$ie' AND fsd.updated = 1
                LIMIT  1";

        $res = mysqli_query($conn, $sql);
        if (!$res || mysqli_num_rows($res) === 0) {
            $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                          'status'=>'not_found','msg'=>'Invoice not found in system',
                          'customer'=>'','net_value'=>0,'detail_id'=>0];
            $not_found++;
            continue;
        }

        $fsd       = mysqli_fetch_assoc($res);
        $detail_id = intval($fsd['id']);
        $net_value = floatval($fsd['net_value']);
        $customer  = $fsd['cname'] ?? '';
        $found++;

        if ($action === 'preview') {
            $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                          'status'=>'found','msg'=>'Ready to import',
                          'customer'=>$customer,'net_value'=>$net_value,'detail_id'=>$detail_id];
        } else {
            $nd_esc = mysqli_real_escape_string($conn, $note_date);
            $dup = mysqli_query($conn,"SELECT id FROM credit_notes
                WHERE field_summary_detail_id=$detail_id AND amount=$amt
                  AND note_date='$nd_esc' AND is_deleted=0 LIMIT 1");
            if ($dup && mysqli_num_rows($dup) > 0) {
                $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                              'status'=>'duplicate','msg'=>'Already imported (duplicate)',
                              'customer'=>$customer,'net_value'=>$net_value,'detail_id'=>$detail_id];
                $skipped++;
                continue;
            }
            $re = mysqli_real_escape_string($conn, $rea);
            mysqli_query($conn,"INSERT INTO credit_notes
                (field_summary_detail_id,amount,reason,note_date)
                VALUES ($detail_id,$amt,'$re','$nd_esc')");
            if (mysqli_insert_id($conn)) {
                $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                              'status'=>'imported','msg'=>'Successfully imported',
                              'customer'=>$customer,'net_value'=>$net_value,'detail_id'=>$detail_id];
                $inserted++;
            } else {
                $results[] = ['row'=>$i+1,'invoice'=>$inv,'amount'=>$amt,'reason'=>$rea,
                              'status'=>'error','msg'=>'DB error: '.mysqli_error($conn),
                              'customer'=>$customer,'net_value'=>$net_value,'detail_id'=>$detail_id];
            }
        }
    }

    echo json_encode([
        'success'   => true,
        'action'    => $action,
        'total'     => count($rows),
        'found'     => $found,
        'not_found' => $not_found,
        'skipped'   => $skipped,
        'inserted'  => $inserted,
        'note_date' => $note_date,
        'results'   => $results
    ]);
    exit;
}
/* ── End AJAX ── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Credit Notes</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1f2937;background:#f8fafc;min-height:100vh;}
.topbar{background:#1e1b4b;color:#fff;padding:12px 24px;display:flex;align-items:center;gap:14px;}
.topbar-icon{width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.topbar h1{font-size:16px;font-weight:800;margin:0;}
.topbar p{font-size:11px;color:#c7d2fe;margin:2px 0 0;}
.back-btn{margin-left:auto;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;padding:7px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.back-btn:hover{background:rgba(255,255,255,.2);}
.page{max-width:1180px;margin:24px auto;padding:0 20px 60px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-title{font-size:14px;font-weight:700;color:#1f2937;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.step-num{width:28px;height:28px;background:#4f46e5;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0;}

/* Drop zone */
.dz{border:2.5px dashed #c4b5fd;border-radius:12px;padding:44px 20px;text-align:center;background:#faf9ff;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;}
.dz:hover,.dz.drag{border-color:#4f46e5;background:#ede9fe;}
.dz-input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:2;}
.dz i{font-size:44px;color:#4f46e5;display:block;margin-bottom:12px;}
.dz-t{font-size:15px;font-weight:700;color:#374151;margin-bottom:4px;}
.dz-s{font-size:12px;color:#9ca3af;}
.dz-loaded{margin-top:12px;background:#ede9fe;color:#4f46e5;padding:7px 14px;border-radius:20px;font-size:12px;font-weight:700;display:inline-block;}
.tmpl-row{display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-top:12px;}
.tmpl-row i{color:#16a34a;font-size:18px;}
.tmpl-row div strong{font-size:12px;color:#14532d;display:block;}
.tmpl-row div span{font-size:11px;color:#166534;}
.tmpl-row div a{color:#16a34a;font-weight:700;font-size:11px;}

/* Form */
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input[type=date],.fg select{border:1px solid #e5e7eb;border-radius:8px;padding:9px 11px;font-size:13px;color:#1f2937;width:100%;font-family:Arial,sans-serif;background:#fff;}
.fg input:focus,.fg select:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(99,102,241,.1);}
.col-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.sheet-info{font-size:11px;color:#6b7280;background:#f8fafc;border:1px solid #e5e7eb;border-radius:7px;padding:7px 11px;margin-bottom:12px;display:none;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;transition:all .15s;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-blue{background:#4f46e5;color:#fff;}.btn-blue:hover:not(:disabled){background:#3730a3;}
.btn-green{background:#16a34a;color:#fff;}.btn-green:hover:not(:disabled){background:#15803d;}
.btn-gray{background:#f1f5f9;color:#374151;border:1px solid #e5e7eb;}.btn-gray:hover{background:#e2e8f0;}
.btn-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}

/* Stats */
.stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.pill{padding:6px 13px;border-radius:20px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px;}
.p-tot{background:#ede9fe;color:#4f46e5;}
.p-ok{background:#dcfce7;color:#15803d;}
.p-nf{background:#fee2e2;color:#dc2626;}
.p-sk{background:#fef3c7;color:#92400e;}
.p-im{background:#dbeafe;color:#1d4ed8;}
.p-am{background:#fff7ed;color:#c2410c;}

/* Progress */
.prog{background:#ede9fe;border:1px solid #c4b5fd;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:none;}
.prog-t{font-size:13px;font-weight:700;color:#4f46e5;display:flex;align-items:center;gap:7px;margin-bottom:7px;}
.prog-track{background:#ddd6fe;border-radius:10px;height:7px;overflow:hidden;}
.prog-fill{background:#4f46e5;height:100%;border-radius:10px;transition:width .3s;}

/* Results */
.res-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:#1e1b4b;padding:12px 16px;border-radius:12px 12px 0 0;}
.res-header h3{color:#fff;font-size:14px;margin:0;}
.res-body{border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;overflow:hidden;}
.tbl-wrap{overflow-x:auto;max-height:500px;overflow-y:auto;}
table.rt{width:100%;border-collapse:collapse;font-size:12px;}
table.rt thead th{position:sticky;top:0;z-index:1;background:#1e1b4b;color:#e0e7ff;padding:8px 9px;font-size:11px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.07);}
table.rt thead th.r{text-align:right;}
table.rt tbody tr{border-bottom:1px solid #f3f4f6;}
table.rt tbody tr:hover td{background:#f9fafb;}
table.rt tbody td{padding:7px 9px;vertical-align:middle;}
table.rt tbody td.r{text-align:right;}
table.rt tfoot td{padding:8px 9px;font-weight:800;font-size:13px;background:#f8fafc;border-top:2px solid #e2e8f0;}
table.rt tfoot td.r{text-align:right;}
.sb{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:11px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.sb-found{background:#dcfce7;color:#15803d;}
.sb-not_found{background:#fee2e2;color:#dc2626;}
.sb-skipped{background:#fef3c7;color:#92400e;}
.sb-duplicate{background:#fefce8;color:#92400e;}
.sb-imported{background:#dbeafe;color:#1d4ed8;}
.sb-error{background:#fce7f3;color:#9d174d;}
@media(max-width:820px){.two-col,.col-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-icon"><i class="fa-solid fa-file-import"></i></div>
  <div>
    <h1>Bulk Import Credit Notes</h1>
    <p>Upload Excel &rarr; Preview &rarr; Confirm &rarr; Done</p>
  </div>
  <a href="credit_bill_summary2.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="page">

  <div class="two-col">

    <!-- STEP 1: Upload -->
    <div class="card">
      <div class="card-title"><div class="step-num">1</div> Upload Excel File</div>

      <div class="dz" id="dz">
        <input type="file" class="dz-input" id="fileInput" accept=".xlsx,.xls,.csv">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <div class="dz-t">Click here or drag &amp; drop your Excel</div>
        <div class="dz-s">Supports .xlsx &nbsp;&middot;&nbsp; .xls &nbsp;&middot;&nbsp; .csv</div>
        <span id="dzLoaded" class="dz-loaded" style="display:none;"></span>
      </div>

      <div class="tmpl-row">
        <i class="fa-solid fa-file-excel"></i>
        <div>
          <strong>Required columns (any order):</strong>
          <span><b>Invoice Number</b> &middot; <b>CN Value</b> &middot; <b>Reason</b> (optional)</span><br>
          <a href="#" onclick="dlTemplate();return false;">&#11015; Download template</a>
        </div>
      </div>
    </div>

    <!-- STEP 2: Configure -->
    <div class="card">
      <div class="card-title"><div class="step-num">2</div> Map Columns &amp; Set Date</div>

      <div class="fg">
        <label><i class="fa-solid fa-calendar-day"></i> Credit Note Date</label>
        <input type="date" id="noteDate" value="<?php echo date('Y-m-d'); ?>">
      </div>

      <div id="sheetInfo" class="sheet-info"></div>

      <div class="col-grid">
        <div class="fg">
          <label>Invoice No. Column *</label>
          <select id="colInv"><option value="">— select —</option></select>
        </div>
        <div class="fg">
          <label>Amount Column *</label>
          <select id="colAmt"><option value="">— select —</option></select>
        </div>
        <div class="fg">
          <label>Reason Column</label>
          <select id="colRea"><option value="">— none —</option></select>
        </div>
      </div>

      <div class="btn-bar">
        <button class="btn btn-blue" id="previewBtn" onclick="run('preview')" disabled>
          <i class="fa-solid fa-eye"></i> Preview Import
        </button>
        <button class="btn btn-gray" onclick="resetAll()">
          <i class="fa-solid fa-rotate-left"></i> Reset
        </button>
      </div>
    </div>
  </div>

  <!-- STEP 3: Results -->
  <div id="resSec" style="display:none;">
    <div id="statsPills" class="stats"></div>
    <div id="prog" class="prog">
      <div class="prog-t"><i class="fa-solid fa-spinner fa-spin"></i> <span id="progMsg">Importing…</span></div>
      <div class="prog-track"><div class="prog-fill" id="progFill" style="width:8%"></div></div>
    </div>
    <div class="res-header">
      <h3 id="resTitle"></h3>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-green" id="importBtn" style="display:none;" onclick="run('import')">
          <i class="fa-solid fa-file-import"></i> Confirm &amp; Import
        </button>
        <button class="btn btn-gray" id="exportBtn" style="display:none;" onclick="exportCsv()">
          <i class="fa-solid fa-file-csv"></i> Export Log
        </button>
      </div>
    </div>
    <div class="res-body">
      <div class="tbl-wrap">
        <table class="rt">
          <thead>
            <tr>
              <th style="width:36px;text-align:center;">Row</th>
              <th>Invoice</th>
              <th>Customer</th>
              <th class="r">Ikea Value</th>
              <th class="r">CN Amount</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody id="resTbody"></tbody>
          <tfoot>
            <tr>
              <td colspan="4" style="text-align:right;font-size:11px;color:#6b7280;" id="footLbl"></td>
              <td class="r" id="footAmt"></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Toast notification -->
<div id="toast" style="position:fixed;bottom:22px;right:22px;z-index:9999;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:700;color:#fff;box-shadow:0 4px 18px rgba(0,0,0,.22);transform:translateY(120px);transition:transform .3s;display:flex;align-items:center;gap:8px;min-width:180px;"></div>

<script>
var _rows=[], _headers=[], _lastRes=[], _lastAction='';

/* ── File input & drop ── */
(function(){
  var dz=document.getElementById('dz');
  var fi=document.getElementById('fileInput');
  dz.addEventListener('dragover',function(e){e.preventDefault();dz.classList.add('drag');});
  dz.addEventListener('dragleave',function(){dz.classList.remove('drag');});
  dz.addEventListener('drop',function(e){e.preventDefault();dz.classList.remove('drag');var f=e.dataTransfer.files[0];if(f){fi.value='';readFile(f);}});
  fi.addEventListener('change',function(){if(fi.files[0])readFile(fi.files[0]);});
})();

function readFile(file){
  if(!file)return;
  var ext=file.name.split('.').pop().toLowerCase();
  if(['xlsx','xls','csv'].indexOf(ext)<0){toast('Use .xlsx .xls or .csv','error');return;}
  var r=new FileReader();
  r.onerror=function(){toast('Cannot read file','error');};
  r.onload=function(e){
    try{
      var wb=XLSX.read(new Uint8Array(e.target.result),{type:'array'});
      var ws=wb.Sheets[wb.SheetNames[0]];
      var json=XLSX.utils.sheet_to_json(ws,{defval:'',raw:false});
      if(!json.length){toast('File has no data rows','error');return;}
      _rows=json;
      _headers=Object.keys(json[0]);
      fillSels(_headers);
      autoDetect(_headers);
      document.getElementById('dzLoaded').style.display='inline-block';
      document.getElementById('dzLoaded').textContent='Loaded: '+file.name+' ('+json.length+' rows)';
      document.getElementById('sheetInfo').style.display='block';
      document.getElementById('sheetInfo').innerHTML='<b>Sheet:</b> '+wb.SheetNames[0]+' &nbsp;|&nbsp; <b>Rows:</b> '+json.length+' &nbsp;|&nbsp; <b>Columns:</b> '+_headers.join(', ');
      document.getElementById('previewBtn').disabled=false;
      toast('Loaded '+json.length+' rows','success');
    }catch(err){toast('Parse error: '+err.message,'error');console.error(err);}
  };
  r.readAsArrayBuffer(file);
}

function fillSels(h){
  ['colInv','colAmt','colRea'].forEach(function(id,i){
    var s=document.getElementById(id);
    s.innerHTML=i===2?'<option value="">— none (optional) —</option>':'<option value="">— select —</option>';
    h.forEach(function(col){var o=document.createElement('option');o.value=col;o.textContent=col;s.appendChild(o);});
  });
}

function autoDetect(h){
  var n=function(s){return s.toLowerCase().replace(/[\s\-_]/g,'');};
  var find=function(kws){return h.find(function(x){return kws.indexOf(n(x))>=0;})||'';};
  var set=function(id,v){if(v)document.getElementById(id).value=v;};
  set('colInv',find(['invoicenumber','invoiceno','invoice','inv','billno']));
  set('colAmt',find(['cnvalue','creditnotevalue','amount','cn','value','creditnote']));
  set('colRea',find(['reason','remarks','note','description','comment']));
}

function run(action){
  var cI=document.getElementById('colInv').value;
  var cA=document.getElementById('colAmt').value;
  var cR=document.getElementById('colRea').value;
  var nd=document.getElementById('noteDate').value;
  if(!cI){toast('Select Invoice Number column','error');return;}
  if(!cA){toast('Select Amount column','error');return;}
  if(!nd){toast('Select a date','error');return;}
  if(!_rows.length){toast('No file loaded','error');return;}

  var mapped=_rows.map(function(r){
    return{invoice_num:String(r[cI]||'').trim(),amount:parseFloat(r[cA])||0,reason:cR?String(r[cR]||'').trim():''};
  });

  var pBtn=document.getElementById('previewBtn');
  var iBtn=document.getElementById('importBtn');
  pBtn.disabled=true;
  pBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
  if(action==='import'){
    iBtn.disabled=true;
    iBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    document.getElementById('prog').style.display='block';
    document.getElementById('progFill').style.width='12%';
    document.getElementById('progMsg').textContent='Importing '+mapped.length+' rows…';
  }

  var fd=new FormData();
  fd.append('ajax_action',action);
  fd.append('note_date',nd);
  fd.append('rows',JSON.stringify(mapped));

  fetch(window.location.href,{method:'POST',body:fd})
    .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();})
    .then(function(txt){
      pBtn.disabled=false;
      pBtn.innerHTML='<i class="fa-solid fa-eye"></i> Preview Import';
      document.getElementById('prog').style.display='none';
      if(iBtn){iBtn.disabled=false;iBtn.innerHTML='<i class="fa-solid fa-file-import"></i> Confirm &amp; Import';}
      var data;
      try{data=JSON.parse(txt);}
      catch(e){toast('Server error — open console for details','error');console.error('RAW:',txt);return;}
      if(!data.success){toast(data.error||'Server error','error');return;}
      _lastAction=action;_lastRes=data.results;
      showResults(data);
    })
    .catch(function(err){
      pBtn.disabled=false;
      pBtn.innerHTML='<i class="fa-solid fa-eye"></i> Preview Import';
      document.getElementById('prog').style.display='none';
      if(iBtn){iBtn.disabled=false;iBtn.innerHTML='<i class="fa-solid fa-file-import"></i> Confirm &amp; Import';}
      toast('Network error: '+err.message,'error');
      console.error(err);
    });
}

function showResults(data){
  var isImp=(data.action==='import');
  document.getElementById('resSec').style.display='block';
  setTimeout(function(){document.getElementById('resSec').scrollIntoView({behavior:'smooth'});},80);

  document.getElementById('resTitle').innerHTML='<i class="fa-solid fa-'+(isImp?'circle-check':'eye')+'"></i> '+(isImp?'Import Complete — '+data.inserted+' saved':'Preview — '+data.found+' matched, '+data.not_found+' not found');
  document.getElementById('importBtn').style.display=isImp?'none':'inline-flex';
  document.getElementById('exportBtn').style.display='inline-flex';

  var fmtM=function(v){return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});};
  var totAmt=0;
  data.results.forEach(function(r){if(r.status==='found'||r.status==='imported')totAmt+=parseFloat(r.amount||0);});

  document.getElementById('statsPills').innerHTML=
    '<div class="pill p-tot"><i class="fa-solid fa-list"></i> Total: '+data.total+'</div>'+
    '<div class="pill p-ok"><i class="fa-solid fa-circle-check"></i> Matched: '+data.found+'</div>'+
    '<div class="pill p-nf"><i class="fa-solid fa-circle-xmark"></i> Not found: '+data.not_found+'</div>'+
    '<div class="pill p-sk"><i class="fa-solid fa-forward"></i> Skipped: '+data.skipped+'</div>'+
    (isImp?'<div class="pill p-im"><i class="fa-solid fa-file-import"></i> Imported: '+data.inserted+'</div>':'')+
    '<div class="pill p-am"><i class="fa-solid fa-coins"></i> CN Total: '+fmtM(totAmt)+'</div>';

  var sbMap={found:'sb-found',not_found:'sb-not_found',skipped:'sb-skipped',duplicate:'sb-duplicate',imported:'sb-imported',error:'sb-error'};
  var sbLbl={found:'&#10003; Matched',not_found:'&#10007; Not Found',skipped:'&#8856; Skipped',duplicate:'Duplicate',imported:'&#10003; Imported',error:'Error'};
  var tbody=document.getElementById('resTbody');
  tbody.innerHTML='';
  var runAmt=0;
  var matchCnt=0;
  data.results.forEach(function(r){
    var a=parseFloat(r.amount||0);
    if(r.status==='found'||r.status==='imported'){runAmt+=a;matchCnt++;}
    var sc=sbMap[r.status]||'';
    var sl=sbLbl[r.status]||r.status;
    var tr=document.createElement('tr');
    tr.innerHTML=
      '<td style="color:#9ca3af;font-size:11px;text-align:center;">'+r.row+'</td>'+
      '<td style="font-family:monospace;font-weight:700;color:#4338ca;">'+esc(r.invoice)+'</td>'+
      '<td style="font-size:12px;">'+esc(r.customer||'—')+'</td>'+
      '<td class="r" style="color:#6b7280;white-space:nowrap;">'+(r.net_value?fmtM(r.net_value):'—')+'</td>'+
      '<td class="r" style="font-weight:700;color:#c2410c;white-space:nowrap;">'+(a>0?fmtM(a):'—')+'</td>'+
      '<td style="font-size:11px;color:#6b7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.reason)+'">'+esc(r.reason||'—')+'</td>'+
      '<td><span class="sb '+sc+'">'+sl+'</span></td>'+
      '<td style="font-size:11px;color:#6b7280;">'+esc(r.msg||'')+'</td>';
    tbody.appendChild(tr);
  });
  document.getElementById('footLbl').textContent=(isImp?'IMPORTED':'MATCHED')+' '+matchCnt+' of '+data.total;
  document.getElementById('footAmt').textContent=fmtM(runAmt);
  toast(isImp?'Done! '+data.inserted+' credit notes imported':'Preview: '+data.found+' matched',isImp?'success':'success');
}

function exportCsv(){
  if(!_lastRes.length){toast('No results','error');return;}
  var lines=[['Row','Invoice','Customer','Ikea Value','CN Amount','Reason','Status','Message'].join(',')];
  _lastRes.forEach(function(r){
    var e=function(v){return'"'+String(v||'').replace(/"/g,'""')+'"';};
    lines.push([r.row,e(r.invoice),e(r.customer||''),parseFloat(r.net_value||0).toFixed(2),parseFloat(r.amount||0).toFixed(2),e(r.reason),r.status,e(r.msg)].join(','));
  });
  var a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
  a.download='cn_import_log_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}

function dlTemplate(){
  var wb=XLSX.utils.book_new();
  var ws=XLSX.utils.aoa_to_sheet([
    ['No','Invoice Number','CN Value','Reason'],
    [1,'26016676',0.01,'Not Material'],
    [2,'26022927',15.50,'Price Difference'],
    [3,'26016289',5.00,'Damage']
  ]);
  ws['!cols']=[{wch:5},{wch:18},{wch:12},{wch:30}];
  XLSX.utils.book_append_sheet(wb,ws,'Credit Notes');
  XLSX.writeFile(wb,'credit_notes_template.xlsx');
}

function resetAll(){
  _rows=[];_headers=[];_lastRes=[];_lastAction='';
  document.getElementById('fileInput').value='';
  document.getElementById('dzLoaded').style.display='none';
  document.getElementById('sheetInfo').style.display='none';
  document.getElementById('previewBtn').disabled=true;
  document.getElementById('resSec').style.display='none';
  document.getElementById('statsPills').innerHTML='';
  ['colInv','colAmt','colRea'].forEach(function(id){document.getElementById(id).innerHTML='<option value="">— select —</option>';});
  toast('Reset','success');
}

function esc(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}

function toast(msg,type){
  var t=document.getElementById('toast');
  t.style.background=type==='error'?'#dc2626':'#166534';
  t.innerHTML=(type==='error'?'<i class="fa-solid fa-circle-exclamation"></i>':'<i class="fa-solid fa-circle-check"></i>')+' '+msg;
  t.style.transform='translateY(0)';
  clearTimeout(t._tmr);
  t._tmr=setTimeout(function(){t.style.transform='translateY(120px)';},4000);
}
</script>
</body>
</html>