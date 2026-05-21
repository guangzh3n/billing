<?php
$current_page = '';
$page_title   = 'Page Not Found';
if (function_exists('require_login')) {
    // Already inside front controller with session
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="text-center py-5">
      <div class="display-1 text-muted">404</div>
      <h3>Page Not Found</h3>
      <p class="text-muted">The page you\'re looking for doesn\'t exist.</p>
      <a href="/dashboard" class="btn btn-primary">Go to Dashboard</a>
    </div>';
    require_once __DIR__ . '/../includes/footer.php';
} else {
    echo '<!DOCTYPE html><html><head><title>404</title></head><body>
    <h2>404 — Page Not Found</h2><a href="/">Go to Home</a></body></html>';
}
