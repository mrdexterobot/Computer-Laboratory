<?php
// ============================================
// COMLAB - Auth & Role Configuration (NEW)
// Revision: Technician role removed.
//   Roles: Administrator, Faculty only.
//   Users synced from HR System.
//   Admin owns all operations.
//   Faculty: view schedule + check-in + requests.
// ============================================

define('SESSION_TIMEOUT', 1800);
define('SESSION_NAME',    'comlab_sess');

// Role constants — Technician removed
define('ROLE_ADMIN',   'Administrator');
define('ROLE_FACULTY', 'Faculty');

/**
 * Module → page file mapping.
 * 'scheduling' = Admin assigns / Faculty views schedule (recurring).
 * 'attendance' = Faculty check-in; Admin views presence summary.
 */
define('MODULES', [
    'dashboard'  => ['label' => 'Dashboard',            'icon' => 'fas fa-chart-pie',       'file' => 'dashboard'],
    'users'      => ['label' => 'User Management',      'icon' => 'fas fa-users',            'file' => 'users'],
    'devices'    => ['label' => 'Devices',              'icon' => 'fas fa-desktop',          'file' => 'devices'],
    'locations'  => ['label' => 'Lab Locations',        'icon' => 'fas fa-map-marker-alt',   'file' => 'locations'],
    'scheduling' => ['label' => 'HR Faculty Schedules', 'icon' => 'fas fa-calendar-check',   'file' => 'scheduling'],
    'attendance' => ['label' => 'Attendance',           'icon' => 'fas fa-user-clock',       'file' => 'attendance'],
    'requests'   => ['label' => 'Requests',             'icon' => 'fas fa-clipboard-list',   'file' => 'requests'],
    'inventory'  => ['label' => 'Inventory',            'icon' => 'fas fa-boxes',            'file' => 'inventory'],
    'integration'=> ['label' => 'Integration Hub',      'icon' => 'fas fa-share-nodes',      'file' => 'integration'],
    'logs'       => ['label' => 'Audit Logs',           'icon' => 'fas fa-history',          'file' => 'logs'],
]);

/**
 * Nav group structure for sidebar.
 */
define('NAV_GROUPS', [
    'Main'       => ['dashboard'],
    'Management' => ['users', 'devices', 'locations'],
    'Operations' => ['scheduling', 'attendance', 'requests'],
    'Integration' => ['integration'],
    'Reports'    => ['inventory', 'logs'],
]);

/**
 * Role → allowed modules.
 *
 * Administrator : all modules.
 * Faculty       : dashboard (read-only), scheduling (view own), attendance (check-in), requests (own).
 */
define('ROLE_PERMISSIONS', [
    ROLE_ADMIN => [
        'dashboard', 'users', 'devices', 'locations',
        'scheduling', 'attendance', 'requests', 'inventory', 'integration', 'logs',
    ],
    ROLE_FACULTY => [
        'dashboard', 'scheduling', 'attendance', 'requests',
    ],
]);

/**
 * Check if a role has access to a module.
 */
function hasAccess(string $module, string $role): bool {
    return isset(ROLE_PERMISSIONS[$role]) && in_array($module, ROLE_PERMISSIONS[$role], true);
}

/**
 * Get allowed modules for a role.
 */
function getAllowedModules(string $role): array {
    return ROLE_PERMISSIONS[$role] ?? [];
}
