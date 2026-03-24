<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$currentUser = requireAuth('scheduling');
$role = $currentUser['role'];
$isAdmin = ($role === ROLE_ADMIN);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HR Faculty Schedules - COMLAB</title>
  <link rel="stylesheet" href="<?= getBasePath() ?>assets/comlab.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
</head>
<body>
<?php renderSidebar($currentUser, 'scheduling'); ?>

<main class="main" id="main">
  <?php renderTopbar($currentUser, 'HR Faculty Schedules'); ?>

  <div class="page-content">
    <div class="page-header">
      <div>
        <h1 class="page-title"><?= $isAdmin ? 'HR Faculty Schedule Sync' : 'My HR Schedule' ?></h1>
        <p class="page-subtitle">
          <?= $isAdmin
            ? 'Monitor and sync recurring laboratory schedules delivered by HR.'
            : 'View your assigned laboratory schedule sourced from the HR feed.' ?>
        </p>
      </div>
      <?php if ($isAdmin): ?>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <button class="btn btn-ghost" onclick="requestHrEmployee()">
          <i class="fas fa-user-plus"></i> Request Employee from HR
        </button>
        <button class="btn btn-primary" onclick="syncHrSchedules()">
          <i class="fas fa-arrows-rotate"></i> Sync from HR
        </button>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <div class="alert alert-info" id="hrFeedBanner">
      <i class="fas fa-building-user"></i>
      Checking latest HR schedule package...
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="stats-grid" id="statsGrid">
      <div class="stat-card"><div class="stat-number" id="statTotal">-</div><div class="stat-label">Total Schedules</div></div>
      <div class="stat-card"><div class="stat-number" id="statActive">-</div><div class="stat-label">Active</div></div>
      <div class="stat-card"><div class="stat-number" id="statToday">-</div><div class="stat-label">Today's Classes</div></div>
      <div class="stat-card"><div class="stat-number" id="statFaculty">-</div><div class="stat-label">Faculty Assigned</div></div>
    </div>
    <?php else: ?>
    <div class="alert alert-info" id="todayBanner" style="display:none"></div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="table-controls">
      <input class="form-control" type="text" id="searchInput"
             placeholder="Search class, faculty, lab..." oninput="filterTable()">
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

    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="data-table" id="schedTable">
            <thead>
              <tr>
                <?php if ($isAdmin): ?><th>Faculty</th><?php endif; ?>
                <th>Class</th>
                <th>Lab</th>
                <th>Days</th>
                <th>Time</th>
                <th>Semester</th>
                <th>Dept</th>
                <th>Status</th>
                <?php if ($isAdmin): ?>
                <th>Source</th>
                <th>Attendance</th>
                <th>Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="schedTableBody">
              <tr><td colspan="<?= $isAdmin ? '11' : '7' ?>" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php if ($isAdmin): ?>
<div class="modal-overlay" id="attModal">
  <div class="modal" style="max-width:700px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-user-clock"></i> Attendance Log</h3>
      <button class="modal-close" onclick="closeModal('attModal')">&times;</button>
    </div>
    <div class="modal-body" id="attModalBody">Loading...</div>
  </div>
</div>

<div class="modal-overlay" id="hrEmployeeReqModal">
  <div class="modal hr-staff-req-modal" style="max-width:680px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-user-plus"></i> Request Additional Lab / IT Staff from HR</h3>
      <button class="modal-close" onclick="closeModal('hrEmployeeReqModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-danger" id="hrReqErr" style="display:none"></div>
      <div class="hr-staff-req-grid-2">
        <div class="form-group">
          <label class="form-label" for="hrReqRole">Requested role</label>
          <select class="form-control" id="hrReqRole" aria-label="Requested role for HR" required>
            <option value="it_staff">IT Staff</option>
            <option value="lab_technician">Lab Technician</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="hrReqQty">Requested count</label>
          <input class="form-control" id="hrReqQty" type="number" min="1" value="1" required>
        </div>
      </div>
      <div class="hr-staff-req-grid-by">
        <div class="form-group">
          <label class="form-label" for="hrReqBy">Requested by</label>
          <input class="form-control" id="hrReqBy" type="text" value="COMLAB Admin" autocomplete="name">
        </div>
        <div class="form-group">
          <label class="form-label" for="hrReqNotes">Request notes</label>
          <textarea class="form-control" id="hrReqNotes" rows="3" placeholder="Request notes" required></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer hr-staff-req-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('hrEmployeeReqModal')">Cancel</button>
      <button type="button" class="btn btn-link-primary" onclick="submitHrEmployeeRequest()">Send Request</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="<?= getBasePath() ?>assets/comlab-app.js"></script>
<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const API_BASE = '<?= getBasePath() ?>api/scheduling.php';
const CSRF = '<?= htmlspecialchars($csrfToken) ?>';
/** PostgREST pool key role_type (see comlab_resolve_hr_staff_role_type_from_requested_text) */
const HR_COMLAB_DEFAULT_ROLE = 'it_staff';
let allSchedules = [];

async function loadSchedules() {
  const res = await fetch(API_BASE + '?action=list');
  const data = await res.json();
  if (!data.success) return;

  allSchedules = data.schedules || [];

  if (IS_ADMIN) {
    document.getElementById('statTotal').textContent = allSchedules.length;
    document.getElementById('statActive').textContent = allSchedules.filter(s => s.is_active == 1).length;
    const today = new Date().toLocaleDateString('en-US', { weekday: 'long' });
    document.getElementById('statToday').textContent = allSchedules.filter(s => s.is_active == 1 && String(s.day_of_week || '').includes(today)).length;
    document.getElementById('statFaculty').textContent = new Set(allSchedules.filter(s => s.is_active == 1).map(s => s.faculty_id)).size;
  } else {
    const todayRes = await fetch(API_BASE + '?action=today');
    const todayData = await todayRes.json();
    const banner = document.getElementById('todayBanner');
    if (todayData.schedules && todayData.schedules.length) {
      const s = todayData.schedules[0];
      const checkin = s.can_checkin
        ? `<a href="attendance.php" class="btn btn-sm btn-primary" style="margin-left:12px"><i class="fas fa-sign-in-alt"></i> Check In Now</a>`
        : (s.attendance_status === 'Present' ? '<span class="badge badge-success">Checked In</span>' : '');
      banner.innerHTML = `<i class="fas fa-bell"></i> <strong>Today:</strong> ${esc(s.class_name)} - ${esc(s.lab_name)} at ${String(s.start_time).slice(0,5)}-${String(s.end_time).slice(0,5)} ${checkin}`;
      banner.style.display = 'block';
    }
  }

  renderTable(allSchedules);
}

async function loadHrFeedStatus() {
  if (!IS_ADMIN) return;
  const banner = document.getElementById('hrFeedBanner');
  try {
    const res = await fetch(API_BASE + '?action=hr_status');
    const data = await res.json();
    const feed = data.hr_feed || null;
    const req = data.last_employee_request || data.last_request || null;
    const reqLabel = req
      ? `Last employee request: ${esc(req.source_reference || req.document_id)} at ${esc(formatSimpleDate(req.sent_at || req.created_at))}.`
      : 'No employee request sent yet.';
    if (!feed) {
      banner.className = 'alert alert-info';
      banner.innerHTML = `<i class="fas fa-info-circle"></i> No HR schedule package found yet. ${reqLabel}`;
      return;
    }
    const total = Array.isArray(feed.payload?.schedules) ? feed.payload.schedules.length : 0;
    banner.className = 'alert alert-info';
    banner.innerHTML = `<i class="fas fa-building-user"></i><strong>HR feed ready:</strong> ${total} schedule item(s) from ${esc(feed.document_id)}. Last package ${esc(formatSimpleDate(feed.sent_at || feed.created_at))}. ${reqLabel}`;
  } catch (error) {
    banner.className = 'alert alert-danger';
    banner.innerHTML = '<i class="fas fa-circle-exclamation"></i>Unable to read the HR schedule feed status.';
  }
}

function renderTable(rows) {
  const tbody = document.getElementById('schedTableBody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${IS_ADMIN ? '11' : '7'}" class="text-center text-muted">No schedules found.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map((s) => {
    const statusBadge = s.is_active == 1
      ? '<span class="badge badge-success">Active</span>'
      : '<span class="badge badge-danger">Cancelled</span>';
    const attRate = s.total_sessions > 0 ? Math.round(100 * s.present_count / s.total_sessions) + '%' : '-';
    const sourceBadge = Number(s.synced_from_hr || 0) === 1
      ? '<span class="badge badge-info">HR Feed</span>'
      : '<span class="badge badge-secondary">Manual</span>';

    return `<tr>
      ${IS_ADMIN ? `<td>${esc(s.faculty_name)}<br><small class="text-muted">${esc(s.faculty_dept || '')}</small></td>` : ''}
      <td><strong>${esc(s.class_name)}</strong></td>
      <td>${esc(s.lab_name)}<br><small class="text-muted">${esc(s.lab_code)}</small></td>
      <td>${esc(s.day_of_week)}</td>
      <td>${String(s.start_time).slice(0,5)}-${String(s.end_time).slice(0,5)}</td>
      <td><small>${esc(s.semester_start)}<br>to ${esc(s.semester_end)}</small></td>
      <td>${esc(s.department)}</td>
      <td>${statusBadge}</td>
      ${IS_ADMIN ? `<td>${sourceBadge}</td>` : ''}
      ${IS_ADMIN ? `<td>${attRate} <small>(${s.present_count || 0}/${s.total_sessions || 0})</small></td>` : ''}
      ${IS_ADMIN ? `<td>
        <button class="btn btn-ghost btn-sm" onclick="viewAttendance(${s.schedule_id})"><i class="fas fa-eye"></i></button>
        ${s.is_active == 1 ? `<button class="btn btn-ghost btn-sm text-danger" onclick="cancelSchedule(${s.schedule_id},'${esc(s.class_name)}')"><i class="fas fa-times"></i></button>` : ''}
      </td>` : ''}
    </tr>`;
  }).join('');
}

function filterTable() {
  const q = document.getElementById('searchInput')?.value.toLowerCase() || '';
  const status = document.getElementById('statusFilter')?.value;
  const rows = allSchedules.filter((s) => {
    const txt = `${s.class_name} ${s.faculty_name} ${s.lab_name} ${s.department}`.toLowerCase();
    return (!q || txt.includes(q)) && (!status || String(s.is_active) === status);
  });
  renderTable(rows);
}

async function syncHrSchedules() {
  if (!confirm('Sync the latest HR faculty schedule package into COMLAB now?')) return;
  const fd = new FormData();
  fd.append('action', 'sync_hr');
  fd.append('csrf_token', CSRF);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const json = await res.json();
  if (json.success) {
    showToast(json.message || 'HR schedule sync completed.', 'success');
    await loadSchedules();
    await loadHrFeedStatus();
  } else {
    showToast(json.message || 'Unable to sync HR schedules.', 'danger');
  }
}

async function requestHrEmployee() {
  const err = document.getElementById('hrReqErr');
  if (err) {
    err.style.display = 'none';
    err.textContent = '';
  }
  const notesInput = document.getElementById('hrReqNotes');
  const roleInput = document.getElementById('hrReqRole');
  const qtyInput = document.getElementById('hrReqQty');
  const byInput = document.getElementById('hrReqBy');
  if (notesInput) notesInput.value = '';
  if (roleInput) roleInput.value = HR_COMLAB_DEFAULT_ROLE;
  if (qtyInput) qtyInput.value = '1';
  if (byInput && !byInput.value.trim()) byInput.value = 'COMLAB Admin';
  openModal('hrEmployeeReqModal');
}

async function submitHrEmployeeRequest() {
  const notes = (document.getElementById('hrReqNotes')?.value || '').trim();
  const roleCode = (document.getElementById('hrReqRole')?.value || '').trim();
  const quantity = Math.max(1, parseInt(document.getElementById('hrReqQty')?.value || '1', 10) || 1);
  const requestedBy = (document.getElementById('hrReqBy')?.value || '').trim() || 'COMLAB Admin';

  const err = document.getElementById('hrReqErr');
  if (!notes) {
    if (err) {
      err.textContent = 'Request notes are required before requesting staff from HR.';
      err.style.display = 'flex';
    } else {
      showToast('Request notes are required before requesting staff from HR.', 'warning');
    }
    return;
  }

  const fd = new FormData();
  fd.append('action', 'request_employee');
  fd.append('request_notes', notes);
  fd.append('requested_role', roleCode);
  fd.append('quantity', String(quantity));
  fd.append('requested_by', requestedBy);
  fd.append('csrf_token', CSRF);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const json = await res.json();
  if (json.success) {
    showToast(json.message || 'Employee request sent to HR.', 'success');
    closeModal('hrEmployeeReqModal');
    await loadHrFeedStatus();
  } else {
    if (err) {
      err.textContent = json.message || 'Unable to request employee from HR.';
      err.style.display = 'flex';
    } else {
      showToast(json.message || 'Unable to request employee from HR.', 'danger');
    }
  }
}

async function cancelSchedule(id, name) {
  if (!confirm(`Cancel schedule "${name}"?\nThis will deactivate the recurring assignment.`)) return;
  const fd = new FormData();
  fd.append('action', 'cancel');
  fd.append('schedule_id', id);
  fd.append('csrf_token', CSRF);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const json = await res.json();
  if (json.success) {
    loadSchedules();
    showToast('Schedule cancelled.', 'success');
  } else {
    showToast(json.message, 'danger');
  }
}

async function viewAttendance(schedId) {
  openModal('attModal');
  const body = document.getElementById('attModalBody');
  body.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i></div>';
  const res = await fetch(`${API_BASE}?action=attendance&schedule_id=${schedId}`);
  const data = await res.json();
  if (!data.success) {
    body.innerHTML = 'Failed to load.';
    return;
  }

  const s = data.summary || {};
  const rate = s.total > 0 ? Math.round(100 * s.present / s.total) : 0;
  let html = `
    <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
      <div class="stat-card"><div class="stat-number">${s.total || 0}</div><div class="stat-label">Sessions</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#4caf50">${s.present || 0}</div><div class="stat-label">Present</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#f44336">${s.absent || 0}</div><div class="stat-label">Absent</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#ff9800">${rate}%</div><div class="stat-label">Rate</div></div>
    </div>
    <div class="table-responsive"><table class="data-table">
      <thead><tr><th>Date</th><th>Status</th><th>Checked In</th><th>Auto?</th></tr></thead>
      <tbody>`;

  (data.attendance || []).forEach((r) => {
    const badge = r.status === 'Present'
      ? `<span class="badge badge-success">Present</span>`
      : r.status === 'Excused'
        ? `<span class="badge badge-warning">Excused</span>`
        : `<span class="badge badge-danger">Absent</span>`;

    html += `<tr>
      <td>${esc(r.attendance_date)}</td>
      <td>${badge}</td>
      <td>${r.checked_in_at ? String(r.checked_in_at).slice(11,16) : '-'}</td>
      <td>${r.marked_by_system ? '<span class="badge badge-secondary">Auto</span>' : '-'}</td>
    </tr>`;
  });

  html += '</tbody></table></div>';
  body.innerHTML = html;
}

function exportCSV() {
  window.location = API_BASE + '?export=1';
}

function esc(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function showToast(msg, type) {
  if (window.showNotification) window.showNotification(msg, type);
  else alert(msg);
}

function formatSimpleDate(value) {
  if (!value) return '-';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

function openHrRequestFromHash() {
  if (!IS_ADMIN) return;
  const h = (location.hash || '').replace(/^#/, '');
  if (h === 'request-employee-hr' || h === 'request-hr-employee') {
    requestHrEmployee();
    history.replaceState(null, '', location.pathname + location.search);
  }
}

window.addEventListener('hashchange', openHrRequestFromHash);

loadSchedules();
loadHrFeedStatus();
openHrRequestFromHash();
</script>
</body>
</html>
