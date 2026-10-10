<?php
/**
 * upload_signature.php  —  Auto-detect & save signature + seal from A4 scan
 */
include 'config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) { header('Location: customers.php'); exit; }

$customer_res = mysqli_query($conn, "SELECT * FROM customers WHERE id = $id");
if (!$customer_res || mysqli_num_rows($customer_res) === 0) { header('Location: customers.php'); exit; }
$customer = mysqli_fetch_assoc($customer_res);

$upload_dir = 'uploads/customers/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

/* ── AJAX: save base64 crops ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_crops') {
    function saveB64($b64, $dir, $prefix) {
        if (preg_match('/^data:image\/(\w+);base64,/', $b64, $m)) {
            $ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64));
            $path = $dir . $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
            if (file_put_contents($path, $data)) return $path;
        }
        return '';
    }
    $res = ['ok' => false];
    $parts = [];
    if (!empty($_POST['signature_data'])) {
        $old = $customer['customer_signature'];
        if ($old && file_exists($old)) unlink($old);
        $p = saveB64($_POST['signature_data'], $upload_dir, 'signature');
        if ($p) { $e = mysqli_real_escape_string($conn,$p); $parts[] = "customer_signature='$e'"; $res['sig']=$p; }
    }
    if (!empty($_POST['seal_data'])) {
        $old = $customer['customer_seal'];
        if ($old && file_exists($old)) unlink($old);
        $p = saveB64($_POST['seal_data'], $upload_dir, 'seal');
        if ($p) { $e = mysqli_real_escape_string($conn,$p); $parts[] = "customer_seal='$e'"; $res['seal']=$p; }
    }
    if ($parts) {
        mysqli_query($conn, "UPDATE customers SET ".implode(',',$parts)." WHERE id=$id");
        $res['ok'] = true;
    } else { $res['error'] = 'No data.'; }
    header('Content-Type: application/json');
    echo json_encode($res); exit;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auto Scan — <?php echo htmlspecialchars($customer['shop_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --ink:#0d0d0d;--paper:#f8f7f5;--cream:#f0ede8;
  --stroke:#ddd8d0;--muted:#8a847a;
  --blue:#1a3a8f;--red:#b83232;--green:#1a6641;
  --r:10px;--sans:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--paper);color:var(--ink);min-height:100vh}

.hdr{background:var(--ink);color:#fff;padding:18px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.hdr-l{display:flex;align-items:center;gap:14px}
.hdr-icon{width:42px;height:42px;border-radius:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:17px}
.hdr h1{font-size:15px;font-weight:600}
.hdr p{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px}
.badge{display:inline-block;padding:1px 9px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:20px;font-size:10px;font-family:var(--mono)}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:6px;font-size:12px;font-weight:600;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.18);color:#fff;text-decoration:none;transition:.2s}
.btn-back:hover{background:rgba(255,255,255,.16)}

.wrap{max-width:920px;margin:0 auto;padding:28px 18px 60px}

.card{background:#fff;border:1px solid var(--stroke);border-radius:var(--r);margin-bottom:18px;overflow:hidden}
.card-head{padding:13px 18px;border-bottom:1px solid var(--stroke);background:var(--cream);display:flex;align-items:center;justify-content:space-between}
.card-head h2{font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px}
.card-head h2 i{color:var(--muted)}
.card-body{padding:22px 18px}

.drop-zone{border:2px dashed var(--stroke);border-radius:var(--r);padding:52px 20px;text-align:center;cursor:pointer;transition:.25s;background:var(--cream);position:relative}
.drop-zone.drag{border-color:var(--blue);background:#edf0f9}
.drop-zone input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}
.drop-zone .big{font-size:42px;color:var(--muted);margin-bottom:14px;display:block}
.drop-zone h3{font-size:15px;font-weight:600;margin-bottom:5px}
.drop-zone p{font-size:12px;color:var(--muted)}
.auto-badge{display:inline-flex;align-items:center;gap:5px;margin-top:14px;padding:5px 14px;background:var(--blue);color:#fff;border-radius:20px;font-size:11px;font-weight:600}

.processing{display:none;flex-direction:column;align-items:center;justify-content:center;padding:52px;gap:18px}
.processing.show{display:flex}
.spinner{width:50px;height:50px;border:3px solid var(--stroke);border-top-color:var(--blue);border-radius:50%;animation:spin .75s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.proc-msg{font-size:13px;font-weight:500;color:var(--muted)}
.proc-steps{display:flex;gap:0;margin-top:4px}
.proc-dot{width:6px;height:6px;border-radius:50%;background:var(--stroke);animation:blink 1.2s infinite;margin:0 3px}
.proc-dot:nth-child(2){animation-delay:.2s}.proc-dot:nth-child(3){animation-delay:.4s}
@keyframes blink{0%,80%,100%{opacity:.2}40%{opacity:1}}

.results-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px}
@media(max-width:560px){.results-grid{grid-template-columns:1fr}}

.result-box{border:1px solid var(--stroke);border-radius:var(--r);overflow:hidden;background:#fff}
.result-box-head{padding:11px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--stroke);background:var(--cream)}
.result-box-head .rtitle{font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px}
.result-box-head .dim{font-size:10px;color:var(--muted);font-family:var(--mono)}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.dot-sig{background:var(--blue)}.dot-seal{background:var(--red)}

.crop-wrap{padding:16px;background:#fafafa;min-height:140px;display:flex;align-items:center;justify-content:center}
.crop-wrap canvas{max-width:100%;max-height:150px;display:block;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.none-msg{text-align:center;color:var(--muted);font-size:11px}
.none-msg i{font-size:26px;display:block;margin-bottom:7px;opacity:.3}

.result-foot{padding:9px 14px;border-top:1px solid var(--stroke);display:flex;align-items:center;gap:8px}
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:600;font-family:var(--mono)}
.chip-ok{background:#dcfce7;color:var(--green)}.chip-na{background:var(--cream);color:var(--muted)}

.save-area{margin-top:20px;padding:18px;background:var(--cream);border-radius:var(--r);border:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.save-area-text h3{font-size:13px;font-weight:600;margin-bottom:3px}
.save-area-text p{font-size:11px;color:var(--muted)}
.save-btns{display:flex;gap:10px;flex-wrap:wrap}

.existing-row{display:flex;gap:14px;flex-wrap:wrap}
.existing-box{flex:1;min-width:180px;border:1px solid var(--stroke);border-radius:8px;overflow:hidden}
.existing-box .eb-head{padding:9px 12px;background:var(--cream);border-bottom:1px solid var(--stroke);font-size:11px;font-weight:600;display:flex;align-items:center;gap:6px}
.existing-box .eb-body{padding:12px;min-height:70px;display:flex;align-items:center;justify-content:center}
.existing-box img{max-width:100%;max-height:80px;object-fit:contain}
.eb-none{font-size:11px;color:var(--muted)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:12px;font-weight:600;font-family:var(--sans);border:none;cursor:pointer;transition:.2s;text-decoration:none}
.btn-success{background:var(--green);color:#fff}.btn-success:hover{opacity:.88}
.btn-ghost{background:var(--cream);color:var(--ink);border:1px solid var(--stroke)}.btn-ghost:hover{background:var(--stroke)}
.btn:disabled{opacity:.4;cursor:not-allowed}

.preview-label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px}

.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--ink);color:#fff;padding:11px 20px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:flex;align-items:center;gap:9px;box-shadow:0 8px 28px rgba(0,0,0,.22);transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s;opacity:0}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.ok{background:var(--green)}.toast.err{background:var(--red)}

#workCanvas,#sigRaw,#sealRaw{display:none;position:absolute;left:-9999px}
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-l">
    <div class="hdr-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
    <div>
      <h1>Auto Signature &amp; Seal Scanner</h1>
      <p><?php echo htmlspecialchars($customer['shop_name']); ?>&nbsp;<span class="badge"><?php echo htmlspecialchars($customer['t_code']); ?></span></p>
    </div>
  </div>
  <a href="edit_customer.php?id=<?php echo $id; ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="wrap">

  <!-- Current stored -->
  <div class="card">
    <div class="card-head"><h2><i class="fa-solid fa-images"></i> Currently Stored</h2></div>
    <div class="card-body">
      <div class="existing-row">
        <div class="existing-box">
          <div class="eb-head"><span class="dot dot-sig"></span>Signature</div>
          <div class="eb-body" id="curSigBox">
            <?php if(!empty($customer['customer_signature'])&&file_exists($customer['customer_signature'])): ?>
              <img src="<?php echo htmlspecialchars($customer['customer_signature']); ?>">
            <?php else: ?><span class="eb-none">No signature on file</span><?php endif; ?>
          </div>
        </div>
        <div class="existing-box">
          <div class="eb-head"><span class="dot dot-seal"></span>Seal</div>
          <div class="eb-body" id="curSealBox">
            <?php if(!empty($customer['customer_seal'])&&file_exists($customer['customer_seal'])): ?>
              <img src="<?php echo htmlspecialchars($customer['customer_seal']); ?>">
            <?php else: ?><span class="eb-none">No seal on file</span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Upload -->
  <div id="uploadCard" class="card">
    <div class="card-head"><h2><i class="fa-solid fa-file-arrow-up"></i> Upload A4 Scanned Document</h2></div>
    <div class="card-body">
      <div class="drop-zone" id="dropZone">
        <input type="file" id="fileInput" accept="image/*">
        <i class="big fa-solid fa-file-image"></i>
        <h3>Drop your A4 scan here</h3>
        <p>Supports JPG, PNG, WEBP — flatbed scan or camera photo</p>
        <div class="auto-badge"><i class="fa-solid fa-bolt"></i> Fully automatic — no manual selection</div>
      </div>
    </div>
  </div>

  <!-- Processing -->
  <div id="processingCard" class="card" style="display:none">
    <div class="card-body">
      <div class="processing show">
        <div class="spinner"></div>
        <p class="proc-msg" id="procMsg">Analysing scan...</p>
        <div class="proc-steps">
          <div class="proc-dot"></div><div class="proc-dot"></div><div class="proc-dot"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Results -->
  <div id="resultsCard" class="card" style="display:none">
    <div class="card-head">
      <h2><i class="fa-solid fa-check-double"></i> Auto-Detected Results</h2>
      <button class="btn btn-ghost" style="font-size:11px;padding:6px 12px" onclick="resetAll()">
        <i class="fa-solid fa-rotate-left"></i> New Scan
      </button>
    </div>
    <div class="card-body">

      <p class="preview-label">Original Scan — detected regions highlighted</p>
      <canvas id="previewCanvas" style="max-width:100%;border:1px solid var(--stroke);border-radius:8px;display:block"></canvas>

      <div class="results-grid">

        <div class="result-box">
          <div class="result-box-head">
            <span class="rtitle"><span class="dot dot-sig"></span>Signature</span>
            <span class="dim" id="sigDim">—</span>
          </div>
          <div class="crop-wrap">
            <div class="none-msg" id="sigNone"><i class="fa-solid fa-triangle-exclamation"></i>Not detected</div>
            <canvas id="sigCanvas" style="display:none"></canvas>
          </div>
          <div class="result-foot"><span class="chip chip-na" id="sigChip">Not found</span></div>
        </div>

        <div class="result-box">
          <div class="result-box-head">
            <span class="rtitle"><span class="dot dot-seal"></span>Seal</span>
            <span class="dim" id="sealDim">—</span>
          </div>
          <div class="crop-wrap">
            <div class="none-msg" id="sealNone"><i class="fa-solid fa-triangle-exclamation"></i>Not detected</div>
            <canvas id="sealCanvas" style="display:none"></canvas>
          </div>
          <div class="result-foot"><span class="chip chip-na" id="sealChip">Not found</span></div>
        </div>

      </div>

      <div class="save-area">
        <div class="save-area-text">
          <h3>Save to Customer Record</h3>
          <p id="saveHint">Detected images are ready to save.</p>
        </div>
        <div class="save-btns">
          <button class="btn btn-success" id="saveBtn" onclick="saveCrops()">
            <i class="fa-solid fa-floppy-disk"></i> Save Now
          </button>
          <button class="btn btn-ghost" onclick="downloadAll()">
            <i class="fa-solid fa-download"></i> Download
          </button>
        </div>
      </div>

    </div>
  </div>

</div><!-- /.wrap -->

<!-- Off-screen work canvas -->
<canvas id="workCanvas" style="display:none;position:absolute;left:-9999px"></canvas>

<div class="toast" id="toast"><i id="toastIco" class="fa-solid fa-circle-check"></i><span id="toastTxt"></span></div>

<script>
/* ================================================================
   CONFIG
================================================================ */
const WHITE_THRESH = 230;   // brightness >= this → white/background
const MIN_SIZE     = 30;    // minimum px in each dimension to count
const PAD          = 18;    // padding around auto-trimmed content (px)
const OUTPUT_SCALE = 2;     // render crops at 2× source for sharpness

/* ================================================================
   Globals
================================================================ */
let sigB64  = null;
let sealB64 = null;

/* ================================================================
   Drop / file input
================================================================ */
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('dragover',  e=>{e.preventDefault();dropZone.classList.add('drag')});
dropZone.addEventListener('dragleave', ()=>dropZone.classList.remove('drag'));
dropZone.addEventListener('drop', e=>{
  e.preventDefault(); dropZone.classList.remove('drag');
  const f = e.dataTransfer.files[0];
  if (f && f.type.startsWith('image/')) loadFile(f);
});
fileInput.addEventListener('change', ()=>{ if(fileInput.files[0]) loadFile(fileInput.files[0]); });

/* ================================================================
   Load → detect → render
================================================================ */
function loadFile(file) {
  setStep('processing');
  msg('Loading image...');
  const reader = new FileReader();
  reader.onload = e => {
    const img = new Image();
    img.onload = () => runPipeline(img);
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

async function runPipeline(img) {
  await tick();

  /* 1. Draw full image to work canvas */
  msg('Rendering scan...');
  const work = document.getElementById('workCanvas');
  const W = img.naturalWidth, H = img.naturalHeight;
  work.width = W; work.height = H;
  const wCtx = work.getContext('2d');
  wCtx.fillStyle = '#ffffff';
  wCtx.fillRect(0, 0, W, H);
  wCtx.drawImage(img, 0, 0);
  await tick();

  /* 2. Read pixels */
  msg('Scanning pixels...');
  const imgData = wCtx.getImageData(0, 0, W, H);
  const px = imgData.data;

  /* 3. Build ink map (1 = ink, 0 = white/near-white) */
  const ink = new Uint8Array(W * H);
  for (let i = 0; i < W * H; i++) {
    const r = px[i*4], g = px[i*4+1], b = px[i*4+2];
    const brightness = (r + g + b) / 3;
    ink[i] = brightness < WHITE_THRESH ? 1 : 0;
  }
  await tick();

  /* 4. Compute per-column ink density */
  msg('Locating regions...');
  const colDensity = new Float32Array(W);
  for (let y = 0; y < H; y++)
    for (let x = 0; x < W; x++)
      if (ink[y * W + x]) colDensity[x]++;

  /* 5. Smooth column density */
  const winSize = Math.max(10, Math.floor(W * 0.025));
  const smDensity = smoothArray(colDensity, winSize);

  /* 6. Find the valley (least ink) in the middle 40% of the page
        → this is the dividing line between signature and seal */
  const searchL = Math.floor(W * 0.25);
  const searchR = Math.floor(W * 0.75);
  let valleyX = Math.floor(W * 0.5), valleyV = Infinity;
  for (let x = searchL; x < searchR; x++) {
    if (smDensity[x] < valleyV) { valleyV = smDensity[x]; valleyX = x; }
  }

  /* 7. Find tight content bounding-box in each half */
  const sigBBox  = contentBBox(ink, W, H,  0,       0, valleyX, H);
  const sealBBox = contentBBox(ink, W, H,  valleyX, 0, W,       H);
  await tick();

  /* 8. Render crops onto their canvases */
  msg('Extracting content...');
  let sigOk = false, sealOk = false;

  if (sigBBox && sigBBox.w >= MIN_SIZE && sigBBox.h >= MIN_SIZE) {
    sigOk  = true;
    sigB64 = renderCrop(wCtx, sigBBox, 'sigCanvas', 'sigDim', 'sigChip', 'sigNone');
  }
  if (sealBBox && sealBBox.w >= MIN_SIZE && sealBBox.h >= MIN_SIZE) {
    sealOk  = true;
    sealB64 = renderCrop(wCtx, sealBBox, 'sealCanvas', 'sealDim', 'sealChip', 'sealNone');
  }
  await tick();

  /* 9. Draw annotated preview */
  msg('Drawing preview...');
  const prev = document.getElementById('previewCanvas');
  prev.width = W; prev.height = H;
  prev.style.maxWidth = '100%';
  const pCtx = prev.getContext('2d');
  pCtx.drawImage(img, 0, 0);

  const lw = Math.max(4, Math.floor(W * 0.003));
  if (sigOk)  drawBox(pCtx, sigBBox,  '#1a3a8f', lw, 'SIGNATURE', W);
  if (sealOk) drawBox(pCtx, sealBBox, '#b83232', lw, 'SEAL',      W);

  /* 10. Show results */
  setStep('results');
  if (!sigOk && !sealOk) {
    document.getElementById('saveHint').textContent = 'No ink detected. Try a higher-contrast or higher-resolution scan.';
    document.getElementById('saveBtn').disabled = true;
  }
}

/* ================================================================
   Helpers
================================================================ */

/** Tight axis-aligned bounding box of all ink pixels in region */
function contentBBox(ink, W, H, x0, y0, x1, y1) {
  x0 = Math.max(0,x0); y0 = Math.max(0,y0);
  x1 = Math.min(W,x1); y1 = Math.min(H,y1);

  let top=y1, bot=y0, left=x1, right=x0, found=false;
  for (let y=y0; y<y1; y++) {
    for (let x=x0; x<x1; x++) {
      if (ink[y*W+x]) {
        found = true;
        if (y < top)   top   = y;
        if (y > bot)   bot   = y;
        if (x < left)  left  = x;
        if (x > right) right = x;
      }
    }
  }
  if (!found) return null;

  const bx = Math.max(0,    left  - PAD);
  const by = Math.max(0,    top   - PAD);
  const bw = Math.min(W, right + PAD) - bx;
  const bh = Math.min(H, bot   + PAD) - by;
  return { x:bx, y:by, w:bw, h:bh };
}

/** Render a crop from the source canvas → destination canvas, return base64 */
function renderCrop(srcCtx, bbox, canvasId, dimId, chipId, noneId) {
  const dest = document.getElementById(canvasId);
  const dCtx = dest.getContext('2d');

  // Scale up for quality but cap width at 1400px
  const scale = Math.min(OUTPUT_SCALE, 1400 / bbox.w);
  dest.width  = Math.round(bbox.w * scale);
  dest.height = Math.round(bbox.h * scale);

  // White fill
  dCtx.fillStyle = '#ffffff';
  dCtx.fillRect(0, 0, dest.width, dest.height);

  // Crop
  dCtx.drawImage(srcCtx.canvas, bbox.x, bbox.y, bbox.w, bbox.h, 0, 0, dest.width, dest.height);

  // Show
  dest.style.display = 'block';
  document.getElementById(noneId).style.display = 'none';
  document.getElementById(dimId).textContent = bbox.w + '×' + bbox.h;
  const chip = document.getElementById(chipId);
  chip.textContent = 'Detected'; chip.className = 'chip chip-ok';

  return dest.toDataURL('image/png');
}

/** Draw a labelled bounding box on the preview canvas */
function drawBox(ctx, bbox, color, lw, label, W) {
  ctx.strokeStyle = color;
  ctx.lineWidth   = lw;
  ctx.setLineDash([]);
  ctx.strokeRect(bbox.x, bbox.y, bbox.w, bbox.h);

  // Fill a small label strip above the box
  const fs = Math.max(16, Math.floor(W * 0.015));
  ctx.font      = `bold ${fs}px Sora,sans-serif`;
  ctx.fillStyle = color;
  const ty = Math.max(fs + 4, bbox.y - 6);
  ctx.fillText(label, Math.max(4, bbox.x + 4), ty);
}

/** Smooth a Float32Array with a box filter of half-width win */
function smoothArray(arr, win) {
  const out = new Float32Array(arr.length);
  for (let i = 0; i < arr.length; i++) {
    let s = 0, c = 0;
    for (let k = -win; k <= win; k++) {
      const j = i + k;
      if (j >= 0 && j < arr.length) { s += arr[j]; c++; }
    }
    out[i] = s / c;
  }
  return out;
}

/** Yield to browser so spinner can repaint */
function tick() { return new Promise(r => setTimeout(r, 25)); }

/* ================================================================
   Save to server
================================================================ */
function saveCrops() {
  if (!sigB64 && !sealB64) { toast('Nothing to save.', 'err'); return; }
  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

  const fd = new FormData();
  fd.append('action', 'save_crops');
  if (sigB64)  fd.append('signature_data', sigB64);
  if (sealB64) fd.append('seal_data',      sealB64);

  fetch('upload_signature.php?id=<?php echo $id; ?>', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Now';
      if (d.ok) {
        toast('Saved successfully!', 'ok');
        if (sigB64)  document.getElementById('curSigBox').innerHTML  = `<img src="${sigB64}"  style="max-width:100%;max-height:80px;object-fit:contain">`;
        if (sealB64) document.getElementById('curSealBox').innerHTML = `<img src="${sealB64}" style="max-width:100%;max-height:80px;object-fit:contain">`;
        document.getElementById('saveHint').textContent = '✓ Saved to customer record.';
      } else {
        toast('Error: ' + (d.error || 'unknown'), 'err');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Now';
      toast('Network error.', 'err');
    });
}

function downloadAll() {
  const dl = (b64, name) => {
    if (!b64) return;
    const a = document.createElement('a'); a.href = b64; a.download = name; a.click();
  };
  dl(sigB64,  'signature_<?php echo $customer["t_code"]; ?>.png');
  dl(sealB64, 'seal_<?php echo $customer["t_code"]; ?>.png');
}

/* ================================================================
   UI helpers
================================================================ */
function setStep(s) {
  document.getElementById('uploadCard').style.display     = s === 'upload'     ? '' : 'none';
  document.getElementById('processingCard').style.display = s === 'processing' ? '' : 'none';
  document.getElementById('resultsCard').style.display    = s === 'results'    ? '' : 'none';
}
function msg(m) { document.getElementById('procMsg').textContent = m; }

function resetAll() {
  sigB64 = sealB64 = null;
  ['sigCanvas','sealCanvas'].forEach(id => {
    const c = document.getElementById(id);
    c.style.display = 'none';
    c.getContext('2d').clearRect(0,0,c.width,c.height);
  });
  ['sigNone','sealNone'].forEach(id  => document.getElementById(id).style.display = '');
  ['sigChip','sealChip'].forEach(id  => { const e=document.getElementById(id); e.textContent='Not found'; e.className='chip chip-na'; });
  ['sigDim','sealDim'].forEach(id    => document.getElementById(id).textContent = '—');
  document.getElementById('saveBtn').disabled = false;
  document.getElementById('saveBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Now';
  document.getElementById('saveHint').textContent = 'Detected images are ready to save.';
  fileInput.value = '';
  setStep('upload');
}

function toast(text, type) {
  const t = document.getElementById('toast');
  const ico = document.getElementById('toastIco');
  t.className = 'toast ' + (type==='ok' ? 'ok' : type==='err' ? 'err' : '');
  ico.className = 'fa-solid ' + (type==='ok' ? 'fa-circle-check' : type==='err' ? 'fa-circle-xmark' : 'fa-circle-info');
  document.getElementById('toastTxt').textContent = text;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3400);
}

setStep('upload');
</script>
</body>
</html>
<?php include 'footer.php'; ?>