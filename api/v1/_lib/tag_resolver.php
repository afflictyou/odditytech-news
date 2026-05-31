<?php
declare(strict_types=1);

// Tag alias resolver — SIG-181.
//
// Loads the canonical-vocabulary map from config/canonical_tags.json (relative
// to public_html), normalizes free-form tag input to slug form, and resolves
// aliases to their canonical slug. Tags with no mapping pass through unchanged
// so the long tail is preserved.
//
// The resolver is shared between POST /api/v1/headlines/ (write-time
// normalization) and GET /api/v1/tags (vocabulary introspection).

function tag_config_path(): string
{
    // Try DOCUMENT_ROOT first (web requests), then walk up from this file (CLI).
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot !== '') {
        $p = rtrim($docRoot, '/\\') . '/config/canonical_tags.json';
        if (is_readable($p)) return $p;
    }
    return dirname(__DIR__, 3) . '/config/canonical_tags.json';
}

function tag_load_config(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $path = tag_config_path();
    if (!is_readable($path)) {
        // Fail open: if config is missing, behave as if no aliases are defined.
        $cache = ['canonical_tags' => [], 'aliases' => []];
        return $cache;
    }
    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache = ['canonical_tags' => [], 'aliases' => []];
        return $cache;
    }
    $cache = [
        'canonical_tags' => $decoded['canonical_tags'] ?? [],
        'aliases'        => is_array($decoded['aliases'] ?? null) ? $decoded['aliases'] : [],
    ];
    return $cache;
}

// Normalize a free-form tag string into the slug form used as a map key.
// Matches the normalize_tag() helper in public_html/index.php so the API and
// the renderer agree on what counts as "the same tag".
function tag_slugify(string $tag): string
{
    $t = strtolower(trim($tag));
    $t = preg_replace('/\s+/', '-', $t) ?? $t;
    $t = preg_replace('/[^a-z0-9\-]/', '', $t) ?? $t;
    $t = preg_replace('/-+/', '-', $t) ?? $t;
    return trim($t, '-');
}

// Resolve a single tag slug to its canonical (or to itself if unmapped).
function tag_resolve_one(string $slug): string
{
    $cfg = tag_load_config();
    return $cfg['aliases'][$slug] ?? $slug;
}

// Normalize a tag list (array of free-form strings, or comma-separated string)
// to a deduplicated list of canonical/passthrough slugs, preserving first-seen
// order. Empty entries are dropped.
function tag_normalize_list($tags): array
{
    if ($tags === null || $tags === '') return [];
    if (is_string($tags)) {
        $parts = explode(',', $tags);
    } elseif (is_array($tags)) {
        $parts = $tags;
    } else {
        return [];
    }

    $seen = [];
    $out  = [];
    foreach ($parts as $raw) {
        if (!is_string($raw) && !is_int($raw)) continue;
        $slug = tag_slugify((string)$raw);
        if ($slug === '') continue;
        $canon = tag_resolve_one($slug);
        if ($canon === '' || isset($seen[$canon])) continue;
        $seen[$canon] = true;
        $out[] = $canon;
    }
    return $out;
}
