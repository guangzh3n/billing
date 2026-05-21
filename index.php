<?php
// Redirect to installer if not set up
if (!file_exists(__DIR__ . '/install/.installed')) {
    header('Location: /install/index.php');
    exit;
}

require_once __DIR__ . '/includes/init.php';
require_login();

$raw_page = trim($_GET['page'] ?? '', '/');
// Normalize trailing slashes and query params already stripped by .htaccess

$routes = [
    ''                => 'pages/dashboard.php',
    'dashboard'       => 'pages/dashboard.php',
    'customers'       => 'pages/customers.php',
    'products'        => 'pages/products.php',
    'invoices'        => 'pages/invoices.php',
    'invoice/create'  => 'pages/invoice_create.php',
    'invoice/view'    => 'pages/invoice_view.php',
    'invoice/edit'    => 'pages/invoice_edit.php',
    'invoice/print'   => 'pages/invoice_print.php',
    'payments'        => 'pages/payments.php',
    'reports'         => 'pages/reports.php',
    'settings'        => 'pages/settings.php',
    'einvoice/submit' => 'pages/einvoice_submit.php',
    'einvoice/status' => 'pages/einvoice_status.php',
];

$file = $routes[$raw_page] ?? null;

if ($file && file_exists(__DIR__ . '/' . $file)) {
    require __DIR__ . '/' . $file;
} else {
    http_response_code(404);
    require __DIR__ . '/pages/404.php';
}
