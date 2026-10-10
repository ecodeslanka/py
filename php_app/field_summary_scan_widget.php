<?php
/**
 * Inline "Scanned Copies" section for a field summary form / view page.
 *
 * Usage (inside edit_field_summary.php or view_field_summary.php):
 *     <?php $fs_id = $id; include 'field_summary_scan_widget.php'; ?>
 *
 * Safe to place INSIDE an existing <form>: it uses no <form> tag and all
 * buttons are type="button", so it never submits the parent form.
 * Uploads go straight to field_summary_scan_api.php via AJAX.
 */
if (!isset($fs_id) || intval($fs_id) <= 0) return;
$fs_id = intval($fs_id);

// Current status from field_summary.scan_count
$fsw_count = 0;
$fsw_q = mysqli_query($conn, "SELECT scan_count, field_summary_code FROM field_summary WHERE id = $fs_id");
$fsw_code = '';
if ($fsw_q && ($fsw_r = mysqli_fetch_assoc($fsw_q))) {
    $fsw_count = intval($fsw_r['scan_count']);
    $fsw_code  = $fsw_r['field_summary_code'];
}
?>
<style>
.fsw { background:#fff; border:1px solid #e5e5e5; border-radius:8px; padding:20px; margin-bottom:20px; }
.fsw-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.fsw-head h3 { margin:0; font-size:16px; font-weight:600; color:#1f2937; display:flex; align-items:center; gap:8px; }
.fsw-status { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:14px; font-size:12px; font-weight:700; border:1px solid; }
.fsw-status.ok   { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; }
.fsw-status.none { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
.fsw-drop { display:flex; flex-direction:column; align-items:center; gap:6px; border:2px dashed #d1d5db; border-radius:8px; padding:22px 16px; text-align:center; cursor:pointer; background:#fafafa; transition:border-color .2s, background .2s; }
.fsw-drop:hover, .fsw-drop.drag { border-color:#0e7490; background:#ecfeff; }
.fsw-drop i { font-size:28px; color:#0e7490; }
.fsw-drop strong { font-size:14px; color:#1f2937; }
.fsw-drop span { font-size:12px; color:#6b7280; }
.fsw-drop input { display:none; }
.fsw-pending { margin-top:12px; display:none; }
.fsw-pending.show { display:block; }
.fsw-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
.fsw-chip { display:flex; align-items:center; gap:8px; max-width:240px; border:1px solid #e5e5e5; border-radius:6px; padding:5px 6px 5px 5px; }
.fsw-chip img, .fsw-chip .pdf { width:34px; height:34px; border-radius:4px; object-fit:cover; flex-shrink:0; }
.fsw-chip .pdf { display:flex; align-items:center; justify-content:center; background:#fef2f2; color:#dc2626; }
.fsw-chip .nm { font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.fsw-chip button { border:none; background:none; color:#9ca3af; cursor:pointer; padding:4px; }
.fsw-chip button:hover { color:#dc2626; }
.fsw-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.fsw-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid #e5e5e5; font-family:inherit; }
.fsw-btn.primary { background:#000; color:#fff; border-color:#000; }
.fsw-btn.primary:disabled { background:#9ca3af; border-color:#9ca3af; cursor:not-allowed; }
.fsw-btn.light { background:#f3f4f6; color:#374151; }
.fsw-progress { flex:1; min-width:140px; height:6px; background:#e5e5e5; border-radius:10px; overflow:hidden; display:none; }
.fsw-progress.show { display:block; }
.fsw-progress div { height:100%; width:0; background:#0e7490; }
.fsw-msg { margin-top:12px; font-size:13px; border-radius:6px; padding:10px 12px; display:none; }
.fsw-msg.show { display:block; }
.fsw-msg.ok  { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.fsw-msg.err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.fsw-msg ul { margin:6px 0 0 18px; padding:0; }
.fsw-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; margin-top:16px; }
.fsw-item { border:1px solid #e5e5e5; border-radius:8px; overflow:hidden; }
.fsw-thumb { display:block; width:100%; aspect-ratio:4/3; border:none; padding:0; background:#f3f4f6; cursor:zoom-in; }
.fsw-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.fsw-thumb .pdf { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; color:#dc2626; background:#fef2f2; font-size:11px; font-weight:700; }
.fsw-thumb .pdf i { font-size:34px; }
.fsw-meta { padding:7px 9px; display:flex; align-items:center; gap:6px; }
.fsw-meta .nm { flex:1; min-width:0; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#1f2937; }
.fsw-meta button { border:1px solid #fecaca; background:#fef2f2; color:#991b1b; border-radius:5px; width:26px; height:26px; cursor:pointer; flex-shrink:0; }
.fsw-meta button:hover { background:#ef4444; color:#fff; }
.fsw-empty { grid-column:1/-1; text-align:center; padding:20px; color:#9ca3af; font-size:13px; }

.fsw-viewer { position:fixed; inset:0; z-index:1100; background:rgba(0,0,0,.9); display:none; flex-direction:column; }
.fsw-viewer.open { display:flex; }
.fsw-vbar { display:flex; align-items:center; gap:10px; padding:12px 16px; color:#fff; }
.fsw-vbar .t { flex:1; min-width:0; font-size:14px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.fsw-vbar .t small { display:block; color:#9ca3af; font-weight:400; font-size:12px; }
.fsw-vbtn { display:inline-flex; align-items:center; justify-content:center; height:36px; min-width:36px; padding:0 10px; border-radius:6px; background:rgba(255,255,255,.1); color:#fff; border:1px solid rgba(255,255,255,.2); cursor:pointer; text-decoration:none; font-size:13px; }
.fsw-vbtn:hover { background:rgba(255,255,255,.22); }
.fsw-vmain { flex:1; position:relative; display:flex; min-height:0; }
.fsw-stage { flex:1; display:flex; overflow:auto; padding:0 60px 20px; }
.fsw-stage img { margin:auto; max-width:100%; max-height:100%; object-fit:contain; cursor:zoom-in; background:#fff; border-radius:4px; }
.fsw-stage img.zoomed { max-width:none; max-height:none; cursor:zoom-out; }
.fsw-stage iframe { width:100%; height:100%; border:none; background:#fff; border-radius:4px; }
.fsw-nav { position:absolute; top:50%; transform:translateY(-50%); width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2); cursor:pointer; }
.fsw-nav.prev { left:8px; } .fsw-nav.next { right:8px; }
.fsw-nav[hidden] { display:none; }
@media (max-width:640px){ .fsw-stage { padding:0 8px 12px; } }
</style>

<div class="fsw" id="fsw" data-fid="<?php echo $fs_id; ?>" data-code="<?php echo htmlspecialchars($fsw_code, ENT_QUOTES); ?>">
    <div class="fsw-head">
        <h3><i class="fa-solid fa-file-image"></i> Scanned Copies</h3>
        <span class="fsw-status <?php echo $fsw_count > 0 ? 'ok' : 'none'; ?>" id="fswStatus">
            <?php if ($fsw_count > 0): ?>
                <i class="fa-solid fa-circle-check"></i> <?php echo $fsw_count; ?> uploaded
            <?php else: ?>
                <i class="fa-solid fa-circle-xmark"></i> Not uploaded
            <?php endif; ?>
        </span>
    </div>

    <label class="fsw-drop" id="fswDrop">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <strong>Click to choose files or drag them here</strong>
        <span>Images (JPG, PNG, WEBP, GIF) or PDF · multiple files · max 10 MB each</span>
        <input type="file" id="fswInput" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
    </label>

    <div class="fsw-pending" id="fswPending">
        <div class="fsw-chips" id="fswChips"></div>
        <div class="fsw-actions">
            <button type="button" class="fsw-btn primary" id="fswUpload"><i class="fa-solid fa-upload"></i> <span>Upload</span></button>
            <button type="button" class="fsw-btn light" id="fswClear">Clear</button>
            <div class="fsw-progress" id="fswProgress"><div></div></div>
        </div>
    </div>

    <div class="fsw-msg" id="fswMsg"></div>
    <div class="fsw-grid" id="fswGrid"><div class="fsw-empty">Loading scans…</div></div>
</div>

<div class="fsw-viewer" id="fswViewer">
    <div class="fsw-vbar">
        <div class="t"><span id="fswVName"></span><small id="fswVCount"></small></div>
        <a class="fsw-vbtn" id="fswVOpen" href="#" target="_blank" rel="noopener" title="Open in new tab"><i class="fa-solid fa-up-right-from-square"></i></a>
        <a class="fsw-vbtn" id="fswVDown" href="#" download title="Download"><i class="fa-solid fa-download"></i></a>
        <button type="button" class="fsw-vbtn" id="fswVClose" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="fsw-vmain">
        <button type="button" class="fsw-nav prev" id="fswPrev"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="fsw-stage" id="fswStage"></div>
        <button type="button" class="fsw-nav next" id="fswNext"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
</div>

<script>
(function () {
    const API = 'field_summary_scan_api.php';
    const MAX = 10 * 1024 * 1024;
    const OK_TYPES = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    const $ = id => document.getElementById(id);
    const root = $('fsw');
    const st = { fid: parseInt(root.dataset.fid, 10), code: root.dataset.code, scans: [], pending: [], v: 0, busy: false };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const size = b => b < 1024 ? b + ' B' : b < 1048576 ? (b/1024).toFixed(1) + ' KB' : (b/1048576).toFixed(1) + ' MB';

    function msg(type, text, list) {
        const m = $('fswMsg');
        if (!type) { m.className = 'fsw-msg'; m.innerHTML = ''; return; }
        m.className = 'fsw-msg show ' + type;
        m.innerHTML = esc(text) + (list && list.length ? '<ul>' + list.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul>' : '');
    }

    function setStatus(n) {
        const s = $('fswStatus');
        s.className = 'fsw-status ' + (n > 0 ? 'ok' : 'none');
        s.innerHTML = n > 0 ? '<i class="fa-solid fa-circle-check"></i> ' + n + ' uploaded'
                            : '<i class="fa-solid fa-circle-xmark"></i> Not uploaded';
    }

    function render() {
        setStatus(st.scans.length);
        $('fswGrid').innerHTML = st.scans.length ? st.scans.map((s, i) =>
            '<div class="fsw-item">' +
              '<button type="button" class="fsw-thumb" data-view="' + i + '">' +
                (s.file_type === 'pdf' ? '<div class="pdf"><i class="fa-solid fa-file-pdf"></i>PDF</div>'
                                       : '<img src="' + esc(s.url) + '" loading="lazy" alt="">') +
              '</button>' +
              '<div class="fsw-meta"><span class="nm" title="' + esc(s.original_name) + '">' + esc(s.original_name) + '</span>' +
              '<button type="button" data-del="' + s.id + '" title="Delete"><i class="fa-solid fa-trash"></i></button></div>' +
            '</div>').join('')
          : '<div class="fsw-empty">No scans uploaded yet.</div>';
    }

    async function load() {
        try {
            const r = await fetch(API + '?action=list&field_summary_id=' + st.fid, { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) throw new Error(d.message);
            st.scans = d.scans; render();
        } catch (e) { $('fswGrid').innerHTML = '<div class="fsw-empty">' + esc(e.message || 'Could not load scans') + '</div>'; }
    }

    function addFiles(list) {
        const bad = [];
        Array.from(list).forEach(f => {
            if (!OK_TYPES.includes(f.type)) return bad.push(f.name + ': only JPG, PNG, WEBP, GIF or PDF allowed');
            if (f.size > MAX) return bad.push(f.name + ': larger than 10 MB');
            if (st.pending.some(p => p.file.name === f.name && p.file.size === f.size)) return;
            st.pending.push({ file: f, prev: f.type.startsWith('image/') ? URL.createObjectURL(f) : null });
        });
        bad.length ? msg('err', 'Some files were skipped:', bad) : msg();
        renderPending();
    }
    function clearPending() { st.pending.forEach(p => p.prev && URL.revokeObjectURL(p.prev)); st.pending = []; renderPending(); }
    function renderPending() {
        $('fswPending').classList.toggle('show', st.pending.length > 0);
        $('fswChips').innerHTML = st.pending.map((p, i) =>
            '<div class="fsw-chip">' + (p.prev ? '<img src="' + p.prev + '" alt="">' : '<div class="pdf"><i class="fa-solid fa-file-pdf"></i></div>') +
            '<span class="nm" title="' + esc(p.file.name) + '">' + esc(p.file.name) + ' · ' + size(p.file.size) + '</span>' +
            '<button type="button" data-rm="' + i + '"><i class="fa-solid fa-xmark"></i></button></div>').join('');
        $('fswUpload').querySelector('span').textContent = 'Upload ' + st.pending.length + ' file' + (st.pending.length === 1 ? '' : 's');
    }
    function busy(b) {
        st.busy = b;
        ['fswUpload','fswClear','fswInput'].forEach(id => $(id).disabled = b);
        $('fswProgress').classList.toggle('show', b);
        if (!b) $('fswProgress').firstElementChild.style.width = '0';
    }

    function upload() {
        if (!st.pending.length || st.busy) return;
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('field_summary_id', st.fid);
        st.pending.forEach(p => fd.append('scans[]', p.file));
        busy(true); msg();
        const x = new XMLHttpRequest();
        x.open('POST', API);
        x.upload.onprogress = e => { if (e.lengthComputable) $('fswProgress').firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%'; };
        x.onload = () => {
            busy(false);
            let d; try { d = JSON.parse(x.responseText); } catch (e) { return msg('err', 'Server returned an invalid response (HTTP ' + x.status + ').'); }
            if (d.scans) { st.scans = d.scans; render(); }
            if (d.uploaded > 0) clearPending();
            msg(d.success && !(d.errors && d.errors.length) ? 'ok' : 'err', d.message || 'Upload failed', d.errors);
        };
        x.onerror = () => { busy(false); msg('err', 'Network error — check your connection and upload again.'); };
        x.send(fd);
    }

    async function del(id) {
        if (!confirm('Delete this scan?')) return;
        const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
        try {
            const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) throw new Error(d.message);
            st.scans = d.scans; render(); msg('ok', 'Scan deleted');
        } catch (e) { msg('err', e.message || 'Delete failed'); }
    }

    // Viewer
    function vOpen(i) { st.v = i; $('fswViewer').classList.add('open'); document.body.style.overflow = 'hidden'; vRender(); }
    function vClose() { $('fswViewer').classList.remove('open'); $('fswStage').innerHTML = ''; document.body.style.overflow = ''; }
    function vNav(d) { const n = st.scans.length; if (n < 2) return; st.v = (st.v + d + n) % n; vRender(); }
    function vRender() {
        const s = st.scans[st.v]; if (!s) return vClose();
        $('fswVName').textContent = s.original_name;
        $('fswVCount').textContent = (st.v + 1) + ' of ' + st.scans.length + ' · ' + st.code;
        $('fswVOpen').href = s.url; $('fswVDown').href = s.url; $('fswVDown').setAttribute('download', s.original_name);
        $('fswPrev').hidden = $('fswNext').hidden = st.scans.length < 2;
        $('fswStage').innerHTML = s.file_type === 'pdf'
            ? '<iframe src="' + esc(s.url) + '"></iframe>'
            : '<img src="' + esc(s.url) + '" alt="" title="Click to zoom">';
    }

    // Events
    $('fswInput').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
    const drop = $('fswDrop');
    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => { if (!st.busy) addFiles(e.dataTransfer.files); });
    $('fswChips').addEventListener('click', e => {
        const b = e.target.closest('[data-rm]'); if (!b || st.busy) return;
        const p = st.pending.splice(parseInt(b.dataset.rm, 10), 1)[0];
        if (p && p.prev) URL.revokeObjectURL(p.prev);
        renderPending();
    });
    $('fswUpload').addEventListener('click', upload);
    $('fswClear').addEventListener('click', () => { clearPending(); msg(); });
    $('fswGrid').addEventListener('click', e => {
        const v = e.target.closest('[data-view]'); if (v) return vOpen(parseInt(v.dataset.view, 10));
        const d = e.target.closest('[data-del]');  if (d) del(parseInt(d.dataset.del, 10));
    });
    $('fswVClose').addEventListener('click', vClose);
    $('fswPrev').addEventListener('click', () => vNav(-1));
    $('fswNext').addEventListener('click', () => vNav(1));
    $('fswStage').addEventListener('click', e => {
        if (e.target.tagName === 'IMG') e.target.classList.toggle('zoomed');
        else if (e.target === $('fswStage')) vClose();
    });
    document.addEventListener('keydown', e => {
        if (!$('fswViewer').classList.contains('open')) return;
        if (e.key === 'Escape') vClose();
        if (e.key === 'ArrowLeft') vNav(-1);
        if (e.key === 'ArrowRight') vNav(1);
    });
    // Stop Enter inside the widget from submitting a parent form
    root.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault(); });

    load();
})();
</script>
