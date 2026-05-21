<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$env    = get_setting('einvoice_env','sandbox');
$cid    = get_setting('einvoice_client_id');
$secret = get_setting('einvoice_client_secret');

if (!$cid || !$secret) {
    echo json_encode(['success' => false, 'error' => 'Client ID and Secret are not configured.']);
    exit;
}

require_once __DIR__ . '/../classes/MyInvoisAPI.php';

// Clear cached token to force re-auth
unset($_SESSION['myinvois_token'], $_SESSION['myinvois_token_expiry']);

$api = new MyInvoisAPI($env, $cid, $secret);
if ($api->authenticate()) {
    echo json_encode(['success' => true, 'env' => $env, 'base_url' => $api->getBaseUrl()]);
} else {
    echo json_encode(['success' => false, 'error' => $api->getLastError()]);
}
