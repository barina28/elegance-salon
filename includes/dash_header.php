<?php
/**
 * DASHBOARD HEADER - role-aware sidebar + topbar.
 * Usage at the top of a dashboard page:
 *     $page_title = 'Clients';
 *     require_once __DIR__ . '/../includes/dash_header.php';
 */
require_once __DIR__ . '/init.php';
require_login();

$page_title = isset($page_title) ? $page_title : 'Dashboard';
$u   = current_user();

/* Current script relative to BASE_URL, e.g. 'modules/dashboard/index.php'
   (used to highlight the active menu item) */
$base_path  = parse_url(BASE_URL, PHP_URL_PATH);
$script_rel = substr($_SERVER['SCRIPT_NAME'], strlen($base_path));

/* ------------------------------------------------------------
 * ROLE-AWARE MENU
 * New modules unlock in later steps: flip 'ready' to true
 * when we build that module (Step number is shown meanwhile).
 * ---------------------------------------------------------- */
$menu = array(
    array('label' => 'Dashboard',    'icon' => 'bi-speedometer2',    'url' => dashboard_url(),                             'show' => true,                        'ready' => true),
    array('label' => 'Appointments', 'icon' => 'bi-calendar2-check', 'url' => BASE_URL . 'modules/appointments/index.php', 'show' => can_manage('appointments'),  'ready' => true),
    array('label' => 'Calendar',     'icon' => 'bi-calendar3',       'url' => BASE_URL . 'modules/calendar/index.php',     'show' => can_manage('appointments'),  'ready' => true),
    array('label' => 'Services',     'icon' => 'bi-scissors',        'url' => BASE_URL . 'modules/admin/services.php',     'show' => can_manage('services'),      'ready' => true),
    array('label' => 'Clients',      'icon' => 'bi-people',          'url' => BASE_URL . 'modules/clients/index.php',      'show' => can_manage('clients'),       'ready' => true),
    array('label' => 'Staff',        'icon' => 'bi-person-badge',    'url' => BASE_URL . 'modules/staff/index.php',        'show' => can_manage('staff'),         'ready' => true),
    array('label' => 'Inventory',    'icon' => 'bi-box-seam',        'url' => BASE_URL . 'modules/inventory/index.php',    'show' => can_manage('inventory'),     'ready' => true),
    array('label' => 'Payments',     'icon' => 'bi-receipt',         'url' => BASE_URL . 'modules/payments/index.php',     'show' => can_manage('payments'),      'ready' => true),
    array('label' => 'Reports',      'icon' => 'bi-graph-up',        'url' => BASE_URL . 'modules/reports/index.php',      'show' => can_manage('reports'),       'ready' => true),
    array('label' => 'Notifications','icon' => 'bi-bell',            'url' => BASE_URL . 'modules/notifications/index.php','show' => can_manage('notifications'),'ready' => true),
    array('label' => 'Users',        'icon' => 'bi-person-gear',     'url' => BASE_URL . 'modules/admin/users.php',        'show' => can_manage('users'),         'ready' => true),
    array('label' => 'Feedback',     'icon' => 'bi-chat-heart',      'url' => BASE_URL . 'modules/admin/feedback.php',     'show' => can_manage('feedback'),      'ready' => true),
    array('label' => 'Settings',     'icon' => 'bi-gear',            'url' => BASE_URL . 'modules/admin/settings.php',     'show' => can_manage('settings'),      'ready' => true),
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> - <?= e(get_setting('salon_name', SITE_NAME)) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body>

<aside class="dash-sidebar">
  <a class="brand d-flex align-items-center gap-2 text-decoration-none mb-4" href="<?= dashboard_url() ?>">
    <span class="brand-badge"><i class="bi bi-scissors"></i></span>
    <span class="brand-name small"><?= e(get_setting('salon_name', SITE_NAME)) ?></span>
  </a>

  <ul class="nav flex-column gap-1">
    <?php foreach ($menu as $m_item): ?>
      <?php if (!$m_item['show']) { continue; } ?>
      <?php if ($m_item['ready']): ?>
        <li class="nav-item">
          <a class="nav-link <?= substr($m_item['url'], strlen(BASE_URL)) === $script_rel ? 'active' : '' ?>" href="<?= $m_item['url'] ?>">
            <i class="bi <?= $m_item['icon'] ?>"></i> <?= e($m_item['label']) ?>
          </a>
        </li>
      <?php else: ?>
        <li class="nav-item">
          <span class="nav-link disabled" title="Unlocks in Step <?= (int)$m_item['step'] ?>">
            <i class="bi <?= $m_item['icon'] ?>"></i> <?= e($m_item['label']) ?>
            <span class="menu-soon">Step <?= (int)$m_item['step'] ?></span>
          </span>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php unset($m_item); ?>
  </ul>
</aside>

<div class="dash-main">
  <div class="dash-topbar">
    <h5 class="mb-0 fw-semibold"><?= e($page_title) ?></h5>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="badge badge-role"><?= e(ucfirst($u['role'])) ?></span>
      <a class="text-decoration-none" href="<?= BASE_URL ?>modules/auth/profile.php" title="My profile / change password">
        <i class="bi bi-person-circle"></i> <?= e($u['name']) ?>
      </a>
      <a class="text-decoration-none" href="<?= BASE_URL ?>index.php" title="View public site"><i class="bi bi-globe2"></i></a>
      <a class="text-danger text-decoration-none" href="<?= BASE_URL ?>modules/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
  </div>
  <?php show_flash(); ?>
