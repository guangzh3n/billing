<?php
$current_page = $current_page ?? '';
$page_title   = $page_title ?? APP_NAME;

function nav_active(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sanitize($page_title) ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<!-- Sidebar -->
<nav id="sidebar" class="sidebar d-flex flex-column">
  <div class="sidebar-header">
    <span class="fs-5 fw-bold text-white"><?= APP_NAME ?></span>
    <span class="text-muted small ms-1">v<?= APP_VERSION ?></span>
  </div>
  <div class="sidebar-nav flex-grow-1 overflow-auto">
    <a href="/dashboard" class="nav-link <?= nav_active('dashboard',$current_page) ?>">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>
    <div class="nav-section">SALES</div>
    <a href="/invoices" class="nav-link <?= nav_active('invoices',$current_page) ?>">
      <i class="bi bi-receipt"></i> Invoices
    </a>
    <a href="/payments" class="nav-link <?= nav_active('payments',$current_page) ?>">
      <i class="bi bi-cash-coin"></i> Payments
    </a>
    <div class="nav-section">RECORDS</div>
    <a href="/customers" class="nav-link <?= nav_active('customers',$current_page) ?>">
      <i class="bi bi-people"></i> Customers
    </a>
    <a href="/products" class="nav-link <?= nav_active('products',$current_page) ?>">
      <i class="bi bi-box-seam"></i> Products &amp; Services
    </a>
    <div class="nav-section">ANALYTICS</div>
    <a href="/reports" class="nav-link <?= nav_active('reports',$current_page) ?>">
      <i class="bi bi-bar-chart-line"></i> Reports
    </a>
    <div class="nav-section">SYSTEM</div>
    <a href="/settings" class="nav-link <?= nav_active('settings',$current_page) ?>">
      <i class="bi bi-gear"></i> Settings
    </a>
  </div>
  <div class="sidebar-footer">
    <div class="small text-muted"><?= sanitize(current_user()['name'] ?? '') ?></div>
    <a href="/logout.php" class="nav-link text-danger mt-1"><i class="bi bi-box-arrow-left"></i> Logout</a>
  </div>
</nav>

<!-- Main content -->
<div class="main-content">
  <!-- Top bar -->
  <header class="topbar d-flex align-items-center justify-content-between px-4 py-2 bg-white border-bottom">
    <button class="btn btn-sm btn-outline-secondary d-md-none" id="sidebar-toggle">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h5 class="mb-0 fw-semibold"><?= sanitize($page_title) ?></h5>
    <div class="d-flex align-items-center gap-3">
      <a href="/invoice/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> New Invoice
      </a>
      <div class="dropdown">
        <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle"></i> <?= sanitize(current_user()['name'] ?? 'User') ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="/settings">Settings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="/logout.php">Logout</a></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- Flash messages -->
  <div class="px-4 pt-3">
  <?php foreach (['success','error','warning','info'] as $fk):
    $f = get_flash($fk);
    if ($f): ?>
    <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : $f['type'] ?> alert-dismissible fade show" role="alert">
      <?= sanitize($f['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; endforeach; ?>
  </div>

  <div class="page-content px-4 py-3">
