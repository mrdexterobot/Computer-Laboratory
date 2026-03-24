<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user    = requireAuth('dashboard');
$isAdmin = ($user['role'] === ROLE_ADMIN);
$base    = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dashboard — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'dashboard'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Dashboard'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Dashboard</h1>
      <p class="page-subtitle" id="dateLabel">Loading…</p>
    </div>
    <?php if ($isAdmin): ?>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
      <a href="<?= $H($base) ?>scheduling.php#request-employee-hr" class="btn btn-ghost">
        <i class="fas fa-user-plus"></i> Request Employee from HR
      </a>
      <a href="<?= $H($base) ?>scheduling.php" class="btn btn-navy">
        <i class="fas fa-calendar-plus"></i> Assign Schedule
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Stats -->
  <div class="stats-grid" id="statsGrid">
    <?php for ($i=0;$i<4;$i++): ?>
    <div class="stat-card">
      <div class="stat-icon-wrap si-blue"><i class="fas fa-spinner fa-spin"></i></div>
      <div><div class="stat-number">—</div><div class="stat-label">Loading…</div></div>
    </div>
    <?php endfor; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr<?= $isAdmin ? ' 340px' : '' ?>;gap:1rem;align-items:start">

    <!-- Today's schedules -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-calendar-day"></i> Today's Lab Schedules</h3>
        <span class="badge badge-info" id="todayCount">—</span>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Faculty</th>
              <th>Lab</th>
              <th>Class</th>
              <th>Time</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="schedBody">
            <tr><td colspan="5"><div class="empty-state" style="padding:2rem">
              <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i>
              <p>Loading…</p>
            </div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Pending requests -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Pending Requests</h3>
        <a href="<?= $H($base) ?>requests.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <div id="reqList" style="padding:.5rem 0">
        <div style="padding:1rem;text-align:center;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i></div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</main>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API  = '<?= $H($base) ?>api/dashboard.php';
const BASE = '<?= $H($base) ?>';
const ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

document.getElementById('dateLabel').textContent =
  new Date().toLocaleDateString('en-PH', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

async function load() {
  try {
    const d = await (await fetch(API)).json();
    if (!d.success) { console.error('Dashboard API:', d.message); return; }

    const s = d.stats || {};
    const today = d.today_schedules || [];

    // Stats
    const icons = ADMIN
      ? [
          ['si-blue',   'fa-users',   s.total_users   || 0, 'Total Users'],
          ['si-teal',   'fa-chalkboard-teacher', s.total_faculty||0, 'Faculty'],
          ['si-green',  'fa-circle-check', s.devices_available||0, 'Available Devices'],
          ['si-orange', 'fa-clipboard-list', s.pending_requests||0, 'Pending Requests'],
        ]
      : [
          ['si-blue',   'fa-calendar-check', today.length, "Today's Classes"],
          ['si-green',  'fa-circle-check',   s.present_this_week||0, 'Present This Week'],
          ['si-orange', 'fa-clipboard-list', s.my_pending_requests||0, 'My Requests'],
          ['si-teal',   'fa-desktop',        s.devices_available||0, 'Available Devices'],
        ];

    document.getElementById('statsGrid').innerHTML = icons.map(([cls,icon,val,label]) =>
      `<div class="stat-card">
        <div class="stat-icon-wrap ${cls}"><i class="fas ${icon}"></i></div>
        <div><div class="stat-number">${val}</div><div class="stat-label">${label}</div></div>
      </div>`
    ).join('');

    // Today's schedules
    document.getElementById('todayCount').textContent = today.length;
    const tb = document.getElementById('schedBody');
    if (!today.length) {
      tb.innerHTML = `<tr><td colspan="5"><div class="empty-state" style="padding:2rem">
        <i class="fas fa-calendar-check"></i><p>No schedules for today.</p></div></td></tr>`;
    } else {
      tb.innerHTML = today.map(r => {
        const sc = r.status === 'Present' ? 'badge-success'
                 : r.status === 'Absent'  ? 'badge-danger' : 'badge-secondary';
        return `<tr>
          <td class="fw-600">${esc(r.faculty_name || '—')}</td>
          <td><span class="code-tag">${esc(r.lab_code)}</span></td>
          <td class="u-sub">${esc(r.class_name)}</td>
          <td class="mono" style="font-size:.78rem">${r.start_time?.slice(0,5)}–${r.end_time?.slice(0,5)}</td>
          <td><span class="badge ${sc}">${esc(r.status || 'Scheduled')}</span></td>
        </tr>`;
      }).join('');
    }

    // Pending requests (admin only)
    if (ADMIN) {
      const reqs = d.pending_requests || [];
      const rl = document.getElementById('reqList');
      if (!reqs.length) {
        rl.innerHTML = '<p style="padding:1rem;text-align:center;color:var(--muted);font-size:.8rem">No pending requests.</p>';
      } else {
        rl.innerHTML = reqs.map(r => `
          <div style="padding:.65rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:.65rem">
            <div style="flex:1;min-width:0">
              <div style="font-size:.8rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.submitted_by_name)}</div>
              <div style="font-size:.73rem;color:var(--muted)">${esc(r.request_type)} · ${r.created_at?.slice(0,10)}</div>
              ${r.pmed_status ? `<div style="font-size:.65rem;margin-top:2px"><span class="badge ${r.pmed_status==='Approved'?'badge-success':(r.pmed_status==='Verified'?'badge-primary':'badge-secondary')}" style="padding:1px 6px;font-size:.6rem">PMED: ${esc(r.pmed_status)}</span></div>` : ''}
            </div>
            <span class="badge badge-warning">${esc(r.request_type)}</span>
          </div>`).join('');
      }
    }
  } catch(e) { console.error(e); }
}

// Automatic HR Sync for Admins (once per session)
if (ADMIN && !sessionStorage.getItem('hr_synced')) {
  fetch(`${BASE}api/workflow.php?action=sync_employees`)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        console.log('HR Sync:', d.message);
        sessionStorage.setItem('hr_synced', 'true');
      }
    }).catch(e => console.error('HR Sync Error:', e));
}

load();
</script>
</body>
</html>
