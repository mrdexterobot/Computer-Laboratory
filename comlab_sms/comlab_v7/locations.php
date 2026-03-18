<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user    = requireAuth('locations');
$isAdmin = ($user['role'] === ROLE_ADMIN);
$csrf    = getCsrfToken();
$base    = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Lab Locations — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'locations'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Lab Locations'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Lab Locations</h1>
      <p class="page-subtitle">Manage computer laboratory rooms and capacity.</p>
    </div>
    <?php if ($isAdmin): ?>
    <button class="btn btn-navy" onclick="openModal('labModal')">
      <i class="fas fa-plus"></i> Add Lab
    </button>
    <?php endif; ?>
  </div>

  <div class="card-grid" id="labGrid">
    <div class="stat-card" style="grid-column:1/-1;justify-content:center">
      <i class="fas fa-spinner fa-spin" style="color:var(--muted)"></i>
      <span style="color:var(--muted);font-size:.82rem">Loading labs…</span>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-map-marker-alt"></i> All Labs</h3>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Name</th><th>Building / Room</th><th>Capacity</th><th>Hours</th><th>Devices</th><th>Status</th><?= $isAdmin?'<th>Actions</th>':'' ?></tr></thead>
        <tbody id="labBody"><tr><td colspan="<?= $isAdmin?8:7 ?>"><div class="empty-state" style="padding:1.5rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i></div></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</main>

<?php if ($isAdmin): ?>
<div class="modal-overlay" id="labModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="labMTitle"><i class="fas fa-map-marker-alt"></i> Add Lab</h3>
      <button class="modal-close" onclick="closeModal('labModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-danger" id="labErr" style="display:none"></div>
      <form id="labForm">
        <input type="hidden" name="csrf_token" value="<?= $H($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="location_id" id="labEditId">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Lab Name *</label><input class="form-control" name="lab_name" required placeholder="Computer Lab A"></div>
          <div class="form-group"><label class="form-label">Lab Code *</label><input class="form-control" name="lab_code" required placeholder="LAB-A"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Building</label><input class="form-control" name="building" placeholder="Main Building"></div>
          <div class="form-group"><label class="form-label">Floor</label><input class="form-control" name="floor" placeholder="2nd Floor"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Room No.</label><input class="form-control" name="room_number" placeholder="201"></div>
          <div class="form-group"><label class="form-label">Capacity</label><input class="form-control" type="number" name="capacity" min="1" placeholder="30"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Opens</label><input class="form-control" type="time" name="operating_hours_start" value="07:30"></div>
          <div class="form-group"><label class="form-label">Closes</label><input class="form-control" type="time" name="operating_hours_end" value="19:00"></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('labModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveLab()"><i class="fas fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API  = '<?= $H($base) ?>api/locations.php';
const CSRF = '<?= $H($csrf) ?>';
const ADMIN = <?= $isAdmin?'true':'false' ?>;
let allLabs = [];

async function load() {
  const d = await (await fetch(API)).json();
  allLabs = d.locations || [];

  // Cards
  const grid = document.getElementById('labGrid');
  if (!allLabs.length) {
    grid.innerHTML = '<p style="color:var(--muted);padding:1rem">No labs found.</p>'; 
  } else {
    grid.innerHTML = allLabs.map(l => `
      <div class="stat-card" style="flex-direction:column;align-items:flex-start;gap:.5rem">
        <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
          <span class="code-tag" style="font-size:.8rem">${esc(l.lab_code)}</span>
          <span class="badge ${l.is_active?'badge-success':'badge-secondary'}">${l.is_active?'Active':'Inactive'}</span>
        </div>
        <div class="fw-600" style="font-size:.9rem">${esc(l.lab_name)}</div>
        <div style="color:var(--muted);font-size:.75rem"><i class="fas fa-building" style="margin-right:4px"></i>${esc(l.building||'')} ${esc(l.floor||'')} ${esc(l.room_number?'#'+l.room_number:'')}</div>
        <div style="display:flex;gap:1rem;font-size:.75rem;color:var(--secondary)">
          <span><i class="fas fa-users" style="margin-right:3px;color:var(--blue)"></i>${l.capacity} seats</span>
          <span><i class="fas fa-desktop" style="margin-right:3px;color:var(--green)"></i>${l.total_devices||0} devices</span>
        </div>
      </div>`).join('');
  }

  // Table
  const tb = document.getElementById('labBody');
  const cols = ADMIN ? 8 : 7;
  if (!allLabs.length) {
    tb.innerHTML = `<tr><td colspan="${cols}"><div class="empty-state" style="padding:2rem"><i class="fas fa-map-marker-alt"></i><p>No labs found.</p></div></td></tr>`; return;
  }
  tb.innerHTML = allLabs.map(l => `<tr>
    <td><span class="code-tag">${esc(l.lab_code)}</span></td>
    <td class="fw-600" style="font-size:.82rem">${esc(l.lab_name)}</td>
    <td class="u-sub">${esc(l.building||'')}, ${esc(l.floor||'')} — Rm ${esc(l.room_number||'—')}</td>
    <td class="stat-number" style="font-size:1rem">${l.capacity}</td>
    <td class="mono" style="font-size:.75rem">${(l.operating_hours_start||'').slice(0,5)} – ${(l.operating_hours_end||'').slice(0,5)}</td>
    <td>${l.total_devices||0}</td>
    <td><span class="badge ${l.is_active?'badge-success':'badge-secondary'}">${l.is_active?'Active':'Inactive'}</span></td>
    ${ADMIN?`<td style="display:flex;gap:4px;padding:.7rem 1rem">
      <button class="act-btn" onclick="editLab(${l.location_id})" title="Edit"><i class="fas fa-pen"></i></button>
      <button class="act-btn" onclick="toggleLab(${l.location_id},${l.is_active},'${esc(l.lab_name)}')" title="${l.is_active?'Deactivate':'Activate'}">
        <i class="fas fa-${l.is_active?'ban':'rotate-left'}"></i></button>
    </td>`:''}
  </tr>`).join('');
}

function editLab(id) {
  const l = allLabs.find(x=>x.location_id==id); if(!l) return;
  document.getElementById('labMTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Lab';
  document.getElementById('labEditId').value = id;
  const f = document.getElementById('labForm');
  f.lab_name.value = l.lab_name; f.lab_code.value = l.lab_code;
  f.building.value = l.building||''; f.floor.value = l.floor||'';
  f.room_number.value = l.room_number||''; f.capacity.value = l.capacity||'';
  f.operating_hours_start.value = (l.operating_hours_start||'07:30').slice(0,5);
  f.operating_hours_end.value   = (l.operating_hours_end||'19:00').slice(0,5);
  document.getElementById('labErr').style.display='none';
  openModal('labModal');
}

async function saveLab() {
  const fd  = new FormData(document.getElementById('labForm'));
  const err = document.getElementById('labErr');
  err.style.display = 'none';
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { closeModal('labModal'); load(); showNotification(d.message,'success'); }
  else { err.textContent=d.message; err.style.display='flex'; }
}

async function toggleLab(id, active, name) {
  if (!confirm(`${active?'Deactivate':'Activate'} "${name}"?`)) return;
  const fd = new FormData();
  fd.append('action','toggle'); fd.append('location_id',id); fd.append('csrf_token',CSRF);
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { load(); showNotification(d.message,'success'); }
  else showNotification(d.message,'danger');
}

load();
</script>
</body>
</html>
