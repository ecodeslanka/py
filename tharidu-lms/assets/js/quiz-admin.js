/* Admin quiz editor: picture drop-zones (drag · paste · click) + AI paper scan with in-browser PDF rendering (for auto-cut figures) */
(function () {
  var Q = window.QUIZ || {}, $ = function (i) { return document.getElementById(i); };

  /* ================= picture drop-zones ================= */
  var modal = $('mQ'), form = $('qform'), zones = [].slice.call(document.querySelectorAll('#mQ .dz')), hovered = null;
  zones.forEach(function (dz) {
    var idx = dz.dataset.idx, isOpt = idx !== undefined, name = isOpt ? 'opt_img[' + idx + ']' : 'q_image';
    var inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.name = name; inp.hidden = true; dz.appendChild(inp);
    var rm = document.createElement('input'); rm.type = 'hidden'; rm.name = isOpt ? 'opt_img_rm[' + idx + ']' : 'q_image_rm'; rm.value = '1'; rm.disabled = true; dz.appendChild(rm);
    var x = document.createElement('button'); x.type = 'button'; x.textContent = '✕'; x.title = 'Remove picture'; dz.querySelector('.dzp').appendChild(x);
    var im = document.createElement('img'); im.alt = ''; dz.querySelector('.dzp').insertBefore(im, x);
    dz._i = inp; dz._rm = rm; dz._img = im; dz._existing = null;
    function show(src) { im.src = src; dz.classList.add('has'); }
    dz._setFile = function (file) {
      if (!file || !/^image\//.test(file.type)) return false;
      try { var dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch (e) { return false; }
      rm.disabled = true; show(URL.createObjectURL(file)); return true;
    };
    dz._setExisting = function (n) { dz._existing = n; if (n) { rm.disabled = true; show(Q.img + n); } };
    dz._clear = function (fromUser) {
      inp.value = ''; im.removeAttribute('src'); dz.classList.remove('has');
      if (fromUser && dz._existing) rm.disabled = false;       // tell the server to delete the stored picture
      if (!fromUser) { dz._existing = null; rm.disabled = true; }
    };
    dz.addEventListener('click', function (e) { if (e.target === x) { dz._clear(true); e.stopPropagation(); return; } inp.click(); });
    dz.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
    inp.addEventListener('change', function () { if (inp.files[0]) { rm.disabled = true; show(URL.createObjectURL(inp.files[0])); } });
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function () { dz.classList.remove('over'); }); });
    dz.addEventListener('drop', function (e) { e.preventDefault(); var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f && !dz._setFile(f)) alert('Please drop a picture file (JPG, PNG, WEBP).'); });
    dz.addEventListener('mouseenter', function () { hovered = dz; }); dz.addEventListener('focus', function () { hovered = dz; });
  });
  // dropping anywhere on an option row goes to that row's picture box
  [].forEach.call(document.querySelectorAll('#mQ .optrow'), function (row) {
    row.addEventListener('dragover', function (e) { e.preventDefault(); });
    row.addEventListener('drop', function (e) { if (e.target.closest('.dz')) return; e.preventDefault(); var f = e.dataTransfer.files[0]; var dz = row.querySelector('.dz'); if (f && dz) dz._setFile(f); });
  });
  // Ctrl+V : paste a copied picture / screenshot into the option being typed in (or the question box)
  document.addEventListener('paste', function (e) {
    if (!modal || !modal.open) return;
    var files = (e.clipboardData && e.clipboardData.files) ? [].slice.call(e.clipboardData.files).filter(function (f) { return /^image\//.test(f.type); }) : [];
    if (!files.length) return;
    var row = document.activeElement && document.activeElement.closest ? document.activeElement.closest('.optrow') : null;
    var dz = row ? row.querySelector('.dz') : (document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('dz') ? document.activeElement : (hovered || zones[0]));
    if (dz && dz._setFile(files[0])) { e.preventDefault(); dz.classList.add('paste'); setTimeout(function () { dz.classList.remove('paste'); }, 700); }
  });
  if (modal) modal.addEventListener('fill', function (e) {
    var k = e.detail; form.reset(); zones.forEach(function (z) { z._clear(false); });
    form.elements.id.value = k ? k.id : 0; $('qmTitle').textContent = k ? 'Edit question' : 'Add question';
    if (k) {
      form.elements.question.value = k.question || ''; form.elements.explanation.value = k.explanation || '';
      for (var i = 0; i < 6; i++) form.elements['opt[' + i + ']'].value = k.options[i] || '';
      form.querySelector('input[name=correct][value="' + k.correct + '"]').checked = true;
      zones.forEach(function (z) { if (z.dataset.idx === undefined) { if (k.q_image) z._setExisting(k.q_image); } else { var n = (k.opt_images || [])[parseInt(z.dataset.idx, 10)]; if (n) z._setExisting(n); } });
    }
  });

  /* ================= tap an option = set the correct answer ================= */
  document.querySelectorAll('.qcard .qo').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.closest('.qcard'), fd = new FormData(); fd.append('act', 'set_correct'); fd.append('qid', Q.id); fd.append('id', c.dataset.id); fd.append('correct', b.dataset.i); fd.append('_csrf', Q.csrf);
      fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) throw 0; c.querySelectorAll('.qo').forEach(function (o) { o.classList.remove('ok'); var em = o.querySelector('em'); if (em) em.remove(); });
        b.classList.add('ok'); b.insertAdjacentHTML('beforeend', '<em>✓ correct</em>'); c.classList.remove('review');
        var cf = c.querySelector('form input[value=confirm_q]'); if (cf) cf.closest('form').remove();
      }).catch(function () { alert('Could not save – reload and try again.'); });
    });
  });

  /* ================= AI scan ================= */
  var aiForm = $('aiForm'); if (!aiForm) return;
  var busy = $('aibusy'), step = $('aiStep'), bar = $('aiBar'), note = $('aiNote');
  function ui(t, p, n) { busy.style.display = 'grid'; step.textContent = t; if (p != null) bar.style.width = Math.round(p * 100) + '%'; if (n) note.textContent = n; }
  function hex(n) { var a = new Uint8Array(n); (window.crypto || window.msCrypto).getRandomValues(a); return [].map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function toJpeg(src, maxW) {                                              // image file/blob -> JPEG blob
    return createImageBitmap(src).then(function (bm) { var s = Math.min(1, maxW / bm.width), c = document.createElement('canvas'); c.width = Math.round(bm.width * s); c.height = Math.round(bm.height * s);
      var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(bm, 0, 0, c.width, c.height); return new Promise(function (r) { c.toBlob(r, 'image/jpeg', 0.85); }); });
  }
  async function pdfPages(buf, progress) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = Q.pdfWorker;
    var pdf = await pdfjsLib.getDocument({ data: buf }).promise, n = Math.min(pdf.numPages, 40), out = [];
    for (var i = 1; i <= n; i++) {
      var pg = await pdf.getPage(i), v1 = pg.getViewport({ scale: 1 }), sc = Math.max(1, Math.min(2.6, 1400 / v1.width)), v = pg.getViewport({ scale: sc });
      var c = document.createElement('canvas'); c.width = Math.round(v.width); c.height = Math.round(v.height); var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
      await pg.render({ canvasContext: x, viewport: v }).promise; out.push(await new Promise(function (r) { c.toBlob(r, 'image/jpeg', 0.8); })); progress(i, n);
    }
    return out;
  }
  function upload(tok, n, blob) {
    var fd = new FormData(); fd.append('act', 'scan_page'); fd.append('token', tok); fd.append('n', n); fd.append('_csrf', Q.csrf); fd.append('page', blob, 'p' + n + '.jpg');
    return fetch(location.pathname + '?id=' + Q.id, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'page upload failed'); });
  }
  async function start() {
    var cut = $('aiCut').checked && window.pdfjsLib, files = [].slice.call($('aiPaper').files), from = $('aiFrom') ? $('aiFrom').value : '0', blobs = [], warn = '';
    ui('Preparing…', 0.02, 'Please keep this page open.');
    try {
      if (cut) {
        var isPdf = function (f) { return /\.pdf$/i.test(f.name) || f.type === 'application/pdf'; }, isImg = function (f) { return /^image\//.test(f.type); };
        if (files.length === 1 && isPdf(files[0])) blobs = await pdfPages(await files[0].arrayBuffer(), function (i, n) { ui('Reading page ' + i + ' of ' + n + '…', 0.05 + 0.4 * i / n); });
        else if (files.length && files.every(isImg)) { for (var i = 0; i < files.length; i++) { ui('Preparing photo ' + (i + 1) + ' of ' + files.length + '…', 0.05 + 0.4 * (i + 1) / files.length); blobs.push(await toJpeg(files[i], 1800)); } }
        else if (!files.length && from !== '0' && Q.papers[from]) {
          var ext = Q.papers[from].ext; ui('Opening the stored paper…', 0.05);
          if (ext === 'pdf' || /^(jpg|jpeg|png)$/.test(ext)) { var blob = await (await fetch(Q.paperUrl + from, { credentials: 'same-origin' })).blob();
            blobs = ext === 'pdf' ? await pdfPages(await blob.arrayBuffer(), function (i, n) { ui('Reading page ' + i + ' of ' + n + '…', 0.05 + 0.4 * i / n); }) : [await toJpeg(blob, 1800)]; }
        }
      } else if ($('aiCut').checked) warn = 'PDF viewer could not load – scanning without auto-cut.';
    } catch (err) { console.error(err); blobs = []; warn = 'Could not read the pages in the browser – scanning without auto-cut.'; }
    var tok = '';
    try {
      if (blobs.length) { tok = hex(16); for (var n = 0; n < blobs.length; n++) { ui('Uploading page ' + (n + 1) + ' of ' + blobs.length + '…', 0.45 + 0.25 * (n + 1) / blobs.length); await upload(tok, n + 1, blobs[n]); } }
    } catch (err) { busy.style.display = 'none'; alert('Page upload failed: ' + err.message); return; }
    ui(blobs.length ? 'Gemini is reading the paper and cutting out the pictures…' : 'Gemini is reading the paper…', 0.75, (warn ? warn + ' ' : '') + 'This can take up to a minute. Please keep this page open.');
    var fd = new FormData(aiForm); fd.set('ajax', '1'); if (tok) { fd.set('scan_token', tok); fd.delete('paper'); }
    fetch(location.pathname + '?id=' + Q.id, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
      var j; try { j = JSON.parse(t); } catch (e) { throw new Error('The server stopped before finishing (it may have run out of time). Try a smaller file or fewer pages.'); }
      if (!j.ok) throw new Error(j.error || 'Scan failed'); bar.style.width = '100%'; location.href = location.pathname + '?id=' + Q.id;
    }).catch(function (err) { busy.style.display = 'none'; alert(err.message); });
  }
  aiForm.addEventListener('submit', function (e) { e.preventDefault(); start(); });
  if (Q.autoScan) { var sel = $('aiFrom'); if (sel) { sel.value = String(Q.autoScan); setTimeout(start, 400); } }
})();
