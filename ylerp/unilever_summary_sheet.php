<?php
/** unilever_summary_sheet.php — the one-page A4 sheet of the Unilever Receivable Summary (used by unilever_summary_print.php) */
function ursp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ursp_n($v) { return number_format((float)$v, 0); }
function ursp_b($v) { return $v < 0 ? '(' . number_format(abs($v), 0) . ')' : number_format($v, 0); }
function ursp_donut($pct, $got, $left) {
    $r = 46; $c = 2 * M_PI * $r; $p = max(0, min(100, $pct)); $dash = $c * $p / 100; $col = $p >= 90 ? '#16a34a' : ($p >= 60 ? '#f59e0b' : '#ef4444');
    return '<svg width="120" height="120" viewBox="0 0 120 120"><circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="#e2e8f0" stroke-width="14"/><circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="14" stroke-linecap="round" stroke-dasharray="' . round($dash, 2) . ' ' . round($c - $dash, 2) . '" transform="rotate(-90 60 60)"/>'
         . '<text x="60" y="64" text-anchor="middle" style="font:800 20px Arial,sans-serif;fill:#0f172a">' . round($p) . '%</text><text x="60" y="79" text-anchor="middle" style="font:600 8px Arial,sans-serif;fill:#64748b">recovered</text></svg>';
}
/** $d = ['rows'=>[], 'groups'=>[rec=>[..],paid=>[..]], 'total'=>[...]], $meta = ['title','sub','by','at'] */
function ursp_sheet($d, $meta) {
    $rows = $d['rows']; $G = $d['groups']; $t = $d['total'];
    $claimed = ($G['rec']['receivable'] ?? 0) + ($G['paid']['paid'] ?? 0); $pct = $claimed > 0 ? $t['received'] / $claimed * 100 : 0;
    $rec = array_values(array_filter($rows, fn($r) => ($r['group'] ?? 'rec') === 'rec')); $paid = array_values(array_filter($rows, fn($r) => ($r['group'] ?? '') === 'paid'));
    $mx = 1; foreach ($rows as $r) $mx = max($mx, abs($r['balance']));
    $bars = $rows; usort($bars, fn($a, $b) => abs($b['balance']) <=> abs($a['balance']));
    ob_start(); ?>
<div class="sheet" id="ursSheet">
  <div class="hd"><div><div class="co">YELO GROUP · Management System</div><h1>Unilever Receivable Summary</h1><div class="sb"><?= ursp_h($meta['sub']) ?></div></div>
    <div class="hr"><div class="bdg">Vendor &amp; Customer Ledger</div><div class="gen">Generated <?= ursp_h($meta['at']) ?><br>by <?= ursp_h($meta['by']) ?></div></div></div>

  <div class="kp">
    <div class="k k1"><span>Receivable</span><b>Rs. <?= ursp_n($G['rec']['receivable'] ?? 0) ?></b><small>from Unilever</small></div>
    <div class="k k2"><span>Paid out</span><b>Rs. <?= ursp_n($G['paid']['paid'] ?? 0) ?></b><small>claimed back</small></div>
    <div class="k k3"><span>Received</span><b>Rs. <?= ursp_n($t['received']) ?></b><small><?= round($pct) ?>% of everything claimed</small></div>
    <div class="k <?= $t['balance'] > 0.5 ? 'k4' : 'k5' ?>"><span>Net balance</span><b>Rs. <?= ursp_b($t['balance']) ?></b><small><?= $t['balance'] > 0.5 ? 'Unilever owes us' : 'settled / over-received' ?></small></div>
  </div>

  <div class="mid">
    <div class="box dn"><div class="bt">Recovery</div><div class="dnw"><?= ursp_donut($pct, $t['received'], $claimed - $t['received']) ?>
      <div class="lg"><div><i style="background:#16a34a"></i>Received<br><b>Rs. <?= ursp_n($t['received']) ?></b></div><div><i style="background:#ef4444"></i>Still to receive<br><b>Rs. <?= ursp_n(max(0, $claimed - $t['received'])) ?></b></div></div></div></div>
    <div class="box bw"><div class="bt">Balance by line <small>red = Unilever owes us · green = over-received</small></div>
      <?php foreach ($bars as $r): $w = max(2, abs($r['balance']) / $mx * 100); $pos = $r['balance'] > 0.5; ?>
        <div class="br"><div class="bl"><?= ursp_h($r['label']) ?></div><div class="bb"><i style="width:<?= round($w, 1) ?>%;background:<?= $pos ? 'linear-gradient(90deg,#f87171,#dc2626)' : 'linear-gradient(90deg,#4ade80,#16a34a)' ?>"></i></div><div class="bv" style="color:<?= $pos ? '#b91c1c' : '#15803d' ?>"><?= ursp_b($r['balance']) ?></div></div>
      <?php endforeach; ?></div>
  </div>

  <div class="tb"><div class="tt"><b>Receivable from Unilever</b><span>Customer Ledger &amp; Vendor Ledger</span></div>
    <table><thead><tr><th>Description</th><th class="r">Receivable</th><th class="r">Received</th><th class="r">Balance</th><th class="pc">Recovered</th></tr></thead><tbody>
    <?php foreach ($rec as $r): $base = (float)$r['receivable']; $p = $base > 0 ? max(0, min(100, round($r['received'] / $base * 100))) : 0; $bc = $r['balance'] > 0.5 ? 'pos' : ($r['balance'] < -0.5 ? 'neg' : 'zero'); ?>
      <tr><td><b><?= ursp_h($r['label']) ?></b><em><?= ursp_h($r['source']) ?></em></td><td class="r"><?= $base ? ursp_n($base) : '—' ?></td><td class="r"><?= $r['received'] ? ursp_n($r['received']) : '—' ?></td><td class="r"><span class="bal <?= $bc ?>"><?= ursp_b($r['balance']) ?></span></td><td class="pc"><div class="pb"><i style="width:<?= $p ?>%;background:<?= $p >= 95 ? '#16a34a' : ($p >= 60 ? '#f59e0b' : '#ef4444') ?>"></i></div><span><?= $p ?>%</span></td></tr>
    <?php endforeach; if (!$rec): ?><tr><td colspan="5" class="nd">No lines.</td></tr><?php endif; ?></tbody>
    <tfoot><tr><td>Total</td><td class="r"><?= ursp_n($G['rec']['receivable'] ?? 0) ?></td><td class="r"><?= ursp_n($G['rec']['received'] ?? 0) ?></td><td class="r"><?= ursp_b($G['rec']['balance'] ?? 0) ?></td><td></td></tr></tfoot></table></div>

  <?php if ($paid): ?>
  <div class="tb"><div class="tt"><b>Paid by us — claimed back from Unilever</b><span>Drivers &amp; Loyalty · Promoters Salary · SR Incentive</span></div>
    <table><thead><tr><th>Description</th><th class="r">Paid</th><th class="r">Received</th><th class="r">Balance</th><th class="pc">Recovered</th></tr></thead><tbody>
    <?php foreach ($paid as $r): $base = (float)($r['paid'] ?? 0); $p = $base > 0 ? max(0, min(100, round($r['received'] / $base * 100))) : 0; $bc = $r['balance'] > 0.5 ? 'pos' : ($r['balance'] < -0.5 ? 'neg' : 'zero'); ?>
      <tr><td><b><?= ursp_h($r['label']) ?></b><em><?= ursp_h($r['source']) ?></em></td><td class="r"><?= $base ? ursp_n($base) : '—' ?></td><td class="r"><?= $r['received'] ? ursp_n($r['received']) : '—' ?></td><td class="r"><span class="bal <?= $bc ?>"><?= ursp_b($r['balance']) ?></span></td><td class="pc"><div class="pb"><i style="width:<?= $p ?>%;background:<?= $p >= 95 ? '#16a34a' : ($p >= 60 ? '#f59e0b' : '#ef4444') ?>"></i></div><span><?= $p ?>%</span></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td>Total</td><td class="r"><?= ursp_n($G['paid']['paid'] ?? 0) ?></td><td class="r"><?= ursp_n($G['paid']['received'] ?? 0) ?></td><td class="r"><?= ursp_b($G['paid']['balance'] ?? 0) ?></td><td></td></tr></tfoot></table></div>
  <?php endif; ?>

  <div class="ft"><div class="nt"><b>How to read:</b> Balance = Receivable (or Paid) − Received. A positive balance means Unilever still owes us; a bracketed figure means more was received than claimed. <?= ursp_h($meta['note'] ?? '') ?></div>
    <div class="sg"><div>Prepared by<br><span></span></div><div>Checked by<br><span></span></div><div>Approved by<br><span></span></div></div></div>
</div>
<?php return ob_get_clean();
}
function ursp_css() { return <<<'CSS'
@page{size:A4 portrait;margin:0}
.sheet{width:210mm;height:297mm;box-sizing:border-box;padding:10mm 11mm 9mm;background:#fff;font-family:Arial,Helvetica,sans-serif;color:#0f172a;display:flex;flex-direction:column;gap:3.2mm;overflow:hidden;position:relative}
.sheet *{box-sizing:border-box}
.hd{display:flex;justify-content:space-between;align-items:flex-start;padding:5mm 6mm;border-radius:3mm;color:#fff;background:linear-gradient(120deg,#0b1220,#1e3a8a 62%,#0e7490)}
.hd .co{font-size:7.5pt;letter-spacing:.14em;text-transform:uppercase;opacity:.8;font-weight:700}.hd h1{margin:1mm 0 .6mm;font-size:17pt;letter-spacing:.2px}.hd .sb{font-size:8pt;opacity:.85}
.hr{text-align:right}.bdg{display:inline-block;border:1px solid rgba(255,255,255,.45);border-radius:99px;padding:1mm 3mm;font-size:7pt;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.gen{margin-top:2mm;font-size:7pt;opacity:.8;line-height:1.4}
.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:2.6mm}.k{border-radius:2.6mm;padding:3mm 3.4mm;color:#fff;position:relative;overflow:hidden}.k::after{content:"";position:absolute;right:-6mm;top:-6mm;width:20mm;height:20mm;border-radius:50%;background:rgba(255,255,255,.15)}
.k span{display:block;font-size:6.8pt;font-weight:700;letter-spacing:.09em;text-transform:uppercase;opacity:.92}.k b{display:block;font-size:12.5pt;margin-top:1.6mm}.k small{display:block;margin-top:.6mm;font-size:6.6pt;opacity:.88}
.k1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}.k2{background:linear-gradient(135deg,#f59e0b,#d97706)}.k3{background:linear-gradient(135deg,#22c55e,#15803d)}.k4{background:linear-gradient(135deg,#ef4444,#b91c1c)}.k5{background:linear-gradient(135deg,#14b8a6,#0f766e)}
.mid{display:grid;grid-template-columns:62mm 1fr;gap:2.6mm}.box{border:1px solid #e5e7eb;border-radius:2.6mm;padding:3mm 3.4mm}.bt{font-weight:800;font-size:8.6pt;margin-bottom:1.6mm;display:flex;justify-content:space-between;align-items:baseline}.bt small{font-weight:400;font-size:6.4pt;color:#94a3b8}
.dnw{display:flex;align-items:center;gap:3mm}.lg{font-size:7pt;color:#475569;line-height:1.35}.lg div{margin-bottom:1.6mm}.lg i{display:inline-block;width:2.2mm;height:2.2mm;border-radius:.6mm;margin-right:1.2mm}.lg b{color:#0f172a;font-size:8pt}
.br{display:grid;grid-template-columns:40mm 1fr 19mm;gap:2mm;align-items:center;padding:.75mm 0;font-size:7pt}.bl{font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bb{height:2.6mm;background:#f1f5f9;border-radius:9mm;position:relative;overflow:hidden}.bb i{position:absolute;left:0;top:0;bottom:0;border-radius:9mm}.bv{text-align:right;font-weight:800}
.tb{border:1px solid #e5e7eb;border-radius:2.6mm;overflow:hidden}.tt{display:flex;justify-content:space-between;align-items:baseline;padding:2mm 3.4mm;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-size:8.6pt}.tt span{font-size:6.6pt;color:#94a3b8}
table{width:100%;border-collapse:collapse;font-size:7.6pt}th{font-size:6.4pt;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;padding:1.4mm 3.4mm;font-weight:700}td{padding:1.5mm 3.4mm;border-top:1px solid #eef2f7}.r{text-align:right}
td b{display:block;font-size:7.8pt}td em{display:block;font-style:normal;font-size:6pt;color:#94a3b8}.bal{display:inline-block;min-width:21mm;text-align:right;padding:.5mm 2mm;border-radius:1.4mm;font-weight:800}.bal.pos{background:#fee2e2;color:#b91c1c}.bal.neg{background:#dcfce7;color:#15803d}.bal.zero{background:#f1f5f9;color:#64748b}
.pc{width:30mm}.pb{height:2mm;background:#e2e8f0;border-radius:9mm;overflow:hidden;margin-bottom:.5mm}.pb i{display:block;height:100%;border-radius:9mm}.pc span{font-size:6.2pt;color:#64748b}
tfoot td{background:#0f172a;color:#fff;font-weight:800;font-size:7.8pt;border-top:0}.nd{text-align:center;color:#94a3b8}
.ft{margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;gap:6mm;font-size:6.6pt;color:#64748b;line-height:1.45}.nt{max-width:100mm}.sg{display:flex;gap:5mm}.sg div{width:32mm;text-align:center;font-size:6.6pt}.sg span{display:block;border-bottom:1px solid #94a3b8;height:7mm;margin-bottom:.8mm}
CSS;
}
