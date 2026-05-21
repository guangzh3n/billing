<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect('/dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($email, $password, $pdo)) {
        redirect('/dashboard');
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 100vh; }
  .login-card { max-width: 400px; margin: 100px auto; }
  .brand-icon { font-size: 2.5rem; color: #0d6efd; }
</style>
</head>
<body>
<div class="login-card">
  <div class="text-center mb-4">
    <i class="bi bi-receipt-cutoff brand-icon"></i>
    <h3 class="text-white fw-bold mt-2"><?= APP_NAME ?></h3>
    <p class="text-secondary">Malaysia Billing &amp; e-Invoice System</p>
  </div>
  <div class="card shadow-lg border-0">
    <div class="card-body p-4">
      <?php if ($error): ?>
      <div class="alert alert-danger"><?= sanitize($error) ?></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <div class="mb-3">
          <label class="form-label fw-semibold">Email Address</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" class="form-control" placeholder="admin@example.com"
                   value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
          <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
        </button>
      </form>
    </div>
  </div>
  <p class="text-center text-secondary small mt-3"><?= APP_NAME ?> v<?= APP_VERSION ?></p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
