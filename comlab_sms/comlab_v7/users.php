<?php
// COMLAB - User Management Page (NEW)
require_once __DIR__.'/config/db.php';
require_once __DIR__.'/config/auth.php';
require_once __DIR__.'/includes/require_auth.php';
require_once __DIR__.'/includes/sidebar.php';
require_once __DIR__.'/includes/topbar.php';

$currentUser = requireAuth('users');
if ($currentUser['role'] !== ROLE_ADMIN) { http_response_code(403); exit; }
$csrf = generateCsrfToken();
$base = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Users — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($currentUser,'users'); ?>
<main class="main" id="main">
<?php renderTopbar($currentUser,'User Management'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">User Management</h1>
      <p class="page-subtitle">Manage administrator and faculty accounts.</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn btn-ghost" onclick="exportCSV()"><i class="fas fa-download"></i> Export</button>
      </button>
      <button class="btn btn-navy" onclick="openUserModal()"><i class="fas fa-user-plus"></i> Add User</button>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon-wrap si-navy"><i class="fas fa-users"></i></div>
      <div><div class="stat-number" id="stTotal">—</div><div class="stat-label">Total Users</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon-wrap si-purple"><i class="fas fa-user-shield"></i></div>
      <div><div class="stat-number" id="stAdmin">—</div><div class="stat-label">Administrators</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon-wrap si-teal"><i class="fas fa-chalkboard-teacher"></i></div>
      <div><div class="stat-number" id="stFaculty">—</div><div class="stat-label">Faculty</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon-wrap si-green"><i class="fas fa-circle-check"></i></div>
      <div><div class="stat-number" id="stActive">—</div><div class="stat-label">Active</div></div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="table-controls">
    <div class="search-box">
      <i class="fas fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search name, email, department…" oninput="filterTable()">
    </div>
    <div class="filter-tabs">
      <button class="ftab active" onclick="setRole(this,'')">All</button>
      <button class="ftab" onclick="setRole(this,'Administrator')">Admins</button>
      <button class="ftab" onclick="setRole(this,'Faculty')">Faculty</button>
    </div>
    <select class="form-control" style="width:130px;height:36px;font-size:.8rem" id="statusFilter" onchange="filterTable()">
      <option value="">All Status</option>
      <option value="1">Active</option>
      <option value="0">Inactive</option>
    </select>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="data-table" id="userTable">
        <thead>
          <tr>
            <th>User</th>
            <th>Email</th>
            <th>Role</th>
            <th>Department</th>
            <th>Last Login</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="userBody">
          <tr><td colspan="7"><div class="empty-state" style="padding:2rem">
            <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.4;display:block;margin-bottom:.5rem"></i>
            <p>Loading users…</p>
          </div></td></tr>
        </tbody>
      </table>
    </div>
  </div>

</main>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="userModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="mTitle"><i class="fas fa-user-plus"></i> Add User</h3>
      <button class="modal-close" onclick="closeModal('userModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-danger" id="mErr" style="display:none"></div>
      <form id="userForm">
        <input type="hidden" name="csrf_token" value="<?= $H($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="user_id" id="editId">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">First Name *</label>
            <input class="form-control" type="text" name="first_name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Last Name *</label>
            <input class="form-control" type="text" name="last_name" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Username *</label>
            <input class="form-control" type="text" name="username" required autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">Role *</label>
            <select class="form-control" name="role">
              <option value="Faculty">Faculty</option>
              <option value="Administrator">Administrator</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input class="form-control" type="email" name="email" required autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Department</label>
          <input class="form-control" type="text" name="department" placeholder="e.g. College of Computer Studies">
        </div>
        <div class="form-group">
          <label class="form-label" id="pwLabel">Password *</label>
          <div style="position:relative">
            <input class="form-control" type="password" name="password" id="pwInput"
                   placeholder="Min. 8 characters" autocomplete="new-password"
                   style="padding-right:2.5rem">
            <button type="button" onclick="togglePw()"
                    style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:.9rem">
              <i class="fas fa-eye" id="pwEye"></i>
            </button>
          </div>
          <p class="form-hint" id="pwHint">Leave blank to keep current password.</p>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('userModal')">Cancel</button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveUser()">
        <i class="fas fa-floppy-disk"></i> Save User
      </button>
    </div>
  </div>
</div>

<!-- Schedule Summary Modal -->
<div class="modal-overlay" id="schedModal">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <h3 class="modal-title" id="sTitle"><i class="fas fa-calendar-alt"></i> Schedules</h3>
      <button class="modal-close" onclick="closeModal('schedModal')">&times;</button>
    </div>
    <div class="modal-body" id="sBody"></div>
    <div class="modal-footer">
      <a href="<?= $H($base) ?>scheduling.php" class="btn btn-navy">
        <i class="fas fa-calendar-plus"></i> Manage Schedules
      </a>
      <button class="btn btn-ghost" onclick="closeModal('schedModal')">Close</button>
    </div>
  </div>
</div>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API  = '<?= $H($base) ?>api/users.php';
const CSRF = '<?= $H($csrf) ?>';
let allUsers = [], roleFilter = '';

async function loadUsers() {
  const d = await (await fetch(API)).json();
  if (!d.success) return;
  allUsers = d.users || [];
  const c = d.counts || {};
  document.getElementById('stTotal').textContent   = c.total   || 0;
  document.getElementById('stAdmin').textContent   = c.administrator || 0;
  document.getElementById('stFaculty').textContent = c.faculty  || 0;
  document.getElementById('stActive').textContent  = c.active   || 0;
  renderTable(allUsers);
}

function renderTable(rows) {
  const tb = document.getElementById('userBody');
  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="7"><div class="empty-state" style="padding:2rem">
      <i class="fas fa-users"></i><p>No users found.</p></div></td></tr>`; return;
  }
  tb.innerHTML = rows.map(u => {
    const init  = (u.first_name[0]+u.last_name[0]).toUpperCase();
    const avCls = u.role==='Administrator' ? 'av-purple' : 'av-teal';
    const rBadge= u.role==='Administrator'
      ? `<span class="badge badge-admin">Admin</span>`
      : `<span class="badge badge-faculty">Faculty</span>`;
    const sBadge= u.is_active==1
      ? `<span class="badge badge-success"><span class="bdot bdot-green"></span>Active</span>`
      : `<span class="badge badge-secondary"><span class="bdot bdot-gray"></span>Inactive</span>`;
    return `<tr>
      <td><div class="u-cell">
        <div class="u-av ${avCls}">${init}</div>
        <div><div class="u-name">${esc(u.first_name+' '+u.last_name)}</div>
             <div class="u-sub mono" style="font-size:.7rem">${esc(u.username)}</div></div>
      </div></td>
      <td class="u-sub">${esc(u.email)}</td>
      <td>${rBadge}</td>
      <td class="u-sub">${esc(u.department||'—')}</td>
      <td class="u-sub">${u.last_login?u.last_login.slice(0,10):'Never'}</td>
      <td>${sBadge}</td>
      <td style="display:flex;gap:4px;align-items:center;padding:.7rem 1rem">
        ${u.role==='Faculty'?`<button class="act-btn" title="View schedules" onclick="viewSched(${u.user_id},'${esc(u.first_name+' '+u.last_name)}')"><i class="fas fa-calendar-alt"></i></button>`:''}
        <button class="act-btn" title="Edit" onclick="openUserModal(${u.user_id})"><i class="fas fa-pen"></i></button>
        <button class="act-btn" title="${u.is_active?'Deactivate':'Activate'}" onclick="toggleUser(${u.user_id},'${esc(u.first_name+' '+u.last_name)}',${u.is_active})">
          <i class="fas fa-${u.is_active?'ban':'rotate-left'}"></i></button>
        <button class="act-btn act-danger" title="Delete" onclick="deleteUser(${u.user_id},'${esc(u.first_name+' '+u.last_name)}')"><i class="fas fa-trash"></i></button>
      </td>
    </tr>`;
  }).join('');
}

function setRole(el, role) {
  document.querySelectorAll('.ftab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active'); roleFilter = role; filterTable();
}

function filterTable() {
  const q  = document.getElementById('searchInput').value.toLowerCase();
  const st = document.getElementById('statusFilter').value;
  renderTable(allUsers.filter(u => {
    const t = `${u.first_name} ${u.last_name} ${u.email} ${u.department||''} ${u.username}`.toLowerCase();
    return (!q||t.includes(q)) && (!roleFilter||u.role===roleFilter) && (!st||String(u.is_active)===st);
  }));
}

function openUserModal(userId) {
  document.getElementById('userForm').reset();
  document.getElementById('mErr').style.display = 'none';
  document.getElementById('editId').value = userId||'';
  if (userId) {
    const u = allUsers.find(x=>x.user_id==userId); if (!u) return;
    document.getElementById('mTitle').innerHTML  = '<i class="fas fa-pen"></i> Edit User';
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-floppy-disk"></i> Update User';
    document.getElementById('userForm').first_name.value = u.first_name;
    document.getElementById('userForm').last_name.value  = u.last_name;
    document.getElementById('userForm').username.value   = u.username;
    document.getElementById('userForm').email.value      = u.email;
    document.getElementById('userForm').role.value       = u.role;
    document.getElementById('userForm').department.value = u.department||'';
    document.getElementById('pwLabel').textContent = 'Password';
    document.getElementById('pwInput').required   = false;
    document.getElementById('pwHint').style.display = 'block';
  } else {
    document.getElementById('mTitle').innerHTML  = '<i class="fas fa-user-plus"></i> Add User';
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-floppy-disk"></i> Create User';
    document.getElementById('pwLabel').textContent = 'Password *';
    document.getElementById('pwInput').required    = true;
    document.getElementById('pwHint').style.display = 'none';
  }
  openModal('userModal');
}

async function saveUser() {
  const fd  = new FormData(document.getElementById('userForm'));
  const err = document.getElementById('mErr');
  err.style.display = 'none';
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { closeModal('userModal'); loadUsers(); toast(d.message,'success'); }
  else { err.textContent = d.message; err.style.display = 'flex'; }
}

async function toggleUser(id, name, active) {
  if (!confirm(`${active?'Deactivate':'Activate'} "${name}"?`)) return;
  const fd = new FormData();
  fd.append('action','toggle'); fd.append('user_id',id); fd.append('csrf_token',CSRF);
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { loadUsers(); toast(d.message,'success'); }
  else toast(d.message,'danger');
}

async function deleteUser(id, name) {
  if (!confirm(`Permanently delete "${name}"? This cannot be undone.`)) return;
  const fd = new FormData();
  fd.append('action','delete'); fd.append('user_id',id); fd.append('csrf_token',CSRF);
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { loadUsers(); toast(d.message,'success'); }
  else toast(d.message,'danger');
}

async function viewSched(id, name) {
  openModal('schedModal');
  document.getElementById('sTitle').innerHTML = `<i class="fas fa-calendar-alt"></i> ${esc(name)}`;
  const body = document.getElementById('sBody');
  body.innerHTML = '<div style="text-align:center;padding:1.5rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i></div>';
  const d = await (await fetch(`${API}?action=schedule_summary&user_id=${id}`)).json();
  const rows = d.schedules || [];
  if (!rows.length) { body.innerHTML = '<p style="color:var(--muted);text-align:center;padding:1.5rem">No schedules assigned.</p>'; return; }
  body.innerHTML = `<div class="table-responsive"><table class="data-table">
    <thead><tr><th>Class</th><th>Lab</th><th>Days</th><th>Time</th><th>Semester</th><th>Rate</th></tr></thead>
    <tbody>${rows.map(s=>{
      const rate = s.total_sessions>0?Math.round(100*s.present_count/s.total_sessions)+'%':'—';
      const dim  = !s.is_active?'style="opacity:.55"':'';
      return `<tr ${dim}>
        <td><div class="u-name">${esc(s.class_name)}${!s.is_active?' <span class="badge badge-secondary">Cancelled</span>':''}</div></td>
        <td><span class="code-tag">${esc(s.lab_code)}</span></td>
        <td class="u-sub" style="font-size:.75rem">${esc(s.day_of_week)}</td>
        <td class="u-sub">${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}</td>
        <td class="u-sub" style="font-size:.72rem">${s.semester_start}<br>${s.semester_end}</td>
        <td><strong>${rate}</strong> <span class="u-sub">(${s.present_count||0}/${s.total_sessions||0})</span></td>
      </tr>`;
    }).join('')}</tbody>
  </table></div>`;
}

function togglePw() {
  const inp = document.getElementById('pwInput');
  const ico = document.getElementById('pwEye');
  inp.type = inp.type==='password'?'text':'password';
  ico.className = inp.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}
function exportCSV() { window.location = API+'?export=1'; }



function esc(s){ return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg,type){ if(window.showNotification) showNotification(msg,type); }

loadUsers();
</script>
</body>
</html>
