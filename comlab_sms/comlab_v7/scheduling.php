<?php
// ============================================
// COMLAB - Faculty Schedules Page (NEW)
// Admin: create/cancel recurring schedules, view all.
// Faculty: read-only view of assigned schedules.
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$currentUser = requireAuth('scheduling');
$role        = $currentUser['role'];
$isAdmin     = ($role === ROLE_ADMIN);
$csrfToken   = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Schedules — COMLAB</title>
  <link rel="stylesheet" href="<?= getBasePath() ?>assets/comlab.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
</head>
<body>
<?php renderSidebar($currentUser, 'scheduling'); ?>

<main class="main" id="main">
  <?php renderTopbar($currentUser, 'Faculty Schedules'); ?>

  <div class="page-content">

    <!-- ── Header Row ─────────────────────────────────────────────── -->
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <?= $isAdmin ? 'Faculty Schedule Management' : 'My Schedule' ?>
        </h1>
        <p class="page-subtitle">
          <?= $isAdmin
            ? 'Assign recurring lab schedules to faculty members.'
            : 'View your assigned lab schedule for the current semester.' ?>
        </p>
      </div>
      <?php if ($isAdmin): ?>
      <button class="btn btn-primary" onclick="openModal('schedModal')">
        <i class="fas fa-plus"></i> Assign Schedule
      </button>
      <?php endif; ?>
    </div>

    <!-- ── Stats (Admin only) ──────────────────────────────────────── -->
    <?php if ($isAdmin): ?>
    <div class="stats-grid" id="statsGrid">
      <div class="stat-card"><div class="stat-number" id="statTotal">—</div><div class="stat-label">Total Schedules</div></div>
      <div class="stat-card"><div class="stat-number" id="statActive">—</div><div class="stat-label">Active</div></div>
      <div class="stat-card"><div class="stat-number" id="statToday">—</div><div class="stat-label">Today's Classes</div></div>
      <div class="stat-card"><div class="stat-number" id="statFaculty">—</div><div class="stat-label">Faculty Assigned</div></div>
    </div>
    <?php endif; ?>

    <!-- ── Today banner (Faculty only) ────────────────────────────── -->
    <?php if (!$isAdmin): ?>
    <div class="alert alert-info" id="todayBanner" style="display:none"></div>
    <?php endif; ?>

    <!-- ── Filter bar (Admin only) ────────────────────────────────── -->
    <?php if ($isAdmin): ?>
    <div class="table-controls">
      <input class="form-control" type="text" id="searchInput"
             placeholder="Search class, faculty, lab…" oninput="filterTable()">
      <select class="form-control" id="statusFilter" onchange="filterTable()">
        <option value="">All Status</option>
        <option value="1">Active</option>
        <option value="0">Cancelled</option>
      </select>
      <button class="btn btn-ghost" onclick="exportCSV()">
        <i class="fas fa-download"></i> Export
      </button>
    </div>
    <?php endif; ?>

    <!-- ── Schedule Table ─────────────────────────────────────────── -->
    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="data-table" id="schedTable">
            <thead>
              <tr>
                <?php if ($isAdmin): ?>
                <th>Faculty</th>
                <?php endif; ?>
                <th>Class</th>
                <th>Lab</th>
                <th>Days</th>
                <th>Time</th>
                <th>Semester</th>
                <th>Dept</th>
                <th>Status</th>
                <?php if ($isAdmin): ?>
                <th>Attendance</th>
                <th>Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="schedTableBody">
              <tr><td colspan="10" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div><!-- /page-content -->
</main>

<!-- ── Assign Schedule Modal (Admin only) ──────────────────────────── -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="schedModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-calendar-plus"></i> Assign Faculty Schedule</h3>
      <button class="modal-close" onclick="closeModal('schedModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="schedFormError" class="alert alert-danger" style="display:none"></div>
      <form id="schedForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Faculty Member *</label>
            <select class="form-control" name="faculty_id" id="facultySelect" required>
              <option value="">Loading faculty…</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Lab *</label>
            <select class="form-control" name="location_id" id="labSelect" required>
              <option value="">Select lab…</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Class Name *</label>
          <input class="form-control" type="text" name="class_name"
                 placeholder="e.g. CIS101 – Intro to Computing" required>
        </div>

        <div class="form-group">
          <label class="form-label">Days of Week *</label>
          <div class="checkbox-group" id="dayCheckboxes">
            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
            <label class="checkbox-label">
              <input type="checkbox" name="days[]" value="<?= $d ?>"> <?= $d ?>
            </label>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="day_of_week" id="dayOfWeekHidden">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Start Time *</label>
            <input class="form-control" type="time" name="start_time" required>
          </div>
          <div class="form-group">
            <label class="form-label">End Time *</label>
            <input class="form-control" type="time" name="end_time" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Semester Start *</label>
            <input class="form-control" type="date" name="semester_start" required>
          </div>
          <div class="form-group">
            <label class="form-label">Semester End *</label>
            <input class="form-control" type="date" name="semester_end" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Department *</label>
          <input class="form-control" type="text" name="department" required>
        </div>

        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('schedModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveSchedule()">
        <i class="fas fa-save"></i> Assign Schedule
      </button>
    </div>
  </div>
</div>

<!-- ── Attendance Detail Modal ──────────────────────────────────── -->
<div class="modal-overlay" id="attModal">
  <div class="modal" style="max-width:700px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-user-clock"></i> Attendance Log</h3>
      <button class="modal-close" onclick="closeModal('attModal')">&times;</button>
    </div>
    <div class="modal-body" id="attModalBody">Loading…</div>
  </div>
</div>
<?php endif; ?>

<script src="<?= getBasePath() ?>assets/comlab-app.js"></script>
<script>
const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const API_BASE  = '<?= getBasePath() ?>api/scheduling.php';
const ATT_BASE  = '<?= getBasePath() ?>api/attendance.php';
const CSRF      = '<?= htmlspecialchars($csrfToken) ?>';
let allSchedules = [];

// ── Load schedules ───────────────────────────────────────────────
async function loadSchedules() {
  const res  = await fetch(API_BASE + '?action=list');
  const data = await res.json();
  if (!data.success) return;
  allSchedules = data.schedules || [];

  if (IS_ADMIN) {
    document.getElementById('statTotal').textContent  = allSchedules.length;
    document.getElementById('statActive').textContent = allSchedules.filter(s=>s.is_active==1).length;

    const today = new Date().toLocaleDateString('en-US',{weekday:'long'});
    document.getElementById('statToday').textContent =
      allSchedules.filter(s=>s.is_active==1 && s.day_of_week.includes(today)).length;

    const facIds = new Set(allSchedules.filter(s=>s.is_active==1).map(s=>s.faculty_id));
    document.getElementById('statFaculty').textContent = facIds.size;
  } else {
    // Faculty: show today banner
    const todayRes  = await fetch(API_BASE + '?action=today');
    const todayData = await todayRes.json();
    const banner    = document.getElementById('todayBanner');
    if (todayData.schedules && todayData.schedules.length) {
      const s = todayData.schedules[0];
      const checkin = s.can_checkin
        ? `<a href="attendance.php" class="btn btn-sm btn-primary" style="margin-left:12px">
             <i class="fas fa-sign-in-alt"></i> Check In Now
           </a>`
        : (s.attendance_status === 'Present'
          ? '<span class="badge badge-success">✓ Checked In</span>'
          : '');
      banner.innerHTML = `<i class="fas fa-bell"></i>
        <strong>Today:</strong> ${s.class_name} — ${s.lab_name}
        at ${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)} ${checkin}`;
      banner.style.display = 'block';
    }
  }

  renderTable(allSchedules);
}

function renderTable(rows) {
  const tbody = document.getElementById('schedTableBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No schedules found.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(s => {
    const statusBadge = s.is_active==1
      ? '<span class="badge badge-success">Active</span>'
      : '<span class="badge badge-danger">Cancelled</span>';
    const attRate = s.total_sessions > 0
      ? Math.round(100 * s.present_count / s.total_sessions) + '%'
      : '—';
    return `<tr>
      ${IS_ADMIN ? `<td>${esc(s.faculty_name)}<br><small class="text-muted">${esc(s.faculty_dept||'')}</small></td>` : ''}
      <td><strong>${esc(s.class_name)}</strong></td>
      <td>${esc(s.lab_name)}<br><small class="text-muted">${esc(s.lab_code)}</small></td>
      <td>${esc(s.day_of_week)}</td>
      <td>${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}</td>
      <td><small>${s.semester_start}<br>→ ${s.semester_end}</small></td>
      <td>${esc(s.department)}</td>
      <td>${statusBadge}</td>
      ${IS_ADMIN ? `
      <td>${attRate} <small>(${s.present_count||0}/${s.total_sessions||0})</small></td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick="viewAttendance(${s.schedule_id})">
          <i class="fas fa-eye"></i>
        </button>
        ${s.is_active==1 ? `
        <button class="btn btn-ghost btn-sm text-danger" onclick="cancelSchedule(${s.schedule_id},'${esc(s.class_name)}')">
          <i class="fas fa-times"></i>
        </button>` : ''}
      </td>` : ''}
    </tr>`;
  }).join('');
}

function filterTable() {
  const q      = document.getElementById('searchInput')?.value.toLowerCase() || '';
  const status = document.getElementById('statusFilter')?.value;
  const rows   = allSchedules.filter(s => {
    const txt = `${s.class_name} ${s.faculty_name} ${s.lab_name} ${s.department}`.toLowerCase();
    const matchQ = !q || txt.includes(q);
    const matchS = !status || String(s.is_active) === status;
    return matchQ && matchS;
  });
  renderTable(rows);
}

// ── Save schedule ────────────────────────────────────────────────
async function saveSchedule() {
  // Collect checked days
  const checked = [...document.querySelectorAll('#dayCheckboxes input:checked')].map(c=>c.value);
  if (!checked.length) {
    showFormError('schedFormError', 'Please select at least one day.'); return;
  }
  document.getElementById('dayOfWeekHidden').value = checked.join(',');

  const form = document.getElementById('schedForm');
  const data = new FormData(form);
  const err  = document.getElementById('schedFormError');
  err.style.display = 'none';

  const res  = await fetch(API_BASE, { method:'POST', body: data });
  const json = await res.json();
  if (json.success) {
    closeModal('schedModal');
    form.reset();
    loadSchedules();
    showToast(json.message || 'Schedule assigned.', 'success');
  } else {
    err.textContent   = json.message;
    err.style.display = 'block';
  }
}

// ── Cancel schedule ──────────────────────────────────────────────
async function cancelSchedule(id, name) {
  if (!confirm(`Cancel schedule "${name}"?\nThis will deactivate the recurring assignment.`)) return;
  const fd = new FormData();
  fd.append('action','cancel'); fd.append('schedule_id',id); fd.append('csrf_token',CSRF);
  const res  = await fetch(API_BASE, {method:'POST',body:fd});
  const json = await res.json();
  if (json.success) { loadSchedules(); showToast('Schedule cancelled.','success'); }
  else showToast(json.message,'danger');
}

// ── View attendance ──────────────────────────────────────────────
async function viewAttendance(schedId) {
  openModal('attModal');
  const body = document.getElementById('attModalBody');
  body.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i></div>';

  const res  = await fetch(`${API_BASE}?action=attendance&schedule_id=${schedId}`);
  const data = await res.json();
  if (!data.success) { body.innerHTML = 'Failed to load.'; return; }

  const s = data.summary || {};
  const rate = s.total > 0 ? Math.round(100*s.present/s.total) : 0;
  let html = `
    <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
      <div class="stat-card"><div class="stat-number">${s.total||0}</div><div class="stat-label">Sessions</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#4caf50">${s.present||0}</div><div class="stat-label">Present</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#f44336">${s.absent||0}</div><div class="stat-label">Absent</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#ff9800">${rate}%</div><div class="stat-label">Rate</div></div>
    </div>
    <div class="table-responsive"><table class="data-table">
      <thead><tr><th>Date</th><th>Status</th><th>Checked In</th><th>Auto?</th></tr></thead>
      <tbody>`;

  (data.attendance || []).forEach(r => {
    const badge = r.status==='Present'
      ? `<span class="badge badge-success">Present</span>`
      : r.status==='Excused'
      ? `<span class="badge badge-warning">Excused</span>`
      : `<span class="badge badge-danger">Absent</span>`;
    html += `<tr>
      <td>${r.attendance_date}</td>
      <td>${badge}</td>
      <td>${r.checked_in_at ? r.checked_in_at.slice(11,16) : '—'}</td>
      <td>${r.marked_by_system ? '<span class="badge badge-secondary">Auto</span>' : '—'}</td>
    </tr>`;
  });

  html += '</tbody></table></div>';
  body.innerHTML = html;
}

// ── Load faculty + lab dropdowns ─────────────────────────────────
async function loadDropdowns() {
  if (!IS_ADMIN) return;
  const [fu, lu] = await Promise.all([
    fetch('<?= getBasePath() ?>api/users.php').then(r=>r.json()),
    fetch('<?= getBasePath() ?>api/locations.php').then(r=>r.json()),
  ]);

  const fSel = document.getElementById('facultySelect');
  fSel.innerHTML = '<option value="">Select faculty…</option>';
  (fu.users||[]).filter(u=>u.role==='Faculty' && u.is_active==1).forEach(u => {
    fSel.innerHTML += `<option value="${u.user_id}">${esc(u.first_name+' '+u.last_name)} (${esc(u.department||'')})</option>`;
  });

  const lSel = document.getElementById('labSelect');
  lSel.innerHTML = '<option value="">Select lab…</option>';
  (lu.locations||[]).filter(l=>l.is_active==1).forEach(l => {
    lSel.innerHTML += `<option value="${l.location_id}">${esc(l.lab_name)} (${esc(l.lab_code)})</option>`;
  });
}

function exportCSV() { window.location = API_BASE + '?export=1'; }
function esc(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showFormError(id, msg) { const el = document.getElementById(id); el.textContent = msg; el.style.display = 'block'; }
function showToast(msg, type) { /* implemented in comlab-app.js */ if(window.showNotification) showNotification(msg, type); else alert(msg); }

loadSchedules();
loadDropdowns();
</script>
</body>
</html>
