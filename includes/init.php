<?php
// Redirect to installer if not yet set up
if (!file_exists(__DIR__ . '/../config/database.php') || !file_exists(__DIR__ . '/../install/.installed')) {
    header('Location: /install/index.php');
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set(TIMEZONE);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
session_name(SESSION_NAME);
session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PDO connection (global for simplicity in procedural pages)
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die('<h3 style="font-family:sans-serif;color:red">Database connection error. Please check config/database.php.<br><small>' . htmlspecialchars($e->getMessage()) . '</small></h3>');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
