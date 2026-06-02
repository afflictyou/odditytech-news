<?php
// SIG-252 deploy probe. Public, intentionally trivial - serves as a check
// that FTPS sync is placing api/v1/*.php files where the web server expects
// them. Returns plain text and the file mtime so we can spot stale deploys
// without authenticating.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
echo json_encode([
    'ok'           => true,
    'service'      => 'odditytech.news/api/v1',
    'file_mtime'   => @filemtime(__FILE__),
    'php_version'  => PHP_VERSION,
    'opcache_on'   => function_exists('opcache_get_status') ? (bool)@opcache_get_status(false)['opcache_enabled'] : null,
]);
// Test comment
