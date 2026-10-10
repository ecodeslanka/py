<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════
   Company Letterhead — Header, Footer & E-Signature Management
   Table: company_settings  (key VARCHAR PRIMARY KEY, value TEXT, updated_at DATETIME)
   Keys used:
     letterhead_header   → path to header image
     letterhead_footer   → path to footer image
     letterhead_esign    → path to e-signature image
     letterhead_company  → company display name (optional)
════════════════════════════════════════════════════════ */

$msg      = '';
$msg_type = '';

$upload_dir = 'uploads/letterhead/';

/* ── Ensure upload directory exists ── */
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

/* ── Ensure table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS company_settings (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* ─────────────────────────────────────────────
   Helper: save a file upload & update DB
────────────────────────────────────────────── */
function save_upload($conn, $file_key, $db_key, $upload_dir, &$msg, &$msg_type) {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) return;

    $f = $_FILES[$file_key];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Upload error (code ' . $f['error'] . ').'; $msg_type = 'error'; return;
    }

    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mime)) {
        $msg = 'Invalid file type for ' . $db_key . '. Only JPG, PNG, WebP allowed.'; $msg_type = 'error'; return;
    }

    $max_bytes = 5 * 1024 * 1024;
    if ($f['size'] > $max_bytes) {
        $msg = 'File too large (max 5 MB).'; $msg_type = 'error'; return;
    }

    $ext      = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $filename = $db_key . '_' . time() . '.' . $ext;
    $dest     = $upload_dir . $filename;

    /* Remove old file if exists */
    $res_old = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$db_key'");
    if ($res_old && $row_old = mysqli_fetch_assoc($res_old)) {
        if ($row_old['value'] && file_exists($row_old['value'])) {
            @unlink($row_old['value']);
        }
    }

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $msg = 'Failed to move uploaded file.'; $msg_type = 'error'; return;
    }

    $esc = mysqli_real_escape_string($conn, $dest);
    mysqli_query($conn, "INSERT INTO company_settings (`key`,`value`) VALUES ('$db_key','$esc')
                         ON DUPLICATE KEY UPDATE `value`='$esc', updated_at=NOW()");
}

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_images') {
        save_upload($conn, 'header_img', 'letterhead_header', $upload_dir, $msg, $msg_type);
        if ($msg_type !== 'error') {
            save_upload($conn, 'footer_img', 'letterhead_footer', $upload_dir, $msg, $msg_type);
        }
        if ($msg_type !== 'error') {
            save_upload($conn, 'esign_img', 'letterhead_esign', $upload_dir, $msg, $msg_type);
        }
        if ($msg_type !== 'error' && isset($_POST['company_name'])) {
            $cn = mysqli_real_escape_string($conn, trim($_POST['company_name']));
            mysqli_query($conn, "INSERT INTO company_settings (`key`,`value`) VALUES ('letterhead_company','$cn')
                                 ON DUPLICATE KEY UPDATE `value`='$cn', updated_at=NOW()");
        }
        if ($msg_type !== 'error') { $msg = 'Settings saved successfully.'; $msg_type = 'success'; }
    }

    if ($_POST['action'] === 'delete_image') {
        $allowed_keys = ['letterhead_header', 'letterhead_footer', 'letterhead_esign'];
        $dk = in_array($_POST['img_key'] ?? '', $allowed_keys) ? $_POST['img_key'] : null;
        if ($dk) {
            $res_d = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$dk'");
            if ($res_d && $row_d = mysqli_fetch_assoc($res_d)) {
                if ($row_d['value'] && file_exists($row_d['value'])) @unlink($row_d['value']);
            }
            mysqli_query($conn, "DELETE FROM company_settings WHERE `key`='$dk'");
            $msg = 'Image removed successfully.'; $msg_type = 'success';
        }
    }
}

/* ── Fetch current values ── */
function get_setting($conn, $key) {
    $k   = mysqli_real_escape_string($conn, $key);
    $res = mysqli_query($conn, "SELECT `value`, updated_at FROM company_settings WHERE `key`='$k'");
    return $res ? (mysqli_fetch_assoc($res) ?: ['value'=>'','updated_at'=>'']) : ['value'=>'','updated_at'=>''];
}

$header_row   = get_setting($conn, 'letterhead_header');
$footer_row   = get_setting($conn, 'letterhead_footer');
$esign_row    = get_setting($conn, 'letterhead_esign');
$company_row  = get_setting($conn, 'letterhead_company');

$header_path  = $header_row['value'];
$footer_path  = $footer_row['value'];
$esign_path   = $esign_row['value'];
$company_name = $company_row['value'];

$has_header   = $header_path && file_exists($header_path);
$has_footer   = $footer_path && file_exists($footer_path);
$has_esign    = $esign_path  && file_exists($esign_path);

function img_info($path) {
    if (!$path || !file_exists($path)) return '';
    $sz   = filesize($path);
    $kb   = $sz > 1048576 ? round($sz/1048576,1).' MB' : round($sz/1024).' KB';
    $info = @getimagesize($path);
    $dim  = $info ? $info[0].'×'.$info[1].' px' : '';
    return $dim ? "$dim · $kb" : $kb;
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root {
    --bg:#eef0f3; --surface:#fff; --bdr:#d2d6db; --bdrs:#e4e7ec;
    --tx:#1a1f2e; --txm:#58626e; --txs:#9aa3ad;
    --fn:'Inter',sans-serif; --mn:'JetBrains Mono',monospace;
    --r:8px; --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --pri:#1e40af; --pri-l:#eff6ff; --pri-b:#bfdbfe;
    --grn:#166534; --grn-l:#f0fdf4; --grn-b:#86efac;
    --red:#dc2626; --red-l:#fef2f2; --red-b:#fca5a5;
    --amb:#b45309; --amb-l:#fffbeb; --amb-b:#fde68a;
    --teal:#0f766e; --teal-l:#f0fdfa; --teal-b:#99f6e4;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;max-width:920px;}

.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--pri);font-style:normal;}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;}

.bcrumb{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--txs);margin-bottom:16px;}
.bcrumb a{color:var(--txs);text-decoration:none;}.bcrumb a:hover{color:var(--tx);}
.bcrumb .sep{color:#d1d5db;}

.alert{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:var(--r);font-size:13px;font-weight:600;margin-bottom:16px;border:1px solid;}
.alert.success{background:var(--grn-l);color:var(--grn);border-color:var(--grn-b);}
.alert.error  {background:var(--red-l);color:var(--red);border-color:var(--red-b);}

.card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;margin-bottom:16px;}
.card-head{display:flex;align-items:center;gap:13px;padding:16px 20px;border-bottom:1px solid var(--bdrs);}
.ch-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ch-icon.blue  {background:linear-gradient(135deg,#eff6ff,#dbeafe);color:#1d4ed8;}
.ch-icon.amber {background:linear-gradient(135deg,#fffbeb,#fef3c7);color:#d97706;}
.ch-title{font-size:14px;font-weight:700;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);margin-top:2px;}
.card-body{padding:20px;}

.sec-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txs);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.sec-label::after{content:'';flex:1;height:1px;background:var(--bdrs);}

/* Upload grids */
.upload-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;}
@media(max-width:620px){.upload-grid{grid-template-columns:1fr;}}
.upload-esign-row{display:grid;grid-template-columns:1fr 2fr;gap:16px;margin-bottom:18px;align-items:start;}
@media(max-width:620px){.upload-esign-row{grid-template-columns:1fr;}}

.upload-zone{border:2px dashed var(--bdr);border-radius:10px;overflow:hidden;background:#fafafa;transition:border-color .2s,background .2s;cursor:pointer;}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--pri);background:var(--pri-l);}
.upload-zone.has-image{border-style:solid;border-color:var(--bdr);background:#fff;}
.upload-zone.esign-zone:hover,.upload-zone.esign-zone.dragover{border-color:var(--teal);background:var(--teal-l);}

.uz-label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;padding:10px 14px 0;display:flex;align-items:center;gap:6px;}

.uz-preview{min-height:90px;display:flex;align-items:center;justify-content:center;padding:12px;}
.uz-preview.esign{min-height:70px;}
.uz-preview img{max-width:100%;max-height:130px;object-fit:contain;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.09);}
.uz-preview.esign img{max-height:75px;}
.uz-empty{text-align:center;padding:14px 10px;}
.uz-empty i{font-size:26px;color:#d1d5db;margin-bottom:7px;display:block;}
.uz-empty-title{font-size:12px;font-weight:600;color:var(--txs);}
.uz-empty-sub{font-size:10px;color:#b0b7bf;margin-top:3px;}

.uz-meta{padding:6px 14px 10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;border-top:1px solid var(--bdrs);background:#f8fafc;}
.uz-file-name{font-size:10px;font-family:var(--mn);color:var(--txm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;}
.uz-file-info{font-size:10px;color:var(--txs);}
.uz-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.btn-choose{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;background:var(--pri-l);color:var(--pri);border:1px solid var(--pri-b);border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-choose:hover{background:#dbeafe;}
.btn-choose.teal{background:var(--teal-l);color:var(--teal);border-color:var(--teal-b);}
.btn-choose.teal:hover{background:#ccfbf1;}
.btn-rm-img{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:var(--red-l);color:var(--red);border:1px solid var(--red-b);border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-rm-img:hover{background:#fee2e2;}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.status-badge.saved{background:var(--grn-l);color:var(--grn);border:1px solid var(--grn-b);}
.status-badge.empty{background:#f1f5f9;color:var(--txs);border:1px solid var(--bdrs);}
.status-badge.new  {background:var(--amb-l);color:var(--amb);border:1px solid var(--amb-b);}
.uz-change-note{font-size:10px;color:var(--amb);background:var(--amb-l);border:1px solid var(--amb-b);border-radius:5px;padding:3px 9px;font-weight:600;display:none;}

/* E-sign info panel */
.esign-info-panel{background:#f8fafc;border:1px solid var(--bdrs);border-radius:10px;padding:16px 18px;}
.esign-info-panel .eip-title{font-size:12px;font-weight:700;color:var(--tx);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.esign-info-panel ul{padding-left:16px;display:flex;flex-direction:column;gap:7px;}
.esign-info-panel ul li{font-size:12px;color:var(--txm);line-height:1.6;}
.esign-info-panel ul li strong{color:var(--tx);}
.esign-info-panel ul li code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 5px;font-family:var(--mn);font-size:11px;}
.esign-tip{margin-top:12px;padding:9px 12px;background:var(--teal-l);border:1px solid var(--teal-b);border-radius:7px;font-size:11px;color:var(--teal);display:flex;align-items:flex-start;gap:7px;line-height:1.6;}

/* Form fields */
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;}
.fg label span{font-weight:400;color:#d1d5db;text-transform:none;margin-left:4px;}
.fg input[type=text]{width:100%;padding:9px 12px;border:1.5px solid var(--bdr);border-radius:7px;font-size:13px;font-family:var(--fn);color:var(--tx);background:#fff;transition:border .15s,box-shadow .15s;}
.fg input:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 3px rgba(30,64,175,.08);}
.fg-hint{font-size:11px;color:var(--txs);margin-top:5px;display:flex;align-items:center;gap:5px;}

/* Buttons */
.btn-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:4px;}
.btn-save{display:inline-flex;align-items:center;gap:7px;padding:9px 22px;background:linear-gradient(135deg,var(--pri),#1558d6);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);box-shadow:0 2px 8px rgba(30,64,175,.28);transition:all .15s;}
.btn-save:hover{background:linear-gradient(135deg,#1558d6,#0d47a1);}
.btn-save:disabled{opacity:.5;cursor:not-allowed;}
.btn-rst{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:var(--fn);text-decoration:none;}
.btn-rst:hover{background:#e5e7eb;}
.btn-preview{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:var(--pri-l);color:var(--pri);border:1.5px solid var(--pri-b);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-preview:hover{background:#dbeafe;}

/* Tips */
.tips-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:560px){.tips-grid{grid-template-columns:1fr;}}
.tip-item{background:#f8fafc;border:1px solid var(--bdrs);border-radius:7px;padding:11px 13px;}
.tip-item .ti-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.tip-item .ti-val{font-size:12px;color:var(--tx);}
.tip-item .ti-val code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 5px;font-family:var(--mn);font-size:11px;}

/* Modals */
.modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:1060;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;}
.modal-ov.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:780px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.32);margin:auto;}
.modal-top{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--bdrs);}
.modal-top h3{font-size:14px;font-weight:700;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--txs);font-size:18px;padding:4px 8px;border-radius:5px;}
.modal-close:hover{background:#f3f4f6;color:var(--tx);}
.modal-body{padding:20px;}

/* Letter preview mock */
.letter-mock{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07);}
.letter-mock-header{background:#f8fafc;border-bottom:1px solid #e2e8f0;min-height:75px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.letter-mock-header img{max-width:100%;max-height:115px;object-fit:contain;}
.letter-mock-header.empty-h,.letter-mock-footer.empty-f{color:#d1d5db;font-size:12px;}
.letter-mock-body{padding:20px 24px;background:#fff;}
.lm-lines{display:flex;flex-direction:column;gap:8px;}
.lm-line{height:8px;background:#f1f5f9;border-radius:3px;}
.lm-line.short{width:60%;}.lm-line.med{width:80%;}.lm-line.long{width:100%;}
/* Signature block */
.lm-sig-block{margin-top:18px;padding-top:14px;border-top:1px dashed #e2e8f0;display:flex;align-items:flex-end;gap:16px;}
.lm-sig-img-wrap{min-width:110px;max-width:160px;}
.lm-sig-img-wrap img{max-width:100%;max-height:50px;object-fit:contain;display:block;}
.lm-sig-empty{width:120px;height:44px;background:#f8fafc;border:1px dashed #d1d5db;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#c0c7cf;gap:5px;}
.lm-sig-lines{display:flex;flex-direction:column;gap:6px;flex:1;}
.lm-sig-lines .sl{height:7px;background:#f1f5f9;border-radius:3px;}
.lm-sig-lines .sl.short{width:55%;}.lm-sig-lines .sl.med{width:70%;}
.lm-sig-label{font-size:9px;color:var(--txs);margin-top:4px;}
.letter-mock-footer{background:#f8fafc;border-top:1px solid #e2e8f0;min-height:55px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.letter-mock-footer img{max-width:100%;max-height:75px;object-fit:contain;}
</style>

<div class="pg">

    <div class="bcrumb">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
        <span class="sep">/</span>
        <span>Settings</span>
        <span class="sep">/</span>
        <span style="color:var(--tx);font-weight:600;">Company Letterhead</span>
    </div>

    <div class="topbar">
        <div>
            <div class="pg-h1">Company <em>Letterhead</em></div>
            <div class="pg-sub">Upload header, footer &amp; e-signature images used on printed documents and PDFs</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn-preview" onclick="openPreview()">
                <i class="fa-solid fa-eye"></i> Preview Layout
            </button>
            <a href="dashboard.php" class="btn-rst"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert <?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- ── CARD 1: Upload Form ── -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon blue"><i class="fa-solid fa-image"></i></div>
            <div>
                <div class="ch-title">Letterhead &amp; E-Signature Images</div>
                <div class="ch-sub">Upload header, footer and authorised e-signature for company documents</div>
            </div>
        </div>
        <div class="card-body">

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="save_images">

                <!-- Company name -->
                <div class="sec-label"><i class="fa-solid fa-building"></i> Company Details</div>
                <div class="fg">
                    <label>Company Name <span>(shown on preview)</span></label>
                    <input type="text" name="company_name" id="companyName"
                        value="<?php echo htmlspecialchars($company_name); ?>"
                        placeholder="e.g. Yelo Logistics (Pvt) Ltd">
                    <div class="fg-hint">
                        <i class="fa-solid fa-circle-info" style="font-size:10px;color:#9ca3af;"></i>
                        Used for document identification only.
                    </div>
                </div>

                <!-- ── Header & Footer ── -->
                <div class="sec-label" style="margin-top:18px;"><i class="fa-solid fa-upload"></i> Header &amp; Footer Images</div>
                <div class="upload-grid">
                <?php
                $zones = [
                    ['key'=>'header','db_key'=>'letterhead_header','file_input'=>'header_img',
                     'label'=>'Header / Letterhead Top','icon'=>'fa-rectangle-ad','icon_color'=>'#1d4ed8',
                     'has'=>$has_header,'path'=>$header_path,
                     'empty_title'=>'Click or drag to upload','empty_sub'=>'Header image — top of document'],
                    ['key'=>'footer','db_key'=>'letterhead_footer','file_input'=>'footer_img',
                     'label'=>'Footer / Letterhead Bottom','icon'=>'fa-table-rows','icon_color'=>'#7c3aed',
                     'has'=>$has_footer,'path'=>$footer_path,
                     'empty_title'=>'Click or drag to upload','empty_sub'=>'Footer image — bottom of document'],
                ];
                foreach($zones as $z):
                ?>
                <div class="upload-zone <?php echo $z['has']?'has-image':''; ?>" id="zone_<?php echo $z['key']; ?>"
                     onclick="document.getElementById('<?php echo $z['file_input']; ?>').click()"
                     ondragover="handleDragOver(event,'zone_<?php echo $z['key']; ?>')"
                     ondragleave="handleDragLeave('zone_<?php echo $z['key']; ?>')"
                     ondrop="handleDrop(event,'zone_<?php echo $z['key']; ?>','<?php echo $z['file_input']; ?>')">

                    <div class="uz-label">
                        <i class="fa-solid <?php echo $z['icon']; ?>" style="color:<?php echo $z['icon_color']; ?>;"></i>
                        <?php echo $z['label']; ?>
                    </div>
                    <div class="uz-preview" id="preview_<?php echo $z['key']; ?>">
                        <?php if($z['has']): ?>
                        <img src="<?php echo htmlspecialchars($z['path']); ?>?v=<?php echo time(); ?>" alt="">
                        <?php else: ?>
                        <div class="uz-empty">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <div class="uz-empty-title"><?php echo $z['empty_title']; ?></div>
                            <div class="uz-empty-sub"><?php echo $z['empty_sub']; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="uz-meta">
                        <div>
                            <?php if($z['has']): ?>
                            <div class="uz-file-name"><?php echo basename($z['path']); ?></div>
                            <div class="uz-file-info"><?php echo img_info($z['path']); ?></div>
                            <?php else: ?>
                            <div class="uz-file-name" id="fname_<?php echo $z['key']; ?>" style="color:#b0b7bf;">No file selected</div>
                            <?php endif; ?>
                            <span class="uz-change-note" id="new_note_<?php echo $z['key']; ?>"><i class="fa-solid fa-triangle-exclamation"></i> New file selected</span>
                        </div>
                        <div class="uz-actions">
                            <?php if($z['has']): ?>
                            <span class="status-badge saved" id="badge_<?php echo $z['key']; ?>"><i class="fa-solid fa-circle-check"></i> Saved</span>
                            <button type="button" class="btn-rm-img" onclick="event.stopPropagation();confirmRemove('<?php echo $z['db_key']; ?>')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <?php else: ?>
                            <span class="status-badge empty" id="badge_<?php echo $z['key']; ?>"><i class="fa-regular fa-circle"></i> Not set</span>
                            <?php endif; ?>
                            <button type="button" class="btn-choose" onclick="event.stopPropagation();document.getElementById('<?php echo $z['file_input']; ?>').click()">
                                <i class="fa-solid fa-folder-open"></i> Browse
                            </button>
                        </div>
                    </div>
                    <input type="file" name="<?php echo $z['file_input']; ?>" id="<?php echo $z['file_input']; ?>"
                           accept="image/jpeg,image/png,image/webp" style="display:none"
                           onchange="previewFile(this,'preview_<?php echo $z['key']; ?>','fname_<?php echo $z['key']; ?>','badge_<?php echo $z['key']; ?>','new_note_<?php echo $z['key']; ?>')">
                </div>
                <?php endforeach; ?>
                </div><!-- /upload-grid -->

                <!-- ── E-Signature ── -->
                <div class="sec-label"><i class="fa-solid fa-signature"></i> E-Signature / Authorised Signatory</div>
                <div class="upload-esign-row">

                    <!-- ESIGN ZONE -->
                    <div class="upload-zone esign-zone <?php echo $has_esign?'has-image':''; ?>" id="zone_esign"
                         onclick="document.getElementById('esign_img').click()"
                         ondragover="handleDragOver(event,'zone_esign')"
                         ondragleave="handleDragLeave('zone_esign')"
                         ondrop="handleDrop(event,'zone_esign','esign_img')">

                        <div class="uz-label">
                            <i class="fa-solid fa-pen-nib" style="color:#0f766e;"></i>
                            Signature Image
                        </div>
                        <div class="uz-preview esign" id="preview_esign">
                            <?php if($has_esign): ?>
                            <img src="<?php echo htmlspecialchars($esign_path); ?>?v=<?php echo time(); ?>" alt="E-Signature">
                            <?php else: ?>
                            <div class="uz-empty">
                                <i class="fa-solid fa-signature" style="font-size:22px;"></i>
                                <div class="uz-empty-title">Upload signature image</div>
                                <div class="uz-empty-sub">Transparent PNG recommended</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="uz-meta">
                            <div>
                                <?php if($has_esign): ?>
                                <div class="uz-file-name"><?php echo basename($esign_path); ?></div>
                                <div class="uz-file-info"><?php echo img_info($esign_path); ?></div>
                                <?php else: ?>
                                <div class="uz-file-name" id="fname_esign" style="color:#b0b7bf;">No file selected</div>
                                <?php endif; ?>
                                <span class="uz-change-note" id="new_note_esign"><i class="fa-solid fa-triangle-exclamation"></i> New file selected</span>
                            </div>
                            <div class="uz-actions">
                                <?php if($has_esign): ?>
                                <span class="status-badge saved" id="badge_esign"><i class="fa-solid fa-circle-check"></i> Saved</span>
                                <button type="button" class="btn-rm-img" onclick="event.stopPropagation();confirmRemove('letterhead_esign')">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="status-badge empty" id="badge_esign"><i class="fa-regular fa-circle"></i> Not set</span>
                                <?php endif; ?>
                                <button type="button" class="btn-choose teal" onclick="event.stopPropagation();document.getElementById('esign_img').click()">
                                    <i class="fa-solid fa-folder-open"></i> Browse
                                </button>
                            </div>
                        </div>
                        <input type="file" name="esign_img" id="esign_img"
                               accept="image/jpeg,image/png,image/webp" style="display:none"
                               onchange="previewFile(this,'preview_esign','fname_esign','badge_esign','new_note_esign')">
                    </div>

                    <!-- Info panel -->
                    <div class="esign-info-panel">
                        <div class="eip-title">
                            <i class="fa-solid fa-circle-info" style="color:#0f766e;"></i>
                            E-Signature Guidelines
                        </div>
                        <ul>
                            <li>Use a <strong>transparent PNG</strong> so the signature blends on any document background.</li>
                            <li>Ideal dimensions: <strong>400 × 150 px</strong> at 300 DPI for crisp print output.</li>
                            <li>Accepted formats: <code>PNG</code> <code>JPG</code> <code>WebP</code> — max <strong>5 MB</strong>.</li>
                            <li>The signature appears in the <strong>authorisation / sign-off</strong> area of printed invoices and letters.</li>
                            <li>Scan your physical signature on white paper, then remove the background using an image editor before uploading.</li>
                        </ul>
                        <div class="esign-tip">
                            <i class="fa-solid fa-lightbulb" style="margin-top:1px;flex-shrink:0;"></i>
                            <span>Use a <strong>PNG with transparent background</strong> to prevent a white box appearing around the signature on coloured document templates.</span>
                        </div>
                    </div>

                </div><!-- /upload-esign-row -->

                <div class="btn-row">
                    <button type="submit" class="btn-save" id="saveBtn">
                        <i class="fa-solid fa-floppy-disk"></i> Save All Settings
                    </button>
                    <button type="button" class="btn-preview" onclick="openPreview()">
                        <i class="fa-solid fa-eye"></i> Preview Layout
                    </button>
                </div>

            </form>

            <form method="POST" id="delImgForm" style="display:none;">
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="img_key" id="delImgKey" value="">
            </form>

        </div>
    </div>

    <!-- ── CARD 2: Tips ── -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon amber"><i class="fa-solid fa-lightbulb"></i></div>
            <div>
                <div class="ch-title">Image Guidelines</div>
                <div class="ch-sub">Recommended specifications for best print results</div>
            </div>
        </div>
        <div class="card-body">
            <div class="tips-grid">
                <div class="tip-item">
                    <div class="ti-lbl"><i class="fa-solid fa-ruler-combined"></i> Header / Footer Size</div>
                    <div class="ti-val">Width: <strong>2480 px</strong> (A4 @ 300 DPI)<br>Header: 200–400 px · Footer: 100–250 px</div>
                </div>
                <div class="tip-item">
                    <div class="ti-lbl"><i class="fa-solid fa-signature"></i> Signature Size</div>
                    <div class="ti-val">Recommended: <strong>400 × 150 px</strong> at 300 DPI<br>Transparent PNG strongly preferred</div>
                </div>
                <div class="tip-item">
                    <div class="ti-lbl"><i class="fa-solid fa-file-image"></i> Accepted Formats</div>
                    <div class="ti-val"><code>JPG</code> <code>PNG</code> <code>WebP</code> — Max <strong>5 MB</strong> per image</div>
                </div>
                <div class="tip-item">
                    <div class="ti-lbl"><i class="fa-solid fa-folder-open"></i> Storage Location</div>
                    <div class="ti-val">All files saved to <code>uploads/letterhead/</code> on the server.</div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /pg -->

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-ov" id="delConfirm" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-top">
      <h3><i class="fa-solid fa-trash-can" style="color:#dc2626;margin-right:8px;"></i> Remove Image</h3>
      <button class="modal-close" onclick="document.getElementById('delConfirm').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:#6b7280;line-height:1.6;" id="delMsg">This image will be permanently deleted from the server.</p>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;">
      <button onclick="document.getElementById('delConfirm').classList.remove('open')" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
      <button onclick="document.getElementById('delImgForm').submit()" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;"><i class="fa-solid fa-trash"></i> Remove</button>
    </div>
  </div>
</div>

<!-- ── PREVIEW MODAL ── -->
<div class="modal-ov" id="previewModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-top">
      <h3><i class="fa-solid fa-file-lines" style="color:#1d4ed8;margin-right:8px;"></i> Document Layout Preview</h3>
      <button class="modal-close" onclick="document.getElementById('previewModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:12px;color:var(--txs);margin-bottom:14px;">
          <i class="fa-solid fa-circle-info" style="color:#93c5fd;"></i>
          Approximate preview of how images appear on a printed document.
      </p>
      <div class="letter-mock">

          <!-- Header -->
          <div class="letter-mock-header <?php echo !$has_header?'empty-h':''; ?>" id="pm_header">
              <?php if($has_header): ?>
              <img src="<?php echo htmlspecialchars($header_path); ?>?v=<?php echo time(); ?>" alt="Header">
              <?php else: ?>
              <span><i class="fa-solid fa-image"></i> No header image uploaded</span>
              <?php endif; ?>
          </div>

          <!-- Body -->
          <div class="letter-mock-body">
              <?php if($company_name): ?>
              <div style="font-size:11px;font-weight:700;color:var(--txs);margin-bottom:14px;text-transform:uppercase;letter-spacing:.06em;"><?php echo htmlspecialchars($company_name); ?></div>
              <?php endif; ?>
              <div class="lm-lines">
                  <div class="lm-line short"></div>
                  <div class="lm-line long"></div>
                  <div class="lm-line med"></div>
                  <div class="lm-line long"></div>
                  <div class="lm-line short"></div>
                  <div style="height:10px;"></div>
                  <div class="lm-line long"></div>
                  <div class="lm-line long"></div>
                  <div class="lm-line med"></div>
              </div>

              <!-- Signature row -->
              <div class="lm-sig-block">
                  <div class="lm-sig-img-wrap" id="pm_esign">
                      <?php if($has_esign): ?>
                      <img src="<?php echo htmlspecialchars($esign_path); ?>?v=<?php echo time(); ?>" alt="Signature">
                      <?php else: ?>
                      <div class="lm-sig-empty"><i class="fa-solid fa-signature"></i> No signature</div>
                      <?php endif; ?>
                  </div>
                  <div class="lm-sig-lines">
                      <div class="sl short"></div>
                      <div class="sl med"></div>
                      <div class="lm-sig-label">Authorised Signatory</div>
                  </div>
              </div>
          </div>

          <!-- Footer -->
          <div class="letter-mock-footer <?php echo !$has_footer?'empty-f':''; ?>" id="pm_footer">
              <?php if($has_footer): ?>
              <img src="<?php echo htmlspecialchars($footer_path); ?>?v=<?php echo time(); ?>" alt="Footer">
              <?php else: ?>
              <span><i class="fa-solid fa-image"></i> No footer image uploaded</span>
              <?php endif; ?>
          </div>

      </div>
      <div style="margin-top:12px;font-size:11px;color:var(--txs);text-align:center;">
          <i class="fa-solid fa-circle-info" style="color:#93c5fd;"></i>
          Actual proportions depend on your PDF/print template settings.
      </div>
    </div>
  </div>
</div>

<script>
function previewFile(input, previewId, fnameId, badgeId, noteId) {
    const file = input.files[0];
    if (!file) return;

    const fnEl = document.getElementById(fnameId);
    if (fnEl) { fnEl.textContent = file.name; fnEl.style.color = ''; }

    const noteEl = document.getElementById(noteId);
    if (noteEl) noteEl.style.display = 'inline-flex';

    const badge = document.getElementById(badgeId);
    if (badge) {
        badge.className = 'status-badge new';
        badge.innerHTML = '<i class="fa-solid fa-arrow-up"></i> Pending';
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const isEsign = (previewId === 'preview_esign');
        const maxH    = isEsign ? '75px' : '130px';
        document.getElementById(previewId).innerHTML =
            '<img src="'+e.target.result+'" style="max-width:100%;max-height:'+maxH+';object-fit:contain;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.09);">';

        /* Update preview modal live */
        const pmMap = { preview_header:'pm_header', preview_footer:'pm_footer', preview_esign:'pm_esign' };
        const pmEl  = document.getElementById(pmMap[previewId] || '');
        if (pmEl) {
            const mh = previewId==='preview_header'?'115px':(previewId==='preview_footer'?'75px':'50px');
            pmEl.innerHTML = '<img src="'+e.target.result+'" style="max-width:100%;max-height:'+mh+';object-fit:contain;">';
            pmEl.classList.remove('lm-sig-empty');
        }
    };
    reader.readAsDataURL(file);
}

function handleDragOver(e, zoneId) {
    e.preventDefault();
    document.getElementById(zoneId).classList.add('dragover');
}
function handleDragLeave(zoneId) {
    document.getElementById(zoneId).classList.remove('dragover');
}
function handleDrop(e, zoneId, inputId) {
    e.preventDefault();
    document.getElementById(zoneId).classList.remove('dragover');
    const input = document.getElementById(inputId);
    if (e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
    }
}

function confirmRemove(key) {
    document.getElementById('delImgKey').value = key;
    const labels = {
        letterhead_header : 'header',
        letterhead_footer : 'footer',
        letterhead_esign  : 'e-signature'
    };
    document.getElementById('delMsg').textContent =
        'The ' + (labels[key]||key) + ' image will be permanently deleted from the server. This action cannot be undone.';
    document.getElementById('delConfirm').classList.add('open');
}

function openPreview() {
    document.getElementById('previewModal').classList.add('open');
}

document.getElementById('uploadForm')?.addEventListener('submit', function() {
    const b = document.getElementById('saveBtn');
    b.disabled = true;
    b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});
</script>

<?php include 'footer.php'; ?>