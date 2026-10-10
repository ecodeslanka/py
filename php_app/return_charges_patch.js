/*
 * PATCH INSTRUCTIONS for return_charges.php
 * ==========================================
 * 1. Add the CSS block below to your <style> section
 * 2. Add the HTML block inside .pay-section-wrap, BEFORE the <!-- Info banner --> comment
 * 3. Add the JS block to your <script> section
 * 4. The feature uses the Anthropic API (claude-sonnet-4-20250514) to read uploaded images
 *    and extract cheque details + CRN number, then auto-fills the form.
 */

/* ══════════════════════════════════════════════════════
   1. ADD TO <style> SECTION
══════════════════════════════════════════════════════ */
const CSS_TO_ADD = `
/* ── Return Slip AI Upload ── */
.rtn-upload-zone{border:2px dashed #bfdbfe;border-radius:10px;background:#eff6ff;padding:16px;margin-bottom:18px;transition:all .2s}
.rtn-upload-zone.dragover{border-color:#3b82f6;background:#dbeafe}
.rtn-upload-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.rtn-upload-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#1e40af;display:flex;align-items:center;gap:6px}
.rtn-upload-title i{font-size:14px}
.btn-rtn-upload{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px solid #3b82f6;border-radius:6px;background:#fff;color:#1d4ed8;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-rtn-upload:hover{background:#eff6ff}
.btn-rtn-analyze{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border:none;border-radius:6px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-rtn-analyze:hover{filter:brightness(1.1)}
.btn-rtn-analyze:disabled{opacity:.55;cursor:not-allowed}
.rtn-preview-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.rtn-img-thumb{position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1.5px solid #bfdbfe;background:#e0f2fe;flex-shrink:0}
.rtn-img-thumb img{width:100%;height:100%;object-fit:cover}
.rtn-img-thumb .rm-img{position:absolute;top:2px;right:2px;background:rgba(239,68,68,.9);color:#fff;border:none;border-radius:3px;width:16px;height:16px;font-size:9px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.rtn-analyze-status{font-size:12px;color:#1e40af;display:flex;align-items:center;gap:6px;padding:6px 10px;background:#dbeafe;border-radius:6px;margin-top:8px;display:none}
.rtn-analyze-status.show{display:flex}
.rtn-analyze-status.ok{background:#dcfce7;color:#166534}
.rtn-analyze-status.err{background:#fee2e2;color:#dc2626}
.rtn-extracted-preview{background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-top:10px;display:none;font-size:12px}
.rtn-extracted-preview.show{display:block}
.rtn-extracted-preview table{width:100%;border-collapse:collapse}
.rtn-extracted-preview td{padding:3px 8px;color:#166534;vertical-align:top}
.rtn-extracted-preview td:first-child{font-weight:700;color:#15803d;white-space:nowrap;width:120px}
.rtn-crn-badge{display:inline-flex;align-items:center;gap:6px;background:#fef9c3;border:1.5px solid #fde047;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:700;color:#713f12;margin-top:6px}
`;

/* ══════════════════════════════════════════════════════
   2. ADD TO HTML — inside .pay-section-wrap, BEFORE <!-- Info banner -->
   Replace: <!-- Info banner -->
   With the block below, then put <!-- Info banner --> after it
══════════════════════════════════════════════════════ */
const HTML_TO_ADD = `
      <!-- ── Return Slip Upload & AI Extract ── -->
      <div class="rtn-upload-zone" id="rtnUploadZone">
        <div class="rtn-upload-header">
          <span class="rtn-upload-title">
            <i class="fa-solid fa-file-image"></i> Upload Return Slip Images
          </span>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label for="rtnImageInput" class="btn-rtn-upload">
              <i class="fa-solid fa-images"></i> Choose Images
            </label>
            <input type="file" id="rtnImageInput" accept="image/*" multiple style="display:none" onchange="onRtnImagesSelected(event)">
            <button type="button" class="btn-rtn-analyze" id="btnAnalyzeSlip" onclick="analyzeReturnSlip()" disabled>
              <i class="fa-solid fa-wand-magic-sparkles"></i> Extract Details
            </button>
          </div>
        </div>
        <div id="rtnPreviewStrip" class="rtn-preview-strip"></div>
        <div style="font-size:11px;color:#6b7280;">
          <i class="fa-solid fa-circle-info"></i> Upload cheque clearing slip images (multiple allowed). AI will extract cheque no., bank, branch, amount, CRN, return reason.
        </div>
        <div class="rtn-analyze-status" id="rtnAnalyzeStatus"></div>
        <div class="rtn-extracted-preview" id="rtnExtractedPreview"></div>
      </div>
`;

/* ══════════════════════════════════════════════════════
   3. ADD TO <script> SECTION (bottom, before closing </script>)
══════════════════════════════════════════════════════ */
const JS_TO_ADD = `
/* ════════════════════════════════════════════
   RETURN SLIP AI EXTRACTION
════════════════════════════════════════════ */
let rtnImages = []; // {file, dataUrl, base64, mediaType}

function onRtnImagesSelected(event) {
    const files = Array.from(event.target.files);
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const dataUrl = e.target.result;
            const base64 = dataUrl.split(',')[1];
            const mediaType = file.type || 'image/jpeg';
            rtnImages.push({ file, dataUrl, base64, mediaType });
            renderRtnPreviews();
        };
        reader.readAsDataURL(file);
    });
    event.target.value = '';
}

function renderRtnPreviews() {
    const strip = document.getElementById('rtnPreviewStrip');
    const btn   = document.getElementById('btnAnalyzeSlip');
    if (!strip) return;
    strip.innerHTML = rtnImages.map((img, i) => \`
        <div class="rtn-img-thumb">
            <img src="\${img.dataUrl}" alt="Slip \${i+1}">
            <button class="rm-img" onclick="removeRtnImage(\${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>
        </div>
    \`).join('');
    if (btn) btn.disabled = rtnImages.length === 0;
}

function removeRtnImage(idx) {
    rtnImages.splice(idx, 1);
    renderRtnPreviews();
}

/* Drag-and-drop on the upload zone */
(function(){
    document.addEventListener('DOMContentLoaded', () => {
        const zone = document.getElementById('rtnUploadZone');
        if (!zone) return;
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault(); zone.classList.remove('dragover');
            const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
            files.forEach(file => {
                const reader = new FileReader();
                reader.onload = ev => {
                    rtnImages.push({ file, dataUrl: ev.target.result, base64: ev.target.result.split(',')[1], mediaType: file.type });
                    renderRtnPreviews();
                };
                reader.readAsDataURL(file);
            });
        });
    });
})();

function setRtnStatus(msg, type) {
    const el = document.getElementById('rtnAnalyzeStatus');
    if (!el) return;
    el.textContent = msg;
    el.className = 'rtn-analyze-status show' + (type === 'ok' ? ' ok' : type === 'err' ? ' err' : '');
}

async function analyzeReturnSlip() {
    if (!rtnImages.length) return;
    const btn = document.getElementById('btnAnalyzeSlip');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Analyzing…';
    setRtnStatus('Sending images to AI for analysis…', '');

    const imgBlocks = rtnImages.map(img => ({
        type: 'image',
        source: { type: 'base64', media_type: img.mediaType, data: img.base64 }
    }));

    const prompt = \`You are analyzing cheque clearing return slip images from a Sri Lankan bank.
Extract ALL of the following details from the image(s). Look carefully at every part.

Return ONLY a JSON object (no markdown, no backticks, no explanation) with these exact keys:
{
  "cheque_no": "cheque number / cheque num printed on the slip (e.g. 862410)",
  "cheque_amount": "numeric amount only, no commas (e.g. 322000.00)",
  "cheque_date": "YYYY-MM-DD format if date visible on cheque",
  "bank_name": "paying bank name (e.g. Bank of Ceylon)",
  "bank_code": "paying bank code (numeric, e.g. 7010)",
  "branch_name": "paying branch name (e.g. Kuruwita)",
  "branch_code": "paying branch code (numeric, e.g. 325)",
  "return_reason": "return reason text (e.g. Refer to drawer)",
  "return_code": "return reason code number (e.g. 01)",
  "return_date": "YYYY-MM-DD format date of return",
  "crn_no": "CRN number — look for a box or highlighted area with format like '7214 00000079', combine both parts with space",
  "collecting_bank": "collecting bank name (e.g. National Development Bank PLC)",
  "collecting_branch": "collecting branch name",
  "first_presentment_date": "YYYY-MM-DD"
}

If a field is not visible or not applicable, use null.
The CRN number is often shown in a rectangular box in the top-right corner as two parts (e.g. 7214 and 00000079) — combine them as '7214 00000079'.\`;

    try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 1000,
                messages: [{
                    role: 'user',
                    content: [
                        ...imgBlocks,
                        { type: 'text', text: prompt }
                    ]
                }]
            })
        });

        const data = await response.json();
        const textBlock = data.content?.find(b => b.type === 'text');
        if (!textBlock) throw new Error('No text response from AI');

        let raw = textBlock.text.trim();
        raw = raw.replace(/^\`\`\`json|^\`\`\`|\`\`\`$/g, '').trim();
        const extracted = JSON.parse(raw);

        applyExtractedToForm(extracted);
        showExtractedPreview(extracted);
        setRtnStatus('✓ Details extracted and applied to form!', 'ok');
    } catch(e) {
        console.error(e);
        setRtnStatus('Error: ' + e.message, 'err');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Extract Details';
}

function applyExtractedToForm(d) {
    /* Fill cheque fields in the first cheque card (idx=1) */
    if (d.cheque_no   && document.getElementById('chqno-1'))   document.getElementById('chqno-1').value = d.cheque_no;
    if (d.cheque_date && document.getElementById('chqdate-1')) document.getElementById('chqdate-1').value = d.cheque_date;
    if (d.cheque_amount && document.getElementById('chqamt-1'))document.getElementById('chqamt-1').value = parseFloat(d.cheque_amount)||'';

    /* Bank select — try to match by bank_code or bank_name */
    if (d.bank_code) {
        const bkSel = document.getElementById('chq-bank-1');
        if (bkSel) {
            const opt = Array.from(bkSel.options).find(o => o.value === d.bank_code);
            if (opt) {
                \$('#chq-bank-1').val(d.bank_code).trigger('change');
                /* After change triggers branch load, set branch */
                setTimeout(() => applyBranch(d.branch_code, d.branch_name), 900);
            } else {
                /* bank_code not in list — load branches by code anyway */
                loadBranches(d.bank_code, 1);
                setTimeout(() => applyBranch(d.branch_code, d.branch_name), 900);
            }
        }
    }

    /* Settlement note with return reason */
    const note = document.getElementById('settlementNote');
    if (note && d.return_reason) note.value = d.return_reason + (d.return_code ? ' (' + d.return_code + ')' : '');

    /* Cash date from return_date */
    if (d.return_date) {
        const cd = document.getElementById('cashDate');
        if (cd) cd.value = d.return_date;
    }

    /* Store CRN in ARD for reference */
    if (d.crn_no) ARD.crn_no = d.crn_no;

    syncPreviews();
}

function applyBranch(branchCode, branchName) {
    if (!branchCode) return;
    const brSel = document.getElementById('chq-branch-1');
    if (!brSel) return;
    const opt = Array.from(brSel.options).find(o => o.value === branchCode);
    if (opt) \$('#chq-branch-1').val(branchCode).trigger('change');
}

function showExtractedPreview(d) {
    const el = document.getElementById('rtnExtractedPreview');
    if (!el) return;
    const row = (lbl, val) => val ? \`<tr><td>\${lbl}</td><td>\${esc(String(val))}</td></tr>\` : '';
    el.innerHTML = \`
        <div style="font-size:11px;font-weight:700;color:#166534;margin-bottom:6px;"><i class="fa-solid fa-circle-check"></i> Extracted Details</div>
        \${d.crn_no ? \`<div class="rtn-crn-badge"><i class="fa-solid fa-barcode"></i> CRN: \${esc(d.crn_no)}</div>\` : ''}
        <table style="margin-top:8px;">
            \${row('Cheque No.', d.cheque_no)}
            \${row('Amount', d.cheque_amount ? 'Rs. ' + parseFloat(d.cheque_amount).toLocaleString('en-US',{minimumFractionDigits:2}) : null)}
            \${row('Cheque Date', d.cheque_date)}
            \${row('Bank', d.bank_name + (d.bank_code ? ' (' + d.bank_code + ')' : ''))}
            \${row('Branch', d.branch_name + (d.branch_code ? ' (' + d.branch_code + ')' : ''))}
            \${row('Return Reason', d.return_reason + (d.return_code ? ' (' + d.return_code + ')' : ''))}
            \${row('Return Date', d.return_date)}
            \${row('Collecting Bank', d.collecting_bank)}
        </table>
    \`;
    el.classList.add('show');
}
`;
