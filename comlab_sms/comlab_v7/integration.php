<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user = requireAuth('integration');
$isAdmin = ($user['role'] === ROLE_ADMIN);
$base = getBasePath();
$H = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Integration Hub - COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
  <style>
    .integration-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    }
    .integration-shell {
      display: grid;
      gap: 1rem;
      grid-template-columns: 1.2fr 1fr;
      align-items: start;
      margin-top: 1rem;
    }
    .integration-stack {
      display: grid;
      gap: 1rem;
    }
    .integration-route-grid {
      display: grid;
      gap: .9rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .integration-route-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      background: #fff;
      padding: 1rem;
      display: grid;
      gap: .75rem;
    }
    .integration-route-top {
      display: flex;
      justify-content: space-between;
      gap: .75rem;
      align-items: flex-start;
    }
    .integration-route-title {
      font-size: .95rem;
      font-weight: 700;
      color: var(--text);
    }
    .integration-route-note {
      color: var(--muted);
      font-size: .8rem;
      line-height: 1.5;
    }
    .integration-chip-row {
      display: flex;
      flex-wrap: wrap;
      gap: .4rem;
    }
    .integration-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      border-radius: 999px;
      padding: .35rem .6rem;
      font-size: .72rem;
      background: #f8fafc;
      color: #334155;
      border: 1px solid #e2e8f0;
    }
    .integration-form-grid {
      display: grid;
      gap: .9rem;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .integration-form-grid label,
    .integration-form-stack label {
      display: block;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: .35rem;
    }
    .integration-form-grid input,
    .integration-form-grid select,
    .integration-form-grid textarea,
    .integration-form-stack input,
    .integration-form-stack select,
    .integration-form-stack textarea {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: .72rem .8rem;
      font: inherit;
      color: var(--text);
      background: #fff;
    }
    .integration-form-grid textarea,
    .integration-form-stack textarea {
      min-height: 100px;
      resize: vertical;
    }
    .integration-form-stack {
      display: grid;
      gap: .9rem;
    }
    .integration-actions {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-top: 1rem;
    }
    .integration-kv {
      display: grid;
      gap: .55rem;
    }
    .integration-kv-row {
      display: flex;
      justify-content: space-between;
      gap: .75rem;
      padding: .65rem 0;
      border-bottom: 1px solid var(--border);
      font-size: .8rem;
    }
    .integration-kv-row:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }
    .integration-kv-label {
      color: var(--muted);
      font-weight: 600;
    }
    .integration-panel-note {
      margin-top: .9rem;
      padding: .9rem 1rem;
      border-radius: 16px;
      background: #f8fafc;
      color: var(--muted);
      font-size: .8rem;
      line-height: 1.5;
      border: 1px solid #e2e8f0;
    }
    .integration-empty-inline {
      padding: 1rem 0;
      color: var(--muted);
      font-size: .82rem;
    }
    .integration-table-title {
      display: flex;
      justify-content: space-between;
      gap: .75rem;
      align-items: center;
    }
    .integration-subtle {
      color: var(--muted);
      font-size: .8rem;
    }
    .integration-card-body {
      padding: 1rem 1.25rem 1.25rem;
    }
    .integration-card-body .integration-form-grid:first-child,
    .integration-card-body .integration-form-stack:first-child,
    .integration-card-body .integration-kv:first-child {
      margin-top: 0;
    }
    .integration-card-body .table-responsive {
      margin: 0 -1.25rem -1.25rem;
    }
    .integration-card-body .data-table thead th,
    .integration-card-body .data-table tbody td {
      padding-left: 1.25rem;
      padding-right: 1.25rem;
    }
    .integration-card-body .empty-state {
      padding: 1rem 0;
    }
    .integration-form-grid > div,
    .integration-form-stack > div {
      min-width: 0;
    }
    .integration-kv-row strong {
      text-align: right;
      justify-self: end;
    }
    @media (max-width: 960px) {
      .integration-shell {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<?php renderSidebar($user, 'integration'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Integration Hub'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Integration Hub</h1>
      <p class="page-subtitle">Coordinate COMLAB intake from Registrar and publish laboratory operations updates to PMED and CRAD Management from one workspace.</p>
    </div>
    <?php if ($isAdmin): ?>
    <div class="integration-actions">
      <a href="#dispatchRecordForm" class="btn btn-navy"><i class="fas fa-paper-plane"></i> Dispatch Record</a>
      <a href="#dispatchReportForm" class="btn btn-ghost"><i class="fas fa-file-waveform"></i> Dispatch PMED Report</a>
    </div>
    <?php endif; ?>
  </div>

  <div class="stats-grid" id="statsGrid">
    <?php for ($i = 0; $i < 4; $i++): ?>
    <div class="stat-card">
      <div class="stat-icon-wrap si-blue"><i class="fas fa-spinner fa-spin"></i></div>
      <div><div class="stat-number">-</div><div class="stat-label">Loading</div></div>
    </div>
    <?php endfor; ?>
  </div>

  <div class="integration-grid" style="margin-top:1rem">
    <section class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-diagram-project"></i> Active Connections</h3>
        <span class="badge badge-info" id="connectionCount">-</span>
      </div>
      <div class="integration-card-body">
        <div id="connectionGrid" class="integration-route-grid">
          <div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading department routes...</p></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-circle-nodes"></i> Sync Summary</h3>
        <span class="badge badge-secondary" id="activeScopeBadge">Active only</span>
      </div>
      <div class="integration-card-body">
        <div class="integration-kv" id="summaryPanel">
          <div class="integration-kv-row"><span class="integration-kv-label">Loading</span><strong>Please wait...</strong></div>
        </div>
        <div class="integration-panel-note">
          This COMLAB workspace tracks the active department contract only: <strong>Registrar</strong> for inbound student operations data, <strong>PMED</strong> for reporting, and <strong>CRAD Management</strong> for laboratory activity handoffs.
        </div>
      </div>
    </section>
  </div>

  <div class="integration-shell">
    <section class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fas fa-share-from-square"></i> Dispatch Integration Record</h3>
        <span class="badge <?php echo $isAdmin ? 'badge-success' : 'badge-warning'; ?>"><?php echo $isAdmin ? 'Admin action' : 'Read only'; ?></span>
      </div>
      <div class="integration-card-body">
      <?php if (!$isAdmin): ?>
      <div class="integration-panel-note">Only COMLAB administrators can dispatch integration records. Faculty can still review route readiness and recent inbound or outbound traffic.</div>
      <?php else: ?>
      <form id="dispatchRecordForm">
        <div class="integration-form-grid">
          <div>
            <label for="recordTarget">Target Department</label>
            <select id="recordTarget" name="target_department" required>
              <option value="">Select target</option>
            </select>
          </div>
          <div>
            <label for="recordType">Record Type</label>
            <select id="recordType" name="record_type" required>
              <option value="">Select record type</option>
            </select>
          </div>
          <div>
            <label for="recordSubjectRef">Subject Reference</label>
            <input id="recordSubjectRef" name="subject_ref" placeholder="RPT-20260320-001">
          </div>
          <div>
            <label for="recordTitle">Title</label>
            <input id="recordTitle" name="title" placeholder="COMLAB workflow dispatch" required>
          </div>
        </div>
        <div class="integration-form-stack">
          <div>
            <label for="recordSummary">Payload Summary</label>
            <textarea id="recordSummary" name="summary" placeholder="Add the COMLAB summary, notes, and package details that should travel with this record."></textarea>
          </div>
        </div>
        <div class="integration-actions">
          <button type="submit" class="btn btn-navy"><i class="fas fa-paper-plane"></i> Send Record</button>
        </div>
      </form>
      <?php endif; ?>
      </div>
    </section>

    <section class="integration-stack">
      <section class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-chart-column"></i> PMED Operations Package</h3>
          <span class="badge badge-info">Report handoff</span>
        </div>
        <div class="integration-card-body">
          <div id="reportSummaryPanel" class="integration-kv">
            <div class="integration-kv-row"><span class="integration-kv-label">Loading</span><strong>Preparing PMED operations snapshot...</strong></div>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fas fa-file-export"></i> Dispatch PMED Report</h3>
          <span class="badge <?php echo $isAdmin ? 'badge-success' : 'badge-warning'; ?>"><?php echo $isAdmin ? 'Enabled' : 'Admin only'; ?></span>
        </div>
        <div class="integration-card-body">
        <?php if (!$isAdmin): ?>
        <div class="integration-panel-note">The PMED report action is available to COMLAB administrators. Usage, attendance, and equipment readiness stay visible here for all signed-in users.</div>
        <?php else: ?>
        <form id="dispatchReportForm">
          <div class="integration-form-grid">
            <div>
              <label for="reportCoverage">Coverage Period</label>
              <input id="reportCoverage" name="coverage_period" type="date">
            </div>
            <div>
              <label for="reportRequestedBy">Requested By</label>
              <input id="reportRequestedBy" name="requested_by" value="COMLAB Integration Hub">
            </div>
            <div>
              <label for="reportTitle">Report Title</label>
              <input id="reportTitle" name="title" value="COMLAB daily usage report">
            </div>
          </div>
          <div class="integration-form-stack">
            <div>
              <label for="reportNotes">Additional Notes</label>
              <textarea id="reportNotes" name="notes" placeholder="Attach PMED-facing notes for usage, attendance, and equipment readiness."></textarea>
            </div>
          </div>
        <div class="integration-actions">
          <button type="submit" class="btn btn-navy"><i class="fas fa-file-arrow-up"></i> Send PMED Report</button>
        </div>
      </form>
      <?php endif; ?>
        </div>
      </section>
    </section>
  </div>

  <div class="integration-shell">
    <section class="card">
      <div class="card-header integration-table-title">
        <h3 class="card-title"><i class="fas fa-arrow-up-right-dots"></i> Recent Outbound Records</h3>
        <span class="integration-subtle" id="outboundCountLabel">Loading...</span>
      </div>
      <div class="integration-card-body">
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Created</th>
                <th>Target</th>
                <th>Record Type</th>
                <th>Title</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="outboundBody">
              <tr><td colspan="5"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading outbound records...</p></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header integration-table-title">
        <h3 class="card-title"><i class="fas fa-arrow-down-left-dots"></i> Recent Inbound Records</h3>
        <span class="integration-subtle" id="inboundCountLabel">Loading...</span>
      </div>
      <div class="integration-card-body">
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Created</th>
                <th>Source</th>
                <th>Record Type</th>
                <th>Title</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="inboundBody">
              <tr><td colspan="5"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading inbound records...</p></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>
</main>

<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
const BASE = '<?= $H($base) ?>';
const ACTIVE_SCOPE = {
  REGISTRAR: {
    incoming: ['student_account_information', 'class_schedule_feed', 'subject_lab_assignments'],
    outgoing: ['laboratory_attendance_records']
  },
  PMED: {
    incoming: [],
    outgoing: ['laboratory_usage_reports', 'equipment_log_reports']
  },
  CRAD: {
    incoming: [],
    outgoing: ['laboratory_activity_reports']
  }
};
const ACTIVE_DEPARTMENTS = Object.keys(ACTIVE_SCOPE);
const API = {
  map: `${BASE}api/integrations/departments/map.php`,
  records: `${BASE}api/integrations/departments/records.php`,
  report: `${BASE}api/integrations/departments/report.php?department=PMED`
};

const routeDirectory = new Map();
let outboundRoutes = [];
let inboundRoutes = [];
let pmedReport = null;

function isActivePeer(code) {
  return ACTIVE_DEPARTMENTS.includes(String(code || '').toUpperCase());
}

function isRouteInActiveScope(route) {
  const code = String(route.department?.code || '').toUpperCase();
  const scope = ACTIVE_SCOPE[code];
  if (!scope) return false;

  const direction = String(route.direction || '').toLowerCase();
  const recordCode = String(route.record_type?.code || '').toLowerCase();
  const allowed = direction === 'incoming' ? scope.incoming : scope.outgoing;
  return allowed.includes(recordCode);
}

function isRecordInActiveScope(record) {
  const direction = String(record.direction || '').toLowerCase();
  const peer = direction === 'incoming' ? record.sender_department : record.receiver_department;
  const code = String(peer?.code || '').toUpperCase();
  const scope = ACTIVE_SCOPE[code];
  if (!scope) return false;

  const recordCode = String(record.record_type?.code || '').toLowerCase();
  const allowed = direction === 'incoming' ? scope.incoming : scope.outgoing;
  return allowed.includes(recordCode);
}

function badgeClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'sent' || normalized === 'acknowledged') return 'badge-success';
  if (normalized === 'received') return 'badge-info';
  if (normalized === 'archived') return 'badge-secondary';
  return 'badge-warning';
}

function formatDateTime(value) {
  if (!value) return '-';
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return esc(value);
  return parsed.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function uniquePeers(routes) {
  const seen = new Set();
  return routes.filter((route) => {
    const key = String(route.department?.code || '').toUpperCase();
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function renderStats(mapPayload, outboundRecords, inboundRecords) {
  const activeConnections = uniquePeers([...(mapPayload.incoming || []), ...(mapPayload.outgoing || [])]).length;
  const cards = [
    ['si-blue', 'fa-arrow-right-arrow-left', activeConnections, 'Active Connections'],
    ['si-teal', 'fa-arrow-down-left-dots', (mapPayload.incoming || []).length, 'Inbound Routes'],
    ['si-green', 'fa-arrow-up-right-dots', (mapPayload.outgoing || []).length, 'Outbound Routes'],
    ['si-orange', 'fa-file-lines', outboundRecords.length + inboundRecords.length, 'Recent Records'],
  ];

  document.getElementById('statsGrid').innerHTML = cards.map(([cls, icon, value, label]) =>
    `<div class="stat-card">
      <div class="stat-icon-wrap ${cls}"><i class="fas ${icon}"></i></div>
      <div><div class="stat-number">${esc(String(value))}</div><div class="stat-label">${esc(label)}</div></div>
    </div>`
  ).join('');
}

function renderConnections(mapPayload) {
  const activeConnections = {};
  [...(mapPayload.incoming || []), ...(mapPayload.outgoing || [])].forEach((route) => {
    const code = String(route.department?.code || '').toUpperCase();
    if (!isActivePeer(code)) return;

    if (!activeConnections[code]) {
      activeConnections[code] = {
        department: route.department,
        incoming: [],
        outgoing: []
      };
    }

    activeConnections[code][route.direction].push(route.record_type);
  });

  const cards = Object.values(activeConnections);
  document.getElementById('connectionCount').textContent = String(cards.length);

  if (!cards.length) {
    document.getElementById('connectionGrid').innerHTML = '<div class="empty-state"><i class="fas fa-link-slash"></i><p>No active COMLAB connections are available yet.</p></div>';
    return;
  }

  document.getElementById('connectionGrid').innerHTML = cards.map((connection) => {
    const incoming = (connection.incoming || []).map((record) => `<span class="integration-chip"><i class="fas fa-arrow-down"></i>${esc(record.name)}</span>`).join('');
    const outgoing = (connection.outgoing || []).map((record) => `<span class="integration-chip"><i class="fas fa-arrow-up"></i>${esc(record.name)}</span>`).join('');

    return `<article class="integration-route-card">
      <div class="integration-route-top">
        <div>
          <div class="integration-route-title">${esc(connection.department.name)}</div>
          <div class="integration-route-note">${esc(connection.department.code)}</div>
        </div>
        <span class="badge badge-info">Active</span>
      </div>
      <div>
        <div class="integration-subtle">Inbound to COMLAB</div>
        <div class="integration-chip-row">${incoming || '<span class="integration-empty-inline">No active inbound types.</span>'}</div>
      </div>
      <div>
        <div class="integration-subtle">Outbound from COMLAB</div>
        <div class="integration-chip-row">${outgoing || '<span class="integration-empty-inline">No active outbound types.</span>'}</div>
      </div>
    </article>`;
  }).join('');
}

function renderSummary(mapPayload, outboundRecords, inboundRecords) {
  const activePeers = uniquePeers([...(mapPayload.incoming || []), ...(mapPayload.outgoing || [])]);
  const stagedRoutes = ((mapPayload.summary?.connected_departments || 0) - activePeers.length);
  const rows = [
    ['Active partner departments', activePeers.length],
    ['Inbound routes available', (mapPayload.incoming || []).length],
    ['Outbound routes available', (mapPayload.outgoing || []).length],
    ['Staged peer routes hidden here', stagedRoutes > 0 ? stagedRoutes : 0],
    ['Recent outbound records', outboundRecords.length],
    ['Recent inbound records', inboundRecords.length]
  ];

  document.getElementById('summaryPanel').innerHTML = rows.map(([label, value]) =>
    `<div class="integration-kv-row"><span class="integration-kv-label">${esc(String(label))}</span><strong>${esc(String(value))}</strong></div>`
  ).join('');
}

function renderReportSummary(reportPayload) {
  const report = reportPayload?.report || null;
  if (!report) {
    document.getElementById('reportSummaryPanel').innerHTML = '<div class="integration-kv-row"><span class="integration-kv-label">PMED route</span><strong>Unavailable</strong></div>';
    return;
  }

  const rows = [
    ['Target department', report.target_department?.name || 'PMED'],
    ['Default report type', report.report_type_code || 'laboratory_usage_reports'],
    ['Recent document count', (report.recent_documents || []).length],
    ['Dispatch supported', report.dispatch_supported ? 'Yes' : 'No'],
    ['Usage sessions (7 days)', report.usage_report?.summary?.usage_sessions_7d ?? 0],
    ['Equipment logs (30 days)', report.usage_report?.summary?.equipment_logs_30d ?? 0],
    ['Snapshot source', report.usage_report?.generated_at || report.generated_at || '-']
  ];

  document.getElementById('reportSummaryPanel').innerHTML = rows.map(([label, value]) =>
    `<div class="integration-kv-row"><span class="integration-kv-label">${esc(String(label))}</span><strong>${esc(String(value))}</strong></div>`
  ).join('');
}

function renderRecords(targetId, rows, direction) {
  const body = document.getElementById(targetId);
  const labelId = direction === 'outbound' ? 'outboundCountLabel' : 'inboundCountLabel';
  document.getElementById(labelId).textContent = `${rows.length} recent ${direction} item${rows.length === 1 ? '' : 's'}`;

  if (!rows.length) {
    body.innerHTML = `<tr><td colspan="5"><div class="empty-state"><i class="fas fa-box-open"></i><p>No ${esc(direction)} integration records found in the active scope.</p></div></td></tr>`;
    return;
  }

  body.innerHTML = rows.map((record) => {
    const peer = direction === 'outbound' ? record.receiver_department : record.sender_department;
    return `<tr>
      <td class="mono" style="font-size:.75rem">${esc(formatDateTime(record.created_at))}</td>
      <td>${esc(peer?.name || peer?.code || '-')}</td>
      <td><span class="code-tag">${esc(record.record_type?.name || record.record_type?.code || '-')}</span></td>
      <td>${esc(record.title || '-')}</td>
      <td><span class="badge ${badgeClass(record.status)}">${esc(record.status || 'pending')}</span></td>
    </tr>`;
  }).join('');
}

function populateDispatchOptions() {
  const targetSelect = document.getElementById('recordTarget');
  const typeSelect = document.getElementById('recordType');
  if (!targetSelect || !typeSelect) return;

  const peers = uniquePeers(outboundRoutes);
  targetSelect.innerHTML = '<option value="">Select target</option>' + peers.map((route) =>
    `<option value="${esc(route.department.code)}">${esc(route.department.name)}</option>`
  ).join('');

  const syncRecordTypes = () => {
    const selectedTarget = String(targetSelect.value || '').toUpperCase();
    const availableRoutes = outboundRoutes.filter((route) => String(route.department?.code || '').toUpperCase() === selectedTarget);
    typeSelect.innerHTML = '<option value="">Select record type</option>' + availableRoutes.map((route) =>
      `<option value="${esc(route.record_type.code)}">${esc(route.record_type.name)}</option>`
    ).join('');

    if (availableRoutes[0]) {
      document.getElementById('recordTitle').value = `COMLAB ${availableRoutes[0].record_type.name} for ${availableRoutes[0].department.name}`;
    }
  };

  targetSelect.onchange = syncRecordTypes;
  syncRecordTypes();
}

async function loadWorkspace() {
  try {
    const [mapResponse, outboundResponse, inboundResponse, reportResponse] = await Promise.all([
      fetch(API.map),
      fetch(`${API.records}?direction=outgoing&limit=12`),
      fetch(`${API.records}?direction=incoming&limit=12`),
      fetch(API.report)
    ]);

    const [mapPayload, outboundPayload, inboundPayload, reportPayload] = await Promise.all([
      mapResponse.json(),
      outboundResponse.json(),
      inboundResponse.json(),
      reportResponse.json()
    ]);

    if (!mapPayload.success) throw new Error(mapPayload.message || 'Unable to load integration map.');
    if (!outboundPayload.success) throw new Error(outboundPayload.message || 'Unable to load outbound records.');
    if (!inboundPayload.success) throw new Error(inboundPayload.message || 'Unable to load inbound records.');

    outboundRoutes = (mapPayload.outgoing || []).filter((route) => isRouteInActiveScope(route));
    inboundRoutes = (mapPayload.incoming || []).filter((route) => isRouteInActiveScope(route));
    pmedReport = reportPayload.success ? reportPayload : null;

    const outboundRecords = (outboundPayload.records || []).filter((record) => isRecordInActiveScope(record));
    const inboundRecords = (inboundPayload.records || []).filter((record) => isRecordInActiveScope(record));

    renderStats({ incoming: inboundRoutes, outgoing: outboundRoutes, summary: mapPayload.summary || {} }, outboundRecords, inboundRecords);
    renderConnections({ incoming: inboundRoutes, outgoing: outboundRoutes, summary: mapPayload.summary || {} });
    renderSummary({ incoming: inboundRoutes, outgoing: outboundRoutes, summary: mapPayload.summary || {} }, outboundRecords, inboundRecords);
    renderReportSummary(pmedReport);
    renderRecords('outboundBody', outboundRecords, 'outbound');
    renderRecords('inboundBody', inboundRecords, 'inbound');
    populateDispatchOptions();
  } catch (error) {
    console.error(error);
    showNotification(error instanceof Error ? error.message : 'Unable to load the COMLAB integration workspace.', 'danger');
  }
}

async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const data = await response.json();
  if (!response.ok || !data.success) {
    throw new Error(data.message || 'The integration request failed.');
  }

  return data;
}

const dispatchRecordForm = document.getElementById('dispatchRecordForm');
if (dispatchRecordForm) {
  dispatchRecordForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const target = document.getElementById('recordTarget').value;
    const recordType = document.getElementById('recordType').value;
    const subjectRef = document.getElementById('recordSubjectRef').value.trim() || `COMLAB-${Date.now()}`;
    const title = document.getElementById('recordTitle').value.trim();
    const summary = document.getElementById('recordSummary').value.trim();

    try {
      await postJson(API.records, {
        action: 'dispatch_record',
        sender_department_code: 'COMLAB',
        receiver_department_code: target,
        record_type_code: recordType,
        subject_type: 'system',
        subject_ref: subjectRef,
        title,
        source_system: 'COMLAB',
        source_reference: `comlab-ui-${Date.now()}`,
        payload: {
          summary,
          dispatched_from: 'integration_hub'
        }
      });

      dispatchRecordForm.reset();
      showNotification('Integration record dispatched successfully.', 'success');
      await loadWorkspace();
    } catch (error) {
      showNotification(error instanceof Error ? error.message : 'Unable to dispatch the record.', 'danger');
    }
  });
}

const dispatchReportForm = document.getElementById('dispatchReportForm');
if (dispatchReportForm) {
  dispatchReportForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const coveragePeriod = document.getElementById('reportCoverage').value;
    const requestedBy = document.getElementById('reportRequestedBy').value.trim() || 'COMLAB Integration Hub';
    const title = document.getElementById('reportTitle').value.trim() || 'COMLAB daily usage report';
    const notes = document.getElementById('reportNotes').value.trim();

    try {
      const reportApiUrl = API.report.split('?')[0];
      await postJson(reportApiUrl, {
        action: 'dispatch_report',
        target_key: 'PMED',
        report_type: pmedReport?.report?.report_type_code || 'laboratory_usage_reports',
        title,
        payload: {
          coverage_period: coveragePeriod || null,
          requested_by: requestedBy,
          notes
        }
      });

      dispatchReportForm.reset();
      showNotification('PMED report package dispatched successfully.', 'success');
      await loadWorkspace();
    } catch (error) {
      showNotification(error instanceof Error ? error.message : 'Unable to dispatch the PMED report.', 'danger');
    }
  });
}

loadWorkspace();
</script>
</body>
</html>
