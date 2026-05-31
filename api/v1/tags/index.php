<?php
declare(strict_types=1);

// GET /api/v1/tags — SIG-181.
//
// Returns the canonical tag vocabulary so the SSCI Digest Editor (and any
// other downstream consumer) can introspect it programmatically. Public:
// no auth — the vocabulary is the same data already published in
// docs/canonical_tags.md, so there is nothing to gate.
//
// Query params:
//   include_aliases=1  -- include the array of aliases that resolve to each canonical.

require_once __DIR__ . '/../_lib/tag_resolver.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$cfg = tag_load_config();
$canonicals = $cfg['canonical_tags'];
$aliases    = $cfg['aliases'];

$includeAliases = isset($_GET['include_aliases']) && $_GET['include_aliases'] !== '' && $_GET['include_aliases'] !== '0';

if ($includeAliases) {
    // Group alias slugs by their canonical target.
    $aliasesByCanonical = [];
    foreach ($aliases as $alias => $canonical) {
        $aliasesByCanonical[$canonical][] = $alias;
    }
    foreach ($aliasesByCanonical as &$list) {
        sort($list);
    }
    unset($list);

    foreach ($canonicals as &$entry) {
        $slug = $entry['slug'] ?? '';
        $entry['aliases'] = $aliasesByCanonical[$slug] ?? [];
    }
    unset($entry);
}

http_response_code(200);
echo json_encode([
    'canonical_tags' => $canonicals,
    'count'          => count($canonicals),
], JSON_UNESCAPED_SLASHES);
