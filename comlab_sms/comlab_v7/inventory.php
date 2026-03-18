<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';

$user = requireAuth('inventory');
$base = getBasePath();
$H = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Inventory — COMLAB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $H($base) ?>assets/comlab.css">
</head>
<body>
<?php renderSidebar($user, 'inventory'); ?>
<main class="main" id="main">
<?php renderTopbar($user, 'Inventory'); ?>

<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Inventory</h1>
      <p class="page-subtitle">Device inventory summary by lab and type.</p>
    </div>
    <button class="btn btn-ghost" onclick="window.location='<?= $H($base) ?>api/inventory.php?export=csv'">
      <i class="fas fa-download"></i> Export CSV
    </button>
  </div>

  <div class="stats-grid" id="invStats">
    <div class="stat-card"><div class="stat-icon-wrap si-blue"><i class="fas fa-boxes"></i></div><div><div class="stat-number" id="stTotal">—</div><div class="stat-label">Total Items</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-green"><i class="fas fa-circle-check"></i></div><div><div class="stat-number" id="stGood">—</div><div class="stat-label">Serviceable</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-orange"><i class="fas fa-wrench"></i></div><div><div class="stat-number" id="stRepair">—</div><div class="stat-label">Under Repair</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap si-red"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-number" id="stDamaged">—</div><div class="stat-label">Damaged / Written Off</div></div></div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-table"></i> Inventory by Lab &amp; Type</h3>
    </div>
    <div class="table-responsive">
      <table class="data-table">
        <thead><tr><th>Lab</th><th>Device Type</th><th>Total</th><th>Available</th><th>In Use</th><th>Repair</th><th>Damaged</th></tr></thead>
        <tbody id="invBody"><tr><td colspan="7"><div class="empty-state" style="padding:2rem"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.5rem"></i></div></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</main>
<div id="toastContainer"></div>
<script src="<?= $H($base) ?>assets/comlab-app.js"></script>
<script>
async function load() {
  const d = await (await fetch('<?= $H($base) ?>api/inventory.php')).json();
  const c   = d.summary || {};
  const labs = d.by_lab || [];

  document.getElementById('stTotal').textContent   = c.total        || 0;
  document.getElementById('stGood').textContent    = c.available    || 0;
  document.getElementById('stRepair').textContent  = c.under_repair || 0;
  document.getElementById('stDamaged').textContent = c.damaged      || 0;

  const tb = document.getElementById('invBody');

  // Flatten: each lab's by_type rows → table rows; unassigned devices too
  const rows = [];
  labs.forEach(lab => {
    if (lab.by_type && lab.by_type.length) {
      lab.by_type.forEach(t => rows.push({
        lab_code:     lab.lab_code || 'Unassigned',
        device_type:  t.device_type,
        total:        t.total        || 0,
        available:    t.available    || 0,
        in_use:       t.in_use       || 0,
        under_repair: t.under_repair || 0,
        damaged:      t.damaged      || 0,
      }));
    } else if (lab.total > 0) {
      rows.push({
        lab_code:     lab.lab_code || 'Unassigned',
        device_type:  '(All)',
        total:        lab.total        || 0,
        available:    lab.available    || 0,
        in_use:       0,
        under_repair: lab.under_repair || 0,
        damaged:      lab.damaged      || 0,
      });
    }
  });

  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="7"><div class="empty-state" style="padding:2rem"><i class="fas fa-boxes"></i><p>No inventory data.</p></div></td></tr>`; return;
  }
  tb.innerHTML = rows.map(r => `<tr>
    <td><span class="code-tag">${esc(r.lab_code)}</span></td>
    <td class="u-sub">${esc(r.device_type)}</td>
    <td class="stat-number" style="font-size:1rem">${r.total}</td>
    <td><span style="color:var(--green);font-weight:600">${r.available}</span></td>
    <td>${r.in_use}</td>
    <td><span style="color:var(--orange);font-weight:600">${r.under_repair}</span></td>
    <td><span style="color:var(--red);font-weight:600">${r.damaged}</span></td>
  </tr>`).join('');
}
load();
</script>
</body>
</html>
