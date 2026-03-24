<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user    = requireAuth('requests');
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
  <title>Requests — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'requests'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Requests'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Requests</h1>
      <p class="page-subtitle"><?= $isAdmin ? 'Review and manage maintenance and unit requests.' : 'Submit and track your requests.' ?></p>
    </div>
    <button class="btn btn-navy" onclick="openModal('reqModal')">
      <i class="fas fa-plus"></i> New Request
    </button>
  </div>

  <div class="table-controls">
    <div class="search-box">
      <i class="fas fa-magnifying-glass"></i>
      <input type="text" id="reqSearch" placeholder="Search requests…" oninput="filterReqs()">
    </div>
    <div class="filter-tabs">
      <button class="ftab active" onclick="setStatus(this,'')">All</button>
      <button class="ftab" onclick="setStatus(this,'Pending')">Pending</button>
      <button class="ftab" onclick="setStatus(this,'Approved')">Approved</button>
      <button class="ftab" onclick="setStatus(this,'Completed')">Completed</button>
      <button class="ftab" onclick="setStatus(this,'Rejected')">Rejected</button>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Type</th><th>Submitted By</th><th>Dept/Lab</th><th>Description</th><th>Date Needed</th><th>Status</th><th>PMED Status</th><th>Actions</th></tr></thead>
        <tbody id="reqBody"><tr><td colspan="8"><div class="empty-state" style="padding:2rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i><p>Loading…</p></div></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</main>

<!-- New Request Modal -->
<div class="modal-overlay" id="reqModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-clipboard-plus"></i> New Request</h3>
      <button class="modal-close" onclick="closeModal('reqModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-danger" id="reqErr" style="display:none"></div>
      <form id="reqForm">
        <input type="hidden" name="csrf_token" value="<?= $H($csrf) ?>">
        <input type="hidden" name="action" value="submit">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Request Type *</label>
            <select class="form-control" name="request_type" required>
              <option value="">Select…</option>
              <option value="Maintenance">Maintenance</option>
              <option value="Unit">Unit Request</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Location *</label>
            <input class="form-control" name="location_text" required placeholder="e.g. Computer Lab A, Clinic, Guidance Office, Room 201…">
          </div>
        </div>
        <div class="form-group"><label class="form-label">Description *</label>
          <textarea class="form-control" name="issue_description" rows="3" required placeholder="Describe the issue or request…"></textarea>
        </div>
        <div class="form-group"><label class="form-label">Date Needed</label>
          <input class="form-control" type="date" name="date_needed">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('reqModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitReq()"><i class="fas fa-paper-plane"></i> Submit</button>
    </div>
  </div>
</div>

<!-- Review Modal (admin) -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="reviewModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-clipboard-check"></i> Review Request</h3>
      <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
    </div>
    <div class="modal-body" id="reviewBody"></div>
    <div class="modal-footer" id="reviewFtr">
      <button class="btn btn-ghost" onclick="closeModal('reviewModal')">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const API   = '<?= $H($base) ?>api/requests.php';
const CSRF  = '<?= $H($csrf) ?>';
const ADMIN = <?= $isAdmin?'true':'false' ?>;
const MY_ID = <?= (int)$user['user_id'] ?>;
let allReqs = [], statusFilter = '';

async function load() {
  const r = await (await fetch(API)).json();
  allReqs = r.requests || [];
  render(allReqs);
}

function render(rows) {
  const tb = document.getElementById('reqBody');
  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="8"><div class="empty-state" style="padding:2rem"><i class="fas fa-clipboard-list"></i><p>No requests found.</p></div></td></tr>`; return;
  }
  const sc = {
    Pending:'badge-warning',
    Approved:'badge-success',
    Completed:'badge-info',
    Rejected:'badge-danger',
    'In Progress':'badge-info',
    'Awaiting Forward':'badge-secondary',
    Verified:'badge-primary'
  };
  tb.innerHTML = rows.map(r => `<tr>
    <td><span class="badge badge-info">${esc(r.request_type)}</span></td>
    <td class="fw-600" style="font-size:.82rem">${esc(r.submitted_by_name||'—')}</td>
    <td class="u-sub">${esc(r.department||r.location_text||r.lab_code||'—')}</td>
    <td class="u-sub" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.issue_description)}</td>
    <td class="u-sub">${r.date_needed||'—'}</td>
    <td><span class="badge ${sc[r.status]||'badge-secondary'}">${esc(r.status)}</span></td>
    <td><span class="badge ${sc[r.pmed_status]||'badge-secondary'}">${esc(r.pmed_status||'Pending')}</span></td>
    <td style="display:flex;gap:4px;padding:.7rem 1rem">
      <button class="act-btn" onclick="viewReq(${r.request_id})" title="View"><i class="fas fa-eye"></i></button>
      ${ADMIN && r.status==='Pending' ? `
        <button class="act-btn act-success" onclick="decide(${r.request_id},'Approved')" title="Approve"><i class="fas fa-check"></i></button>
        <button class="act-btn act-danger"  onclick="decide(${r.request_id},'Rejected')" title="Reject"><i class="fas fa-xmark"></i></button>
      ` : ''}
    </td>
  </tr>`).join('');
}

function setStatus(el, s) {
  document.querySelectorAll('.filter-tabs .ftab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active'); statusFilter = s; filterReqs();
}
function filterReqs() {
  const q = document.getElementById('reqSearch').value.toLowerCase();
  render(allReqs.filter(r => {
    const t = `${r.request_type} ${r.issue_description} ${r.submitted_by_name||''}`.toLowerCase();
    return (!q||t.includes(q)) && (!statusFilter||r.status===statusFilter);
  }));
}

async function submitReq() {
  const fd  = new FormData(document.getElementById('reqForm'));
  const err = document.getElementById('reqErr');
  err.style.display = 'none';
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { closeModal('reqModal'); document.getElementById('reqForm').reset(); load(); showNotification(d.message,'success'); }
  else { err.textContent=d.message; err.style.display='flex'; }
}

function viewReq(id) {
  const r = allReqs.find(x=>x.request_id==id); if(!r) return;
  const sc = {
    Pending:'badge-warning',
    Approved:'badge-success',
    Completed:'badge-info',
    Rejected:'badge-danger',
    'Awaiting Forward':'badge-secondary',
    Verified:'badge-primary'
  };
  const isPMED = <?= (isset($user['department']) && $user['department'] === 'PMED') ? 'true' : 'false' ?>;
  document.getElementById('reviewBody').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:.75rem">
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <span class="badge badge-info">${esc(r.request_type)}</span>
        <span class="badge ${sc[r.status]||'badge-secondary'}">${esc(r.status)}</span>
        <span class="badge ${sc[r.pmed_status]||'badge-secondary'}">PMED: ${esc(r.pmed_status||'Pending')}</span>
      </div>
      <div><label class="form-label">Submitted By</label><p class="fw-600">${esc(r.submitted_by_name||'—')}</p></div>
      <div><label class="form-label">Dept/Location</label><p>${esc(r.department||r.location_text||r.lab_code||'—')}</p></div>
      <div><label class="form-label">Description</label><p style="color:var(--secondary);font-size:.85rem;line-height:1.6">${esc(r.issue_description)}</p></div>
      <div style="display:flex;gap:1rem">
        <div><label class="form-label">Date Needed</label><p>${r.date_needed||'—'}</p></div>
        <div><label class="form-label">Submitted</label><p>${r.created_at?.slice(0,10)||'—'}</p></div>
      </div>
      ${r.crad_ref?`<div><label class="form-label">CRAD Reference</label><p class="mono">${esc(r.crad_ref)}</p></div>`:''}
      ${r.rejection_reason?`<div><label class="form-label">Rejection Reason</label><p style="color:var(--red-text)">${esc(r.rejection_reason)}</p></div>`:''}
    </div>`;
  const ftr = document.getElementById('reviewFtr');
  let btns = `<button class="btn btn-ghost" onclick="closeModal('reviewModal')">Close</button>`;
  
  if (ADMIN && r.status==='Pending') {
    btns = `
      <button class="btn btn-ghost" onclick="closeModal('reviewModal')">Cancel</button>
      <button class="btn btn-danger"  onclick="decide(${r.request_id},'Rejected')"><i class="fas fa-xmark"></i> Reject</button>
      <button class="btn btn-success" onclick="decide(${r.request_id},'Approved')"><i class="fas fa-check"></i> Approve</button>`;
    if (r.request_type === 'Unit' && r.pmed_status === 'Awaiting Forward') {
      btns += `<button class="btn btn-navy" onclick="pmedAction(${r.request_id},'forward-to-pmed')"><i class="fas fa-share-from-square"></i> Forward to PMED</button>`;
    }
  } else if (isPMED || ADMIN) {
    if (r.pmed_status === 'Awaiting Forward') {
      btns += `<button class="btn btn-navy" onclick="pmedAction(${r.request_id},'pmed-send')"><i class="fas fa-paper-plane"></i> PMED Send</button>`;
    } else if (r.pmed_status === 'Pending') {
      btns += `<button class="btn btn-primary" onclick="pmedAction(${r.request_id},'pmed-verify')"><i class="fas fa-check-double"></i> PMED Verify</button>`;
    } else if (r.pmed_status === 'Verified') {
      btns += `<button class="btn btn-success" onclick="pmedAction(${r.request_id},'pmed-approve')"><i class="fas fa-award"></i> PMED Approve</button>`;
    } else if (ADMIN && r.pmed_status === 'Approved' && r.status === 'Approved') {
      btns += `<button class="btn btn-primary" onclick="pmedAction(${r.request_id},'comlab-receive')"><i class="fas fa-inbox"></i> COMLAB Receive</button>`;
    }
  }
  
  ftr.innerHTML = btns;
  openModal('reviewModal');
}

async function pmedAction(id, action) {
  const labels = {
    'forward-to-pmed': 'forward to PMED',
    'pmed-send': 'send to PMED queue',
    'pmed-verify': 'PMED verify',
    'pmed-approve': 'PMED approve',
    'comlab-receive': 'COMLAB receive'
  };
  const label = labels[action] || action;
  if (!confirm(`Confirm ${label} for this request?`)) return;
  const d = await (await fetch('<?= $H($base) ?>api/workflow.php', {
    method: 'POST',
    body: JSON.stringify({ action, request_id: id }),
    headers: { 'Content-Type': 'application/json' }
  })).json();
  if (d.success) { closeModal('reviewModal'); load(); showNotification(d.message,'success'); }
  else showNotification(d.message,'danger');
}

async function decide(id, decision) {
  if (decision === 'Rejected' && !confirm('Reject this request? A rejection reason will be noted.')) return;
  const fd = new FormData();
  fd.append('action','review');
  fd.append('request_id', id);
  fd.append('decision', decision);
  if (decision === 'Rejected') {
    const reason = prompt('Enter rejection reason (required):');
    if (!reason || !reason.trim()) { showNotification('Rejection reason is required.','warning'); return; }
    fd.append('rejection_reason', reason.trim());
  }
  fd.append('csrf_token', CSRF);
  const d = await (await fetch(API,{method:'POST',body:fd})).json();
  if (d.success) { closeModal('reviewModal'); load(); showNotification(d.message,'success'); }
  else showNotification(d.message,'danger');
}

load();
</script>
</body>
</html>
