<?php
/**
 * PUBLIC SITE HEADER  (Home, Contact Us, Feedback, ...)
 * Usage at the top of a public page:
 *     $page_title = 'Home';
 *     require_once __DIR__ . '/includes/header.php';
 */
require_once __DIR__ . '/init.php';

$page_title = isset($page_title) ? $page_title : get_setting('salon_name', SITE_NAME);
$here = basename($_SERVER['PHP_SELF']);
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

<nav class="navbar navbar-expand-lg salon-navbar">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>index.php">
      <span class="brand-badge"><i class="bi bi-scissors"></i></span>
      <span class="brand-name"><?= e(get_setting('salon_name', SITE_NAME)) ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item"><a class="nav-link <?= $here === 'index.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>index.php">Home</a></li>
        <li class="nav-item"><a class="nav-link <?= $here === 'contact.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>contact.php">Book Appointment</a></li>
        <li class="nav-item"><a class="nav-link <?= $here === 'feedback.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>feedback.php">Submit Feedback</a></li>
        <?php if (is_logged_in()): ?>
          <li class="nav-item"><a class="btn btn-salon btn-sm ms-lg-2" href="<?= dashboard_url() ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>modules/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-salon btn-sm ms-lg-2" href="<?= BASE_URL ?>modules/auth/login.php"><i class="bi bi-box-arrow-in-right"></i> Staff Login</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main class="container py-4">
<?php show_flash(); ?>
