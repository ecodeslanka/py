<?php
// online_users.php – Live "Who is online" page (auto-refreshing)
include 'header.php';
require_once __DIR__ . '/online_presence.php';

if (!presence_can_view()) {
    echo '<div style="padding:40px;text-align:center"><h2>Access denied</h2><p>Only administrators can view live sessions.</p></div>';
    include 'footer.php';
    exit;
}
presence_touch($conn, true);
?>
<style>
.ou-wrap{padding:4px 0}
.ou-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:18px}
.ou-live{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:#16a34a;font-weight:600}
.ou-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;animation:ouPulse 1.6s infinite}
.ou-live.err{color:#dc2626}.ou-live.err .ou-dot{background:#dc2626;animation:none}
@keyframes ouPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}70%{box-shadow:0 0 0 9px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.ou-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.ou-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;display:flex;gap:12px;align-items:center}
.ou-card i{font-size:20px;width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center}
.ou-card b{display:block;font-size:26px;line-height:1.1;color:#111827}
.ou-card span{font-size:12px;color:#6b7280}
.ou-c-total i{background:#eff6ff;color:#2563eb}.ou-c-work i{background:#f0fdf4;color:#16a34a}
.ou-c-idle i{background:#fffbeb;color:#d97706}.ou-c-pc i{background:#f5f3ff;color:#7c3aed}
.ou-c-mob i{background:#fdf2f8;color:#db2777}.ou-c-tab i{background:#ecfeff;color:#0891b2}
.ou-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.ou-tools{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.ou-tools input,.ou-tools select{padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px}
.ou-tools input{flex:1;min-width:180px}
.ou-table-wrap{overflow-x:auto}
table.ou-t{width:100%;border-collapse:collapse;font-size:13px}
.ou-t th{background:#f8fafc;text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;white-space:nowrap}
.ou-t td{padding:11px 12px;border-top:1px solid #f1f5f9;white-space:nowrap;color:#111827}
.ou-t tr.me{background:#f0f9ff}
.ou-user{display:flex;align-items:center;gap:10px}
.ou-av{width:34px;height:34px;border-radius:50%;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px}
.ou-user small{display:block;color:#6b7280;font-size:11px}
.ou-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.ou-pill.working{background:#dcfce7;color:#15803d}.ou-pill.idle{background:#fef3c7;color:#b45309}
.ou-pill i{font-size:7px}
.ou-dev{display:inline-flex;align-items:center;gap:7px}
.ou-dev.desktop i{color:#7c3aed}.ou-dev.mobile i{color:#db2777}.ou-dev.tablet i{color:#0891b2}
.ou-ip{font-family:ui-monospace,Menlo,Consolas,monospace;background:#f1f5f9;padding:2px 7px;border-radius:6px}
.ou-empty{padding:50px;text-align:center;color:#9ca3af}
.ou-empty i{font-size:38px;display:block;margin-bottom:10px;opacity:.5}
body.ms-dark .ou-card,body.ms-dark .ou-box{background:#171a23;border-color:#2a2f3d}
body.ms-dark .ou-card b,body.ms-dark .ou-t td{color:#e5e7eb}
body.ms-dark .ou-t th{background:#1d2130}body.ms-dark .ou-t td{border-color:#2a2f3d}
body.ms-dark .ou-tools input,body.ms-dark .ou-tools select{background:#0f1117;color:#e5e7eb;border-color:#2a2f3d}
</style>

<div class="ou-wrap">
  <div class="ou-head">
    <h2 class="page-title" style="margin:0"><i class="fa-solid fa-users-viewfinder"></i> Live Online Users</h2>
    <div class="ou-live" id="ouLive"><span class="ou-dot"></span><span id="ouLiveTxt">Connecting…</span></div>
  </div>

  <div class="ou-cards">
    <div class="ou-card ou-c-total"><i class="fa-solid fa-user-group"></i><div><b id="cTotal">0</b><span>Sessions online</span></div></div>
    <div class="ou-card ou-c-work"><i class="fa-solid fa-bolt"></i><div><b id="cWork">0</b><span>Working now</span></div></div>
    <div class="ou-card ou-c-idle"><i class="fa-solid fa-mug-hot"></i><div><b id="cIdle">0</b><span>Idle</span></div></div>
    <div class="ou-card ou-c-pc"><i class="fa-solid fa-desktop"></i><div><b id="cPc">0</b><span>PC / Laptop</span></div></div>
    <div class="ou-card ou-c-mob"><i class="fa-solid fa-mobile-screen"></i><div><b id="cMob">0</b><span>Mobile</span></div></div>
    <div class="ou-card ou-c-tab"><i class="fa-solid fa-tablet-screen-button"></i><div><b id="cTab">0</b><span>Tablet</span></div></div>
  </div>

  <div class="ou-box">
    <div class="ou-tools">
      <input type="search" id="ouSearch" placeholder="Search user, IP, browser, page…">
      <select id="ouDev"><option value="">All devices</option><option value="desktop">PC</option><option value="mobile">Mobile</option><option value="tablet">Tablet</option></select>
      <select id="ouSt"><option value="">All status</option><option value="working">Working</option><option value="idle">Idle</option></select>
    </div>
    <div class="ou-table-wrap">
      <table class="ou-t">
        <thead><tr><th>User</th><th>Status</th><th>Device</th><th>IP address</th><th>OS / Browser</th><th>Current page</th><th>Logged in</th><th>Online for</th><th>Last action</th></tr></thead>
        <tbody id="ouBody"></tbody>
      </table>
      <div class="ou-empty" id="ouEmpty" style="display:none"><i class="fa-solid fa-user-slash"></i>No users online</div>
    </div>
  </div>
  <p style="font-size:12px;color:#9ca3af;margin-top:10px">Updates every 5 seconds. “Working” = activity in the last 2 minutes; “Idle” = tab open but no activity. A user disappears ~90 seconds after closing the browser or logging out.</p>
</div>

<script>
(function(){
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const DEV = {desktop:['fa-desktop','PC / Laptop'], mobile:['fa-mobile-screen','Mobile'], tablet:['fa-tablet-screen-button','Tablet']};
  let data = [];

  function render(){
    const q = $('ouSearch').value.toLowerCase(), d = $('ouDev').value, s = $('ouSt').value;
    const rows = data.filter(u =>
      (!d || u.device === d) && (!s || u.status === s) &&
      (!q || [u.username,u.role,u.ip,u.browser,u.os,u.page].join(' ').toLowerCase().includes(q)));
    $('ouBody').innerHTML = rows.map(u => {
      const dv = DEV[u.device] || DEV.desktop;
      return `<tr class="${u.is_me?'me':''}">
        <td><div class="ou-user"><div class="ou-av">${esc(u.username.substr(0,2).toUpperCase())}</div>
            <div>${esc(u.username)}${u.is_me?' <small style="display:inline">(you)</small>':''}<small>${esc(u.role)}</small></div></div></td>
        <td><span class="ou-pill ${u.status}"><i class="fa-solid fa-circle"></i>${u.status==='working'?'Working':'Idle'}</span></td>
        <td><span class="ou-dev ${esc(u.device)}"><i class="fa-solid ${dv[0]}"></i>${dv[1]}</span></td>
        <td><span class="ou-ip">${esc(u.ip)}</span></td>
        <td>${esc(u.os)}<small style="display:block;color:#6b7280">${esc(u.browser)}</small></td>
        <td>${esc(u.page||'-')}</td>
        <td>${esc(u.login_time)}</td>
        <td>${esc(u.session_for)}</td>
        <td>${esc(u.idle_for)} ago</td></tr>`;
    }).join('');
    $('ouEmpty').style.display = rows.length ? 'none' : 'block';
  }

  async function poll(){
    try{
      const r = await fetch('online_users_api.php?action=list', {credentials:'same-origin', cache:'no-store'});
      if(r.status === 401){ location.href = 'login.php?timeout=1'; return; }
      const j = await r.json();
      if(!j.ok) throw new Error(j.error || 'error');
      data = j.users;
      const s = j.summary;
      $('cTotal').textContent = s.total; $('cWork').textContent = s.working; $('cIdle').textContent = s.idle;
      $('cPc').textContent = s.desktop; $('cMob').textContent = s.mobile; $('cTab').textContent = s.tablet;
      $('ouLive').classList.remove('err');
      $('ouLiveTxt').textContent = 'Live · ' + s.unique_users + ' user' + (s.unique_users===1?'':'s') + ' · ' + new Date().toLocaleTimeString();
      render();
    }catch(e){
      $('ouLive').classList.add('err'); $('ouLiveTxt').textContent = 'Connection lost – retrying…';
    }
  }
  ['ouSearch','ouDev','ouSt'].forEach(id => $(id).addEventListener('input', render));
  poll(); setInterval(() => { if(!document.hidden) poll(); }, 5000);
  document.addEventListener('visibilitychange', () => { if(!document.hidden) poll(); });
})();
</script>
<?php include 'footer.php'; ?>
