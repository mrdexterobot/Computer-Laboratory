<?php
// COMLAB - Sidebar Partial

function renderSidebar(array $user, string $currentModule = 'dashboard'): void {
    $role    = $user['role'];
    $allowed = getAllowedModules($role);
    $modules = MODULES;
    $groups  = NAV_GROUPS;
    $base    = getBasePath();
    $initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
    $roleBadgeClass = ($role === 'Administrator') ? 'role-badge-admin' : 'role-badge-faculty';
    $avBg = ($role === 'Administrator') ? '#d97706' : '#0d9488';
    ?>
<aside class="sidebar" id="sidebar">

  <div class="sidebar-brand">
    <div class="brand-logo">
      <div class="brand-icon"><i class="fas fa-laptop-code"></i></div>
      <div>
        <h2>COMLAB</h2>
        <p>Laboratory Management</p>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($groups as $groupLabel => $groupModules): ?>
      <?php $visible = array_intersect($groupModules, $allowed); if (empty($visible)) continue; ?>
      <div class="nav-section">
        <?php if ($groupLabel !== 'Main'): ?>
          <div class="nav-section-label"><?= htmlspecialchars($groupLabel) ?></div>
        <?php endif; ?>
        <?php foreach ($groupModules as $key): ?>
          <?php if (!in_array($key, $allowed, true)) continue; $mod = $modules[$key]; ?>
          <a href="<?= $base.$mod['file'].'.php' ?>"
             class="nav-link <?= $currentModule === $key ? 'active' : '' ?>">
            <span class="nav-icon"><i class="<?= $mod['icon'] ?>"></i></span>
            <?= htmlspecialchars($mod['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar sidebar-avatar" style="background:<?= $avBg ?>"><?= $initials ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></div>
        <div class="sidebar-user-role <?= $roleBadgeClass ?>"><?= htmlspecialchars($role) ?></div>
      </div>
    </div>
    <a href="<?= $base ?>api/auth/logout.php"
       class="btn btn-ghost btn-sm logout-btn"
       onclick="return confirm('Sign out of COMLAB?')">
      <i class="fas fa-right-from-bracket"></i> Sign Out
    </a>
  </div>

</aside>
    <?php
}
