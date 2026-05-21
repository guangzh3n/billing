<?php
// Block if already installed
if (file_exists(__DIR__ . '/.installed')) {
    header('Location: ../index.php');
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$errors = [];
$success = false;

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_port = trim($_POST['db_port'] ?? '3306');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';

    if (!$db_name) $errors[] = 'Database name is required.';
    if (!$db_user) $errors[] = 'Database username is required.';

    if (!$errors) {
        try {
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Run schema
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            $pdo->exec($sql);

            // Write config
            $config = "<?php\ndefine('DB_HOST', " . var_export($db_host, true) . ");\n"
                . "define('DB_PORT', " . var_export($db_port, true) . ");\n"
                . "define('DB_NAME', " . var_export($db_name, true) . ");\n"
                . "define('DB_USER', " . var_export($db_user, true) . ");\n"
                . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n";

            file_put_contents(__DIR__ . '/../config/database.php', $config);

            // Store in session for step 2
            session_start();
            $_SESSION['install_db'] = compact('db_host','db_port','db_name','db_user','db_pass');
            header('Location: index.php?step=2');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }
}

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $name  = trim($_POST['admin_name'] ?? '');
    $email = trim($_POST['admin_email'] ?? '');
    $pass  = $_POST['admin_password'] ?? '';
    $pass2 = $_POST['admin_password2'] ?? '';

    if (!$name)  $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== $pass2)  $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $db = $_SESSION['install_db'];
        try {
            $dsn = "mysql:host={$db['db_host']};port={$db['db_port']};dbname={$db['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['db_user'], $db['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([$name, $email, $hash]);
            file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));
            header('Location: index.php?step=3');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to create admin: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BillingPro — Installer</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#f0f2f5;} .install-card{max-width:520px;margin:60px auto;}</style>
</head>
<body>
<div class="install-card">
  <div class="text-center mb-4">
    <h2 class="fw-bold text-primary">BillingPro</h2>
    <p class="text-muted">Installation Wizard</p>
  </div>

  <div class="d-flex justify-content-center mb-4 gap-2">
    <?php foreach ([1=>'Database',2=>'Admin Account',3=>'Complete'] as $s=>$label): ?>
    <div class="text-center">
      <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold"
           style="width:36px;height:36px;background:<?= $step>=$s?'#0d6efd':'#dee2e6' ?>;color:<?= $step>=$s?'#fff':'#6c757d' ?>">
        <?= $s ?>
      </div>
      <div class="small mt-1 <?= $step===$s?'fw-bold text-primary':'text-muted' ?>"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h5 class="mb-3">Database Configuration</h5>
      <form method="post">
        <div class="mb-3"><label class="form-label">Database Host</label>
          <input name="db_host" class="form-control" value="localhost"></div>
        <div class="mb-3"><label class="form-label">Port</label>
          <input name="db_port" class="form-control" value="3306"></div>
        <div class="mb-3"><label class="form-label">Database Name <span class="text-danger">*</span></label>
          <input name="db_name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Username <span class="text-danger">*</span></label>
          <input name="db_user" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Password</label>
          <input name="db_pass" type="password" class="form-control"></div>
        <button class="btn btn-primary w-100">Next: Admin Account &rarr;</button>
      </form>
    </div>
  </div>

  <?php elseif ($step === 2): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h5 class="mb-3">Create Admin Account</h5>
      <form method="post">
        <div class="mb-3"><label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input name="admin_name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label>
          <input name="admin_email" type="email" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Password <span class="text-danger">*</span></label>
          <input name="admin_password" type="password" class="form-control" required minlength="8">
          <div class="form-text">Minimum 8 characters.</div></div>
        <div class="mb-3"><label class="form-label">Confirm Password <span class="text-danger">*</span></label>
          <input name="admin_password2" type="password" class="form-control" required></div>
        <button class="btn btn-primary w-100">Create Account &amp; Finish</button>
      </form>
    </div>
  </div>

  <?php else: ?>
  <div class="card shadow-sm border-success">
    <div class="card-body p-4 text-center">
      <div class="text-success fs-1 mb-3">&#10003;</div>
      <h5>Installation Complete!</h5>
      <p class="text-muted">BillingPro has been installed successfully.</p>
      <a href="../index.php" class="btn btn-success">Go to Login &rarr;</a>
      <hr>
      <div class="alert alert-warning text-start small">
        <strong>Security:</strong> Delete or rename the <code>install/</code> folder to prevent re-installation.<br>
        <strong>Permissions:</strong> Set directories to <code>755</code> and files to <code>644</code> via cPanel File Manager.<br>
        <strong>Logs:</strong> Ensure the <code>logs/</code> directory is writable (<code>755</code>).
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
