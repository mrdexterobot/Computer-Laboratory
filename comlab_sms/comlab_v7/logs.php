<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user = requireAuth('logs');
$base = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Audit Logs — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'logs'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Audit Logs'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Audit Logs</h1>
      <p class="page-subtitle">System activity and security event trail.</p>
    </div>
    <button class="btn btn-ghost" onclick="load()"><i class="fas fa-rotate-right"></i> Refresh</button>
  </div>

  <div class="table-controls">
    <div class="search-box">
      <i class="fas fa-magnifying-glass"></i>
      <input type="text" id="logSearch" placeholder="Search action, user, description…" oninput="filterLogs()">
    </div>
    <input type="date" class="form-control" style="width:160px;height:36px;font-size:.8rem" id="logDate" onchange="filterLogs()">
    <select class="form-control" style="width:160px;height:36px;font-size:.8rem" id="logAction" onchange="filterLogs()">
      <option value="">All Actions</option>
      <option>Login</option><option>Login Failed</option><option>Logout</option>
      <option>User Created</option><option>User Updated</option><option>User Deleted</option>
    </select>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
        <tbody id="logBody"><tr><td colspan="5"><div class="empty-state" style="padding:2rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i></div></td></tr></tbody>
      </table>
    </div>
    <div id="logPager" style="padding:.75rem 1.25rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
      <span id="logMeta" style="font-size:.75rem;color:var(--muted)"></span>
      <div style="display:flex;gap:.35rem" id="logPages"></div>
    </div>
  </div>
</div>
</main>
<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API = '<?= $H($base) ?>api/logs.php';
let allLogs = [], page = 1, perPage = 50;

async function load() {
  const d  = await (await fetch(API)).json();
  allLogs  = d.logs || [];
  page     = 1;
  filterLogs();
}

function filterLogs() {
  const q  = document.getElementById('logSearch').value.toLowerCase();
  const dt = document.getElementById('logDate').value;
  const ac = document.getElementById('logAction').value;
  const filtered = allLogs.filter(l => {
    const t = `${l.action_type||''} ${l.performed_by||''} ${l.description||''} ${l.ip_address||''}`.toLowerCase();
    return (!q||t.includes(q)) && (!dt||l.created_at?.startsWith(dt)) && (!ac||l.action_type===ac);
  });
  renderPage(filtered);
}

function renderPage(rows) {
  const total = rows.length;
  const start = (page-1)*perPage, end = Math.min(start+perPage, total);
  const slice = rows.slice(start, end);
  const tb = document.getElementById('logBody');

  if (!slice.length) {
    tb.innerHTML = `<tr><td colspan="5"><div class="empty-state" style="padding:2rem"><i class="fas fa-history"></i><p>No logs found.</p></div></td></tr>`;
  } else {
    const ac = { 'Login':'badge-success','Login Failed':'badge-danger','Logout':'badge-secondary','User Created':'badge-info','User Deleted':'badge-danger' };
    tb.innerHTML = slice.map(l => `<tr>
      <td class="mono" style="font-size:.72rem;color:var(--muted);white-space:nowrap">${l.created_at?.replace('T',' ').slice(0,19)||'—'}</td>
      <td class="fw-600" style="font-size:.8rem">${esc(l.performed_by||'System')}</td>
      <td><span class="badge ${ac[l.action_type]||'badge-secondary'}" style="font-size:.6rem">${esc(l.action_type)}</span></td>
      <td class="u-sub" style="font-size:.78rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(l.description||'—')}</td>
      <td class="mono" style="font-size:.7rem;color:var(--muted)">${esc(l.ip_address||'—')}</td>
    </tr>`).join('');
  }

  const pages = Math.ceil(total/perPage)||1;
  document.getElementById('logMeta').textContent = `Showing ${start+1}–${end} of ${total}`;
  document.getElementById('logPages').innerHTML = Array.from({length:Math.min(pages,8)},(_,i) => {
    const p = i+1;
    return `<button class="ftab${p===page?' active':''}" onclick="page=${p};filterLogs()">${p}</button>`;
  }).join('');
}

load();
</script>
</body>
</html>
