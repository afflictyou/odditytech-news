<?php
// -----------------------------------------------------------------------------
// digest/_partials/bootstrap.php (SIG-178)
//
// Shared bootstrap for digest/index.php and digest/show.php:
//   * loads the project .env (same pattern as root index.php and the API),
//   * resolves the digests API base URL,
//   * exposes helpers for HTTP calls into the API,
//   * exposes a `digest_escape` shorthand mirroring the rest of the codebase,
//   * provides preview-auth detection used by show.php.
//
// PHP 7.1+ compatible.
// -----------------------------------------------------------------------------

declare(strict_types=1);

// --- Load .env (same pattern as the rest of the codebase) ------------------
$__envPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/.env';
if (file_exists($__envPath)) {
    foreach (file($__envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        $__line = trim($__line);
        if ($__line === '' || strpos($__line, '#') === 0) continue;
        if (strpos($__line, '=') === false) continue;
        list($__k, $__v) = array_map('trim', explode('=', $__line, 2));
        if ($__k === '') continue;
        $_ENV[$__k] = $__v;
    }
}
unset($__envPath, $__line, $__k, $__v);

// --- Composer autoload (league/commonmark + transitive deps) ---------------
// vendor/ lives at the document root, installed by CI before each FTPS sync.
// On a fresh checkout without vendor/, autoload is skipped; show.php will then
// fall back to escaped plain text, which is ugly but safe (no PHP error).
$__autoload = $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
if (file_exists($__autoload)) {
    require_once $__autoload;
}
unset($__autoload);

// --- API base URL ----------------------------------------------------------
// On prod this is always the same host the digest pages are served from, so
// we resolve it relative to the current request rather than hard-coding the
// public hostname. Falls back to https://odditytech.news for CLI smoke runs
// where $_SERVER['HTTP_HOST'] is empty.
function digest_api_base(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'odditytech.news';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $host . '/api/v1/digests';
}

// --- HTML escape -----------------------------------------------------------
function digest_escape($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- Preview auth detection ------------------------------------------------
// SIG-178 acceptance: `?preview=draft` must call the API with auth so the CEO
// can see drafts before publish. We accept the same X-API-Key the API uses,
// passed via HTTP Basic Auth (username can be anything; password is the key).
// This way the CEO bookmarks `/digest/<slug>?preview=draft`, the browser
// prompts for credentials once per session, and nothing leaks to anonymous
// link-sharers.
function digest_preview_requested(): bool {
    return isset($_GET['preview']) && $_GET['preview'] === 'draft';
}

function digest_preview_api_key(): string {
    // Browser-supplied basic auth.
    $pw = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($pw !== '') return $pw;
    // CLI / curl path: explicit X-API-Key header survives htaccess too.
    return $_SERVER['HTTP_X_API_KEY'] ?? '';
}

function digest_preview_authed(): bool {
    if (!digest_preview_requested()) return false;
    $expected = $_ENV['INGEST_API_KEY'] ?? '';
    $supplied = digest_preview_api_key();
    return $expected !== '' && $supplied !== '' && hash_equals($expected, $supplied);
}

function digest_preview_challenge(): void {
    header('WWW-Authenticate: Basic realm="odditytech.news draft preview", charset="UTF-8"');
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Auth required</title></head><body style="font-family:monospace;background:#08080c;color:#e6e6f0;padding:40px;">';
    echo '<h1>401 — preview auth required</h1>';
    echo '<p>Drafts are only visible to authenticated reviewers.</p>';
    echo '</body></html>';
    exit;
}

// --- HTTP helper -----------------------------------------------------------
// Returns [http_status, decoded_body_or_null, raw_body_string].
function digest_api_get(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_USERAGENT      => 'odditytech-digest/1.0',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        // Network error: treat as 502 upstream so the page can render an error.
        return [502, null, $err];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) $decoded = null;
    return [$status, $decoded, (string)$raw];
}

// --- Array helpers ---------------------------------------------------------
// PHP 8.1 ships array_is_list(); we polyfill it for the PHP 7.4 floor declared
// in composer.json so digest pages don't break on older shared-hosting PHPs.
function digest_array_is_list(array $arr): bool {
    if ($arr === []) return true;
    $i = 0;
    foreach ($arr as $k => $_) { if ($k !== $i++) return false; }
    return true;
}

// --- Date formatting -------------------------------------------------------
function digest_format_date(?string $iso): string {
    if ($iso === null || $iso === '') return '';
    $ts = strtotime($iso);
    if ($ts === false) return '';
    return date('F j, Y', $ts);
}
