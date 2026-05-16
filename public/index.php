<?php
declare(strict_types=1);

use Kaspi\Bootstrap;
use Kaspi\Http;
use Kaspi\Routes\Account;
use Kaspi\Routes\Auth;
use Kaspi\Routes\Orders;
use Kaspi\Routes\Pay;
use Kaspi\Routes\Products;
use Kaspi\Routes\Sessions;
use Kaspi\Routes\Store;

$root = dirname(__DIR__);
require $root . '/src/Bootstrap.php';
Bootstrap::init($root);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// CORS (relax — UI served from same origin, but harmless)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Session-Id, X-Cashier-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// Health
if ($path === '/health') Http::json(['status' => 'ok']);

// Static UI (when running via `php -S`)
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/public/index.html');
    exit;
}
if ($path === '/app.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($root . '/public/app.js');
    exit;
}

// User uploads (product images) — served from data/uploads/
if (preg_match('#^/uploads/([a-zA-Z0-9_./-]+)$#', $path, $m)) {
    $rel = $m[1];
    if (str_contains($rel, '..')) Http::error('Forbidden', 403);
    $file = Kaspi\Config::$dataDir . '/uploads/' . $rel;
    if (!is_file($file)) Http::error('Not Found', 404);
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000');
    readfile($file);
    exit;
}

// Static assets (CSS, JS, images under /assets/)
if (preg_match('#^/assets/([a-zA-Z0-9_./-]+)$#', $path, $m)) {
    $rel = $m[1];
    if (str_contains($rel, '..')) Http::error('Forbidden', 403);
    $file = $root . '/public/assets/' . $rel;
    if (!is_file($file)) Http::error('Not Found', 404);
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}

// Swagger UI + OpenAPI spec
if ($path === '/docs' || $path === '/docs/' || $path === '/docs/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/public/docs.html');
    exit;
}
if ($path === '/docs/openapi.yaml') {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile($root . '/docs/openapi.yaml');
    exit;
}

// Public storefront page: /s/<username>
if (preg_match('#^/s/([a-zA-Z0-9_.@-]{3,64})/?$#', $path, $m)) {
    // Validate shop exists before serving HTML
    $shop = \Kaspi\User::findByUsername($m[1]);
    if (!$shop) Http::error('Shop not found', 404);
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/public/store.html');
    exit;
}
if ($path === '/store.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($root . '/public/store.js');
    exit;
}

// Public storefront API: /api/store/<username>/<sub>
if (preg_match('#^/api/store/([a-zA-Z0-9_.@-]{3,64})(?:/([a-z-]+))?/?$#i', $path, $m)) {
    $_GET['shop'] = $m[1];
    $sub = $m[2] ?? '';
    Store::dispatch($sub, $method);
}

// API routes: /api/{section}/{sub}
if (preg_match('#^/api/([a-z\-]+)/([a-z\-]+)/?$#i', $path, $m)) {
    [$_, $section, $sub] = $m;
    match ($section) {
        'account'  => Account::dispatch($sub, $method),
        'sessions' => Sessions::dispatch($sub, $method),
        'auth'     => Auth::dispatch($sub, $method),
        'pay'      => Pay::dispatch($sub, $method),
        'products' => Products::dispatch($sub, $method),
        'orders'   => Orders::dispatch($sub, $method),
        default    => Http::error('Unknown section', 404),
    };
}

Http::error('Not Found', 404);
