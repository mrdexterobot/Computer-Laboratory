<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user    = requireAuth('devices');
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
  <title>Devices — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'devices'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Devices'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Devices</h1>
      <p class="page-subtitle">Manage laboratory equipment and track status.</p>
    </div>
    <?php if ($isAdmin): ?>
    <button class="btn btn-navy" onclick="openModal('deviceModal')">
      <i class="fas fa-plus"></i> Add Device
    </button>
    <?php endif; ?>
  </div>

  <!-- Stats -->
  <div class="stats-grid" id="devStats">
    <div class="stat-card"><div class="stat-icon-wrap si-blue"><i class="fas fa-desktop"></i></div><div><div class="stat-number" id="stTotal">—</div><div class="stat-label">Total Devices</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-green"><i class="fas fa-circle-check"></i></div><div><div class="stat-number" id="stAvail">—</div><div class="stat-label">Available</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-orange"><i class="fas fa-wrench"></i></div><div><div class="stat-number" id="stRepair">—</div><div class="stat-label">Under Repair</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-red"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-number" id="stDamaged">—</div><div class="stat-label">Damaged</div></div></div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-desktop"></i> Device Inventory</h3>
    </div>
    <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border)">
      <div class="table-controls" style="margin:0">
        <div class="search-box">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" id="devSearch" placeholder="Search code, brand, model…" oninput="filterDevices()">
        </div>
        <div class="filter-tabs" id="statusTabs">
          <button class="ftab active" onclick="setFilter(this,'')">All</button>
          <button class="ftab" onclick="setFilter(this,'Available')">Available</button>
          <button class="ftab" onclick="setFilter(this,'In Use')">In Use</button>
          <button class="ftab" onclick="setFilter(this,'Under Repair')">Under Repair</button>
          <button class="ftab" onclick="setFilter(this,'Damaged')">Damaged</button>
        </div>
        <select class="form-control" style="width:150px;height:36px;font-size:.8rem" id="labFilter" onchange="filterDevices()">
          <option value="">All Labs</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Type</th><th>Brand / Model</th><th>Lab</th><th>Serial</th><th>Status</th><?= $isAdmin ? '<th>Actions</th>' : '' ?></tr></thead>
        <tbody id="devBody"><tr><td colspan="<?= $isAdmin?7:6 ?>"><div class="empty-state" style="padding:2rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i><p>Loading…</p></div></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</main>

<?php if ($isAdmin): ?>
<!-- Add/Edit Device Modal -->
<div class="modal-overlay" id="deviceModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="devMTitle"><i class="fas fa-desktop"></i> Add Device</h3>
      <button class="modal-close" onclick="closeModal('deviceModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-danger" id="devErr" style="display:none"></div>
      <form id="devForm">
        <input type="hidden" name="csrf_token" value="<?= $H($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="device_id" id="devEditId">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Device Code *</label><input class="form-control" name="device_code" required placeholder="PC-A-001"></div>
          <div class="form-group"><label class="form-label">Type *</label>
            <select class="form-control" name="device_type" required>
              <option value="">Select type…</option>
              <option>Desktop</option><option>Laptop</option><option>Monitor</option>
              <option>Printer</option><option>Keyboard</option><option>Mouse</option><option>Other</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Brand</label><input class="form-control" name="brand" placeholder="Dell, HP, Lenovo…"></div>
          <div class="form-group"><label class="form-label">Model</label><input class="form-control" name="model" placeholder="OptiPlex 7090…"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Serial Number</label><input class="form-control" name="serial_number"></div>
          <div class="form-group"><label class="form-label">Lab Location</label>
            <select class="form-control" name="location_id" id="devLocSel"><option value="">Unassigned</option></select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status">
              <option>Available</option><option>In Use</option><option>Under Repair</option><option>Damaged</option><option>Decommissioned</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Purchase Date</label><input class="form-control" type="date" name="purchase_date"></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('deviceModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveDevice()"><i class="fas fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API   = '<?= $H($base) ?>api/devices.php';
const CSRF  = '<?= $H($csrf) ?>';
const ADMIN = <?= $isAdmin?'true':'false' ?>;
let allDevs = [], statusFilter = '';

async function load() {
  const [dRes, lRes] = await Promise.all([fetch(API), fetch('<?= $H($base) ?>api/locations.php')]);
  const d = await dRes.json(), l = await lRes.json();

  // Populate lab dropdowns
  const labs = l.locations || [];
  const labSel = document.getElementById('labFilter');
  const devLoc = document.getElementById('devLocSel');
  labs.forEach(lab => {
    labSel.innerHTML += `<option value="${lab.location_id}">${esc(lab.lab_code)} — ${esc(lab.lab_name)}</option>`;
    if (devLoc) devLoc.innerHTML += `<option value="${lab.location_id}">${esc(lab.lab_code)} — ${esc(lab.lab_name)}</option>`;
  });

  allDevs = d.devices || [];
  const c = d.stats || {};
  document.getElementById('stTotal').textContent   = c.total   || 0;
  document.getElementById('stAvail').textContent   = c.available || 0;
  document.getElementById('stRepair').textContent  = c.under_repair || 0;
  document.getElementById('stDamaged').textContent = c.damaged || 0;
  render(allDevs);
}

function render(rows) {
  const tb = document.getElementById('devBody');
  const cols = ADMIN ? 7 : 6;
  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="${cols}"><div class="empty-state" style="padding:2rem"><i class="fas fa-desktop"></i><p>No devices found.</p></div></td></tr>`; return;
  }
  const sc = { Available:'badge-success', 'In Use':'badge-info', 'Under Repair':'badge-warning', Damaged:'badge-danger', Decommissioned:'badge-secondary' };
  tb.innerHTML = rows.map(d => `<tr>
    <td><span class="code-tag">${esc(d.device_code)}</span></td>
    <td class="u-sub">${esc(d.device_type)}</td>
    <td><div class="fw-600" style="font-size:.82rem">${esc(d.brand||'')} ${esc(d.model||'')}</div></td>
    <td class="u-sub">${esc(d.lab_code||'Unassigned')}</td>
    <td class="mono" style="font-size:.72rem;color:var(--muted)">${esc(d.serial_number||'—')}</td>
    <td><span class="badge ${sc[d.status]||'badge-secondary'}">${esc(d.status)}</span></td>
    ${ADMIN?`<td style="display:flex;gap:4px;padding:.7rem 1rem">
      <button class="act-btn" onclick="editDev(${d.device_id})" title="Edit"><i class="fas fa-pen"></i></button>
      <button class="act-btn act-danger" onclick="deleteDev(${d.device_id},'${esc(d.device_code)}')" title="Delete"><i class="fas fa-trash"></i></button>
    </td>`:''}
  </tr>`).join('');
}

function setFilter(el, s) {
  document.querySelectorAll('#statusTabs .ftab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active'); statusFilter = s; filterDevices();
}
function filterDevices() {
  const q = document.getElementById('devSearch').value.toLowerCase();
  const l = document.getElementById('labFilter').value;
  render(allDevs.filter(d => {
    const t = `${d.device_code} ${d.brand||''} ${d.model||''} ${d.device_type}`.toLowerCase();
    return (!q||t.includes(q)) && (!statusFilter||d.status===statusFilter) && (!l||String(d.location_id)===l);
  }));
}

function editDev(id) {
  const d = allDevs.find(x=>x.device_id==id); if (!d) return;
  document.getElementById('devForm').reset();
  document.getElementById('devMTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Device';
  document.getElementById('devEditId').value = id;
  const f = document.getElementById('devForm');
  f.device_code.value   = d.device_code;
  f.device_type.value   = d.device_type;
  f.brand.value         = d.brand||'';
  f.model.value         = d.model||'';
  f.serial_number.value = d.serial_number||'';
  f.location_id.value   = d.location_id||'';
  f.status.value        = d.status;
  f.purchase_date.value = d.purchase_date||'';
  document.getElementById('devErr').style.display='none';
  openModal('deviceModal');
}

async function saveDevice() {
  const fd = new FormData(document.getElementById('devForm'));
  const err = document.getElementById('devErr');
  err.style.display = 'none';
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { closeModal('deviceModal'); load(); showNotification(d.message,'success'); }
  else { err.textContent=d.message; err.style.display='flex'; }
}

async function deleteDev(id, code) {
  if (!confirm(`Delete device "${code}"?`)) return;
  const fd = new FormData();
  fd.append('action','delete'); fd.append('device_id',id); fd.append('csrf_token',CSRF);
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { load(); showNotification(d.message,'success'); }
  else showNotification(d.message,'danger');
}

load();
</script>
</body>
</html>
