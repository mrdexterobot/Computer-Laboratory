<?php
// COMLAB - Topbar Partial

function renderTopbar(array $user, string $pageTitle): void {
    $initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
    $fullName = htmlspecialchars($user['first_name'].' '.$user['last_name']);
    $avBg = ($user['role'] === 'Administrator') ? '#d97706' : '#0d9488';
    $roleBadgeClass = ($user['role'] === 'Administrator') ? 'role-badge-admin' : 'role-badge-faculty';
    ?>
<header class="topbar">
  <div style="display:flex;align-items:center;gap:.75rem">
    <button class="menu-toggle" id="menuToggle" title="Toggle sidebar">
      <i class="fas fa-bars"></i>
    </button>
    <span class="topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
  </div>
  <div class="topbar-right">
    <div class="clock" id="topClock"></div>
    <div class="user-chip">
      <div class="chip-av" style="background:<?= $avBg ?>"><?= $initials ?></div>
      <div>
        <div class="chip-name"><?= $fullName ?></div>
        <div class="chip-role <?= $roleBadgeClass ?>"><?= htmlspecialchars($user['role']) ?></div>
      </div>
    </div>
  </div>
</header>
<?php
}
