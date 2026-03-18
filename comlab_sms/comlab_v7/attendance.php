<?php
// ============================================
// COMLAB - Attendance Page (NEW)
// Faculty: check in + view own history.
// Admin: faculty presence summary, daily view,
//        mark excused, run absence sweep.
// ============================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$currentUser = requireAuth('attendance');
$role        = $currentUser['role'];
$isAdmin     = ($role === ROLE_ADMIN);
$csrfToken   = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance — COMLAB</title>
  <link rel="stylesheet" href="<?= getBasePath() ?>assets/comlab.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
</head>
<body>
<?php renderSidebar($currentUser, 'attendance'); ?>

<main class="main" id="main">
  <?php renderTopbar($currentUser, 'Attendance'); ?>

  <div class="page-content">

    <!-- ── Faculty: Check-In Panel ───────────────────────────────── -->
    <?php if (!$isAdmin): ?>
    <div class="page-header">
      <div>
        <h1 class="page-title">My Attendance</h1>
        <p class="page-subtitle">Check in for today's class and view your attendance history.</p>
      </div>
    </div>

    <!-- Today's schedules -->
    <div id="todaySection">
      <h2 class="section-title">Today's Classes</h2>
      <div id="todayCards" class="card-grid">
        <div class="text-center" style="padding:24px"><i class="fas fa-spinner fa-spin"></i></div>
      </div>
    </div>

    <!-- Attendance history -->
    <div class="card" style="margin-top:24px">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> Attendance History</h3>
      </div>
      <div class="card-body">
        <div id="myStats" class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px"></div>
        <div class="table-responsive">
          <table class="data-table" id="histTable">
            <thead>
              <tr>
                <th>Date</th><th>Class</th><th>Lab</th>
                <th>Time</th><th>Status</th><th>Checked In</th>
              </tr>
            </thead>
            <tbody id="histBody">
              <tr><td colspan="6" class="text-center"><i class="fas fa-spinner fa-spin"></i></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php else: /* Admin view */ ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">Faculty Presence Summary</h1>
        <p class="page-subtitle">Monitor faculty attendance across all lab schedules.</p>
      </div>
      <div style="display:flex;gap:8px">
        <input class="form-control" type="date" id="dailyDatePicker"
               value="<?= date('Y-m-d') ?>" onchange="loadDaily()">
        <button class="btn btn-ghost" onclick="runAbsenceSweep()">
          <i class="fas fa-magic"></i> Mark Absences
        </button>
      </div>
    </div>

    <!-- Tab: Summary / Daily -->
    <div class="tab-bar" style="margin-bottom:16px">
      <button class="tab-btn active" id="tabSummaryBtn" onclick="switchTab('summary')">Faculty Summary</button>
      <button class="tab-btn"        id="tabDailyBtn"   onclick="switchTab('daily')">Daily View</button>
    </div>

    <!-- Faculty Summary table -->
    <div id="tabSummary">
      <div class="card">
        <div class="card-body">
          <div class="table-responsive">
            <table class="data-table" id="summaryTable">
              <thead>
                <tr>
                  <th>Faculty</th><th>Dept</th><th>Active Schedules</th>
                  <th>Sessions</th><th>Present</th><th>Absent</th>
                  <th>Rate</th><th>Details</th>
                </tr>
              </thead>
              <tbody id="summaryBody">
                <tr><td colspan="8" class="text-center"><i class="fas fa-spinner fa-spin"></i></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Daily view -->
    <div id="tabDaily" style="display:none">
      <div class="card">
        <div class="card-body">
          <div class="table-responsive">
            <table class="data-table" id="dailyTable">
              <thead>
                <tr>
                  <th>Faculty</th><th>Class</th><th>Lab</th>
                  <th>Time</th><th>Status</th><th>Check-In</th><th>Auto?</th><th>Actions</th>
                </tr>
              </thead>
              <tbody id="dailyBody">
                <tr><td colspan="8" class="text-center">Select a date above.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Excuse Modal -->
    <div class="modal-overlay" id="excuseModal">
      <div class="modal" style="max-width:420px">
        <div class="modal-header">
          <h3 class="modal-title">Mark as Excused</h3>
          <button class="modal-close" onclick="closeModal('excuseModal')">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="excuseAttId">
          <div class="form-group">
            <label class="form-label">Reason for Excusal</label>
            <textarea class="form-control" id="excuseReason" rows="3"
                      placeholder="e.g. Official travel, medical leave…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" onclick="closeModal('excuseModal')">Cancel</button>
          <button class="btn btn-warning" onclick="submitExcuse()">Mark Excused</button>
        </div>
      </div>
    </div>

    <?php endif; ?>

  </div><!-- /page-content -->
</main>

<script src="<?= getBasePath() ?>assets/comlab-app.js"></script>
<script>
const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const ATT_API   = '<?= getBasePath() ?>api/attendance.php';
const SCHED_API = '<?= getBasePath() ?>api/scheduling.php';
const CSRF      = '<?= htmlspecialchars($csrfToken) ?>';

// ── Faculty: load today's classes ────────────────────────────────
async function loadToday() {
  const res  = await fetch(SCHED_API + '?action=today');
  const data = await res.json();
  const box  = document.getElementById('todayCards');

  if (!data.schedules || !data.schedules.length) {
    box.innerHTML = '<p class="text-muted">No classes scheduled for today.</p>';
    return;
  }

  box.innerHTML = data.schedules.map(s => {
    let action = '';
    if (s.can_checkin) {
      action = `<button class="btn btn-primary btn-sm" onclick="checkIn(${s.schedule_id})">
                  <i class="fas fa-sign-in-alt"></i> Check In
                </button>`;
    } else if (s.attendance_status === 'Present') {
      action = `<span class="badge badge-success" style="padding:8px 16px">
                  <i class="fas fa-check"></i> Checked In at ${s.checked_in_at ? s.checked_in_at.slice(11,16) : ''}
                </span>`;
    } else if (s.attendance_status === 'Absent') {
      action = `<span class="badge badge-danger" style="padding:8px 16px">Absent</span>`;
    } else {
      action = `<small class="text-muted">Window: ${s.checkin_window_open.slice(0,5)}–${s.checkin_window_close.slice(0,5)}</small>`;
    }

    return `<div class="card" style="border-left:4px solid var(--primary)">
      <div class="card-body">
        <h4>${esc(s.class_name)}</h4>
        <p><i class="fas fa-map-marker-alt"></i> ${esc(s.lab_name)} (${esc(s.lab_code)})</p>
        <p><i class="fas fa-clock"></i> ${s.start_time.slice(0,5)} – ${s.end_time.slice(0,5)}</p>
        <div style="margin-top:12px">${action}</div>
      </div>
    </div>`;
  }).join('');
}

async function checkIn(schedId) {
  const fd = new FormData();
  fd.append('action','checkin'); fd.append('schedule_id',schedId); fd.append('csrf_token',CSRF);
  const res  = await fetch(SCHED_API, {method:'POST',body:fd});
  const json = await res.json();
  if (json.success) { loadToday(); loadHistory(); showToast(json.message,'success'); }
  else showToast(json.message,'danger');
}

async function loadHistory() {
  const res  = await fetch(ATT_API + '?action=my_history');
  const data = await res.json();
  const s    = data.summary || {};
  const rate = s.total > 0 ? Math.round(100*s.present/s.total) : 0;

  document.getElementById('myStats').innerHTML = `
    <div class="stat-card"><div class="stat-number">${s.total||0}</div><div class="stat-label">Sessions</div></div>
    <div class="stat-card"><div class="stat-number" style="color:#4caf50">${s.present||0}</div><div class="stat-label">Present</div></div>
    <div class="stat-card"><div class="stat-number" style="color:#f44336">${s.absent||0}</div><div class="stat-label">Absent</div></div>
    <div class="stat-card"><div class="stat-number" style="color:var(--primary)">${rate}%</div><div class="stat-label">Rate</div></div>`;

  const tbody = document.getElementById('histBody');
  const rows  = data.records || [];
  if (!rows.length) { tbody.innerHTML='<tr><td colspan="6" class="text-center">No records yet.</td></tr>'; return; }
  tbody.innerHTML = rows.map(r => {
    const badge = r.status==='Present' ? 'badge-success' : r.status==='Excused' ? 'badge-warning' : 'badge-danger';
    return `<tr>
      <td>${r.attendance_date}</td>
      <td>${esc(r.class_name)}</td>
      <td>${esc(r.lab_name)}</td>
      <td>${r.start_time.slice(0,5)}–${r.end_time.slice(0,5)}</td>
      <td><span class="badge ${badge}">${r.status}</span></td>
      <td>${r.checked_in_at ? r.checked_in_at.slice(11,16) : '—'}</td>
    </tr>`;
  }).join('');
}

// ── Admin: summary ───────────────────────────────────────────────
async function loadSummary() {
  const res  = await fetch(ATT_API + '?action=summary');
  const data = await res.json();
  const tbody = document.getElementById('summaryBody');
  const rows  = data.summary || [];
  if (!rows.length) { tbody.innerHTML='<tr><td colspan="8" class="text-center">No faculty found.</td></tr>'; return; }
  tbody.innerHTML = rows.map(r => {
    const pct  = parseFloat(r.attendance_rate) || 0;
    const color= pct>=80?'#4caf50':pct>=60?'#ff9800':'#f44336';
    return `<tr>
      <td><strong>${esc(r.faculty_name)}</strong></td>
      <td>${esc(r.department||'')}</td>
      <td>${r.active_schedules}</td>
      <td>${r.total_sessions}</td>
      <td style="color:#4caf50">${r.present||0}</td>
      <td style="color:#f44336">${r.absent||0}</td>
      <td><strong style="color:${color}">${pct}%</strong></td>
      <td>
        <a href="scheduling.php" class="btn btn-ghost btn-sm">
          <i class="fas fa-calendar"></i> Schedules
        </a>
      </td>
    </tr>`;
  }).join('');
}

async function loadDaily() {
  const date  = document.getElementById('dailyDatePicker').value;
  const res   = await fetch(`${ATT_API}?action=daily&date=${date}`);
  const data  = await res.json();
  const tbody = document.getElementById('dailyBody');
  const rows  = data.records || [];
  if (!rows.length) { tbody.innerHTML=`<tr><td colspan="8" class="text-center">No records for ${date}.</td></tr>`; return; }
  tbody.innerHTML = rows.map(r => {
    const badge = r.status==='Present'?'badge-success':r.status==='Excused'?'badge-warning':'badge-danger';
    const excuseBtn = (r.status !== 'Present')
      ? `<button class="btn btn-ghost btn-sm" onclick="openExcuse(${r.attendance_id})">Excuse</button>` : '';
    return `<tr>
      <td>${esc(r.faculty_name)}</td>
      <td>${esc(r.class_name)}</td>
      <td>${esc(r.lab_name)}</td>
      <td>${r.start_time.slice(0,5)}–${r.end_time.slice(0,5)}</td>
      <td><span class="badge ${badge}">${r.status}</span></td>
      <td>${r.checked_in_at ? r.checked_in_at.slice(11,16) : '—'}</td>
      <td>${r.marked_by_system ? '<span class="badge badge-secondary">Auto</span>' : '—'}</td>
      <td>${excuseBtn}</td>
    </tr>`;
  }).join('');
}

function switchTab(tab) {
  document.getElementById('tabSummary').style.display = tab==='summary' ? '' : 'none';
  document.getElementById('tabDaily').style.display   = tab==='daily'   ? '' : 'none';
  document.getElementById('tabSummaryBtn').classList.toggle('active', tab==='summary');
  document.getElementById('tabDailyBtn').classList.toggle('active',   tab==='daily');
  if (tab==='daily') loadDaily();
}

function openExcuse(attId) {
  document.getElementById('excuseAttId').value = attId;
  document.getElementById('excuseReason').value = '';
  openModal('excuseModal');
}

async function submitExcuse() {
  const attId  = document.getElementById('excuseAttId').value;
  const reason = document.getElementById('excuseReason').value.trim();
  if (!reason) { showToast('Please enter a reason.','warning'); return; }
  const fd = new FormData();
  fd.append('action','mark_excused'); fd.append('attendance_id',attId);
  fd.append('reason',reason); fd.append('csrf_token',CSRF);
  const res  = await fetch(ATT_API, {method:'POST',body:fd});
  const json = await res.json();
  closeModal('excuseModal');
  if (json.success) { loadDaily(); showToast('Marked as Excused.','success'); }
  else showToast(json.message,'danger');
}

async function runAbsenceSweep() {
  if (!confirm('Mark all overdue sessions today as Absent?')) return;
  const fd = new FormData();
  fd.append('action','run_absence_sweep'); fd.append('csrf_token',CSRF);
  const res  = await fetch(ATT_API, {method:'POST',body:fd});
  const json = await res.json();
  showToast(json.message, json.success?'success':'danger');
  if (json.success && json.count > 0) loadDaily();
}

function esc(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showToast(msg, type) { if(window.showNotification) showNotification(msg,type); else alert(msg); }

// Init
if (IS_ADMIN) { loadSummary(); }
else          { loadToday(); loadHistory(); }
</script>
</body>
</html>
