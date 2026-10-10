<!DOCTYPE html>
<html>
<head>
<title>OA Ajax Diagnostics</title>
<style>
body { font-family: monospace; padding: 20px; background: #1a1a2e; color: #eee; }
h2 { color: #7c3aed; }
.box { background: #16213e; border: 1px solid #333; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
.label { font-size: 11px; color: #9ca3af; text-transform: uppercase; margin-bottom: 6px; }
pre { white-space: pre-wrap; word-break: break-all; font-size: 12px; margin: 0; }
.ok { color: #4ade80; } .err { color: #f87171; } .warn { color: #fbbf24; }
button { background: #7c3aed; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; margin-right: 8px; margin-bottom: 8px; }
button:hover { background: #6d28d9; }
input { background: #0f3460; border: 1px solid #444; color: #fff; padding: 6px 10px; border-radius: 5px; font-size: 13px; width: 120px; }
</style>
</head>
<body>
<h2>🔍 OA Ajax Diagnostics</h2>

<div class="box">
  <div class="label">Test GET — get_oa_txns</div>
  Invoice ID: <input type="number" id="inv_id" value="1" />
  <br><br>
  <button onclick="testGet()">Run GET Test</button>
  <button onclick="testRaw()">Show Raw Bytes</button>
</div>

<div class="box">
  <div class="label">Test POST — save_oa_txn (dummy data)</div>
  <button onclick="testPost()">Run POST Test</button>
</div>

<div class="box">
  <div class="label">Results</div>
  <pre id="out">Click a test button above…</pre>
</div>

<script>
function log(msg, cls) {
  const out = document.getElementById('out');
  out.innerHTML += `<span class="${cls||''}">${msg}</span>\n`;
}
function clear() { document.getElementById('out').innerHTML = ''; }

async function testGet() {
  clear();
  const inv_id = document.getElementById('inv_id').value;
  log(`→ GET oa_ajax.php?action=get_oa_txns&invoice_id=${inv_id}`, 'warn');

  try {
    const r = await fetch(`oa_ajax.php?action=get_oa_txns&invoice_id=${inv_id}`);
    log(`HTTP Status: ${r.status} ${r.statusText}`, r.ok ? 'ok' : 'err');

    const headers = [...r.headers.entries()].map(([k,v]) => `  ${k}: ${v}`).join('\n');
    log(`Response Headers:\n${headers}`, '');

    const raw = await r.text();
    log(`\nRaw response length: ${raw.length} chars`, '');
    log(`First 20 char codes: ${[...raw.slice(0,20)].map(c => c.charCodeAt(0)).join(', ')}`, 'warn');
    log(`\nRaw response:\n${escHtml(raw)}`, '');

    // Try parse
    const cleaned = raw.trim().replace(/^\uFEFF/, '');
    try {
      const json = JSON.parse(cleaned);
      log(`\n✅ JSON parsed OK!`, 'ok');
      log(`ok: ${json.ok}`, json.ok ? 'ok' : 'err');
      log(`rows count: ${json.rows ? json.rows.length : 'N/A'}`, 'ok');
      if (json.msg) log(`msg: ${json.msg}`, 'warn');
      if (json.rows && json.rows.length > 0) {
        log(`\nFirst row:`, 'ok');
        log(JSON.stringify(json.rows[0], null, 2), '');
      }
    } catch(e) {
      log(`\n❌ JSON.parse FAILED: ${e.message}`, 'err');
      log(`Cleaned string starts with: ${JSON.stringify(cleaned.slice(0, 100))}`, 'warn');

      // Find where JSON starts/ends
      const firstBrace = cleaned.indexOf('{');
      const firstBracket = cleaned.indexOf('[');
      log(`First '{' at index: ${firstBrace}`, 'warn');
      log(`First '[' at index: ${firstBracket}`, 'warn');
      if (firstBrace > 0) {
        log(`Content BEFORE JSON (likely the problem):\n${JSON.stringify(cleaned.slice(0, firstBrace))}`, 'err');
        try {
          const json2 = JSON.parse(cleaned.slice(firstBrace));
          log(`\n⚠️ JSON parsed after stripping prefix — something is leaking before the JSON!`, 'warn');
          log(JSON.stringify(json2, null, 2), '');
        } catch(e2) {
          log(`Still failed after stripping prefix: ${e2.message}`, 'err');
        }
      }
    }
  } catch(e) {
    log(`❌ Fetch error: ${e}`, 'err');
  }
}

async function testRaw() {
  clear();
  const inv_id = document.getElementById('inv_id').value;
  log('→ Fetching raw bytes…', 'warn');
  try {
    const r = await fetch(`oa_ajax.php?action=get_oa_txns&invoice_id=${inv_id}`);
    const buf = await r.arrayBuffer();
    const bytes = new Uint8Array(buf);
    log(`Total bytes: ${bytes.length}`, 'ok');
    log(`First 60 bytes as decimal:\n${Array.from(bytes.slice(0,60)).join(' ')}`, 'warn');
    log(`First 60 bytes as chars:\n${Array.from(bytes.slice(0,60)).map(b => b < 32 ? `[${b}]` : String.fromCharCode(b)).join('')}`, '');

    // Check for BOM
    if (bytes[0]===0xEF && bytes[1]===0xBB && bytes[2]===0xBF)
      log('\n❌ UTF-8 BOM detected at start of file! Remove it from oa_ajax.php or config.php.', 'err');
    else if (bytes[0]===0xFF && bytes[1]===0xFE)
      log('\n❌ UTF-16 LE BOM detected!', 'err');
    else if (bytes[0] !== 0x7B && bytes[0] !== 0x5B)
      log(`\n⚠️ File does not start with { or [ — starts with byte ${bytes[0]} ('${String.fromCharCode(bytes[0])}')`, 'err');
    else
      log('\n✅ No BOM, starts with JSON character.', 'ok');
  } catch(e) {
    log(`❌ ${e}`, 'err');
  }
}

async function testPost() {
  clear();
  log('→ POST oa_ajax.php action=save_oa_txn (dummy invoice_id=999999)', 'warn');
  const fd = new FormData();
  fd.append('action', 'save_oa_txn');
  fd.append('invoice_id', '999999');
  fd.append('invoice_no', 'TEST-DIAG-001');
  fd.append('ledger_row_id', '0');
  fd.append('ledger_ref', 'diag test');
  fd.append('notes', 'diagnostic test');
  fd.append('lines[0][description]', 'Test line');
  fd.append('lines[0][amount]', '100.00');
  fd.append('lines[0][date]', '2025-01-01');

  try {
    const r = await fetch('oa_ajax.php', { method: 'POST', body: fd });
    log(`HTTP Status: ${r.status}`, r.ok ? 'ok' : 'err');
    const raw = await r.text();
    log(`Raw response:\n${escHtml(raw)}`, '');
    try {
      const json = JSON.parse(raw.trim().replace(/^\uFEFF/,''));
      log(`\n✅ JSON parsed OK: ok=${json.ok}, msg=${json.msg}`, json.ok ? 'ok' : 'warn');
      if (json.ok) {
        log(`Transaction created with id=${json.txn_id} — cleaning it up now…`, 'warn');
        // Clean up the test record
        const fd2 = new FormData();
        fd2.append('action', 'delete_oa_txn');
        fd2.append('txn_id', json.txn_id);
        const r2 = await fetch('oa_ajax.php', { method: 'POST', body: fd2 });
        const d2 = await r2.json();
        log(`Cleanup: ${d2.msg}`, d2.ok ? 'ok' : 'err');
      }
    } catch(e) {
      log(`❌ JSON parse failed: ${e.message}`, 'err');
    }
  } catch(e) {
    log(`❌ Fetch error: ${e}`, 'err');
  }
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
