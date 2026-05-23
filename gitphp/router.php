<?php
// Router for PHP built-in server: php -S localhost:8080 router.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block sensitive paths
$blocked = ['repos', 'uploads', 'cache', 'includes', 'gitphp.db'];
$first = explode('/', ltrim($uri, '/'))[0];
if (in_array($first, $blocked)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

// Serve real files (CSS, JS, images, etc.)
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false;
}

// Everything else → index.php
require __DIR__ . '/index.php';
