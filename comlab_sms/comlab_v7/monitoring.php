<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user = requireAuth('monitoring');
$base = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Lab Monitoring — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'monitoring'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Lab Monitoring'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Lab Monitoring</h1>
      <p class="page-subtitle">Real-time view of lab activity and device status.</p>
    </div>
    <button class="btn btn-ghost" onclick="load()"><i class="fas fa-rotate-right"></i> Refresh</button>
  </div>

  <div class="card-grid" id="monGrid">
    <div class="stat-card" style="grid-column:1/-1;justify-content:center">
      <i class="fas fa-spinner fa-spin" style="color:var(--muted)"></i>
      <span style="color:var(--muted);font-size:.82rem">Loading…</span>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-calendar-day"></i> Today's Schedule</h3>
      <span class="badge badge-info" id="monCount">—</span>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Lab</th><th>Faculty</th><th>Class</th><th>Time</th><th>Attendance</th></tr></thead>
        <tbody id="monBody"><tr><td colspan="5"><div class="empty-state" style="padding:2rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i></div></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</main>
<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
async function load() {
  const d = await (await fetch('<?= $H($base) ?>api/monitoring.php')).json();
  const labs  = d.labs  || [];
  const sched = d.today_schedules || [];

  // Lab status cards
  const grid = document.getElementById('monGrid');
  grid.innerHTML = labs.length
    ? labs.map(l => `
      <div class="checkin-card" style="border-left-color:${l.is_active?'var(--green)':'var(--muted)'}">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
          <span class="code-tag">${esc(l.lab_code)}</span>
          <span class="badge ${l.is_active?'badge-success':'badge-secondary'}">${l.is_active?'Active':'Inactive'}</span>
        </div>
        <div class="fw-600" style="margin-bottom:.3rem">${esc(l.lab_name)}</div>
        <div style="font-size:.75rem;color:var(--muted)">${esc(l.building||'')} ${esc(l.floor||'')}</div>
        <div style="margin-top:.65rem;display:flex;gap:.75rem;font-size:.75rem">
          <span style="color:var(--green)"><i class="fas fa-circle-check"></i> ${l.devices_available||0} available</span>
          <span style="color:var(--orange)"><i class="fas fa-wrench"></i> ${l.devices_repair||0} repair</span>
        </div>
        <div class="rate-bar-wrap" style="margin-top:.5rem">
          <div class="rate-bar-fill" style="width:${l.capacity?Math.round(100*(l.device_count||0)/l.capacity):0}%;background:var(--blue)"></div>
        </div>
        <div style="font-size:.68rem;color:var(--muted);margin-top:3px">${l.device_count||0}/${l.capacity||0} devices</div>
      </div>`).join('')
    : '<p style="color:var(--muted);padding:1rem">No labs found.</p>';

  // Schedule table
  document.getElementById('monCount').textContent = sched.length;
  const tb = document.getElementById('monBody');
  if (!sched.length) {
    tb.innerHTML = `<tr><td colspan="5"><div class="empty-state" style="padding:2rem"><i class="fas fa-calendar-check"></i><p>No classes scheduled today.</p></div></td></tr>`; return;
  }
  tb.innerHTML = sched.map(r => {
    const sc = r.status==='Present'?'badge-success':r.status==='Absent'?'badge-danger':'badge-secondary';
    return `<tr>
      <td><span class="code-tag">${esc(r.lab_code)}</span></td>
      <td class="fw-600" style="font-size:.82rem">${esc(r.faculty_name)}</td>
      <td class="u-sub">${esc(r.class_name)}</td>
      <td class="mono" style="font-size:.75rem">${r.start_time?.slice(0,5)}–${r.end_time?.slice(0,5)}</td>
      <td><span class="badge ${sc}">${esc(r.status||'Scheduled')}</span></td>
    </tr>`;
  }).join('');
}
load();
setInterval(load, 60000); // auto-refresh every minute
</script>
</body>
</html>
