<?php
// SIG-252: deploy-time OPcache reset endpoint.
//
// LSAPI on shared LiteSpeed hosts caches PHP bytecode across requests. When a
// fresh deploy replaces api/v1/*.php on disk, running workers may keep serving
// the previous bytecode until OPcache notices the file change. This endpoint
// lets the deploy workflow (or an operator) force a full OPcache reset
// immediately after the FTPS sync completes.
//
// Auth: same INGEST_API_KEY as the rest of /api/v1/*. Side-effect only -
// no DB access, no business data exposed.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// Load .env from one level above public_html (same convention as other endpoints).
$envPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $_ENV[$k] = $v;
    }
}

$apiKey = $_ENV['INGEST_API_KEY'] ?? '';
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$apiKey || !hash_equals($apiKey, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$result = [
    'opcache_available' => function_exists('opcache_reset'),
    'reset'             => false,
    'php_version'       => PHP_VERSION,
];

if (function_exists('opcache_reset')) {
    $result['reset'] = (bool)@opcache_reset();
}

if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    if (is_array($status)) {
        $result['opcache_enabled']        = $status['opcache_enabled'] ?? null;
        $result['cached_scripts']         = $status['opcache_statistics']['num_cached_scripts'] ?? null;
        $result['last_restart_time']      = $status['opcache_statistics']['last_restart_time'] ?? null;
    }
}

http_response_code(200);
echo json_encode($result);
