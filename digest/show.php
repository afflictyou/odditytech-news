<?php
// -----------------------------------------------------------------------------
// digest/show.php (SIG-178)
//
// Single-digest renderer. Reached via .htaccess rewrite:
//   /digest/<slug> -> digest/show.php?slug=<slug>
//
// Renders the markdown `body_markdown` field from the digests API as HTML
// through league/commonmark in **safe** mode (no raw HTML, no unsafe links,
// no javascript: URIs). Draft preview is gated by HTTP Basic Auth where the
// password must match $_ENV['INGEST_API_KEY'] — same surface the API uses.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/_partials/bootstrap.php';

// --- Slug validation -------------------------------------------------------
$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
// Defence-in-depth: the .htaccess rewrite already restricts the URL pattern to
// [A-Za-z0-9_-]+, but show.php may be hit directly via ?slug= so we re-check.
if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
    http_response_code(404);
    $page_title = 'Not found';
    $page_meta  = '';
    $section    = 'digest';
    include __DIR__ . '/_partials/layout_open.php';
    echo '<main><div class="digest-container"><section class="digest-empty">';
    echo '<div class="empty-icon">&#9671;</div><h3>Digest not found</h3>';
    echo '<p>That slug does not match any digest issue.</p>';
    echo '<p><a class="digest-back-link" href="/digest">&larr; Back to all digests</a></p>';
    echo '</section></div></main>';
    include __DIR__ . '/_partials/layout_close.php';
    exit;
}

// --- Preview-auth gate -----------------------------------------------------
$preview_mode = digest_preview_requested();
if ($preview_mode && !digest_preview_authed()) {
    // No (or wrong) basic-auth creds: prompt the browser. Once the CEO supplies
    // a valid INGEST_API_KEY as the password, the request retries and proceeds.
    digest_preview_challenge();
    // unreachable
}

// --- Fetch from API --------------------------------------------------------
$headers = [];
if ($preview_mode) {
    $headers[] = 'X-API-Key: ' . digest_preview_api_key();
}
list($http_status, $payload, $raw) = digest_api_get(digest_api_base() . '/' . rawurlencode($slug), $headers);

if ($http_status === 404) {
    http_response_code(404);
    $page_title = 'Not found';
    $page_meta  = '';
    $section    = 'digest';
    $preview    = false;
    include __DIR__ . '/_partials/layout_open.php';
    echo '<main><div class="digest-container"><section class="digest-empty">';
    echo '<div class="empty-icon">&#9671;</div><h3>Digest not found</h3>';
    if ($preview_mode) {
        echo '<p>No draft or published digest exists with that slug.</p>';
    } else {
        echo '<p>That digest is not published, or the slug is wrong.</p>';
    }
    echo '<p><a class="digest-back-link" href="/digest">&larr; Back to all digests</a></p>';
    echo '</section></div></main>';
    include __DIR__ . '/_partials/layout_close.php';
    exit;
}

if ($http_status !== 200 || !is_array($payload)) {
    http_response_code(502);
    $page_title = 'Unavailable';
    $page_meta  = '';
    $section    = 'digest';
    $preview    = false;
    include __DIR__ . '/_partials/layout_open.php';
    echo '<main><div class="digest-container"><section class="digest-empty">';
    echo '<div class="empty-icon">&#9888;</div><h3>Digest temporarily unavailable</h3>';
    echo '<p>Upstream returned HTTP ' . (int)$http_status . '. Try again in a moment.</p>';
    echo '<p><a class="digest-back-link" href="/digest">&larr; Back to all digests</a></p>';
    echo '</section></div></main>';
    include __DIR__ . '/_partials/layout_close.php';
    exit;
}

// --- Extract fields --------------------------------------------------------
$digest = $payload;
if (isset($payload['data']) && is_array($payload['data'])) {
    $digest = $payload['data'];
}

$title         = (string)($digest['title']         ?? 'Untitled digest');
$summary       = (string)($digest['summary']       ?? '');
$body_markdown = (string)($digest['body_markdown'] ?? '');
$published_at  = isset($digest['published_at']) ? (string)$digest['published_at'] : '';
$status_val    = (string)($digest['status']        ?? 'draft');
$pubdate_human = digest_format_date($published_at);

// --- Render markdown -------------------------------------------------------
// league/commonmark v2 is "safe by default": raw HTML is escaped and unsafe
// links are stripped unless we explicitly relax those flags. We keep the
// defaults plus the GFM extensions so tables, fenced code, autolinks, and
// task lists work out of the box.
$body_html = null;
if (class_exists('League\\CommonMark\\GithubFlavoredMarkdownConverter')) {
    try {
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 50,
        ]);
        $body_html = (string)$converter->convert($body_markdown);
    } catch (\Throwable $e) {
        $body_html = null;
    }
}

if ($body_html === null) {
    // Fallback for the (transient) case where vendor/ isn't on disk yet:
    // render escaped text inside a <pre> so the page still works.
    $body_html = '<pre class="digest-fallback">' . digest_escape($body_markdown) . '</pre>';
}

// --- Render page -----------------------------------------------------------
$page_title = $title;
$page_meta  = $summary !== '' ? $summary : 'odditytech.news digest';
$section    = 'digest';
$preview    = $preview_mode;
include __DIR__ . '/_partials/layout_open.php';
?>

<main>
  <article class="digest-article">

    <header class="digest-article-header">
      <p class="digest-breadcrumb">
        <a href="/digest">&larr; All digests</a>
      </p>
      <div class="digest-article-meta">
        <?php if ($preview_mode && $status_val === 'draft'): ?>
          <span class="digest-status-pill digest-status-draft">DRAFT</span>
        <?php endif; ?>
        <?php if ($pubdate_human !== ''): ?>
          <time class="digest-article-date" datetime="<?= digest_escape($published_at) ?>"><?= digest_escape($pubdate_human) ?></time>
        <?php endif; ?>
      </div>
      <h1 class="digest-article-title"><?= digest_escape($title) ?></h1>
      <?php if ($summary !== ''): ?>
        <p class="digest-article-lede"><?= digest_escape($summary) ?></p>
      <?php endif; ?>
    </header>

    <div class="digest-prose">
<?= $body_html ?>
    </div>

    <footer class="digest-article-footer">
      <a class="digest-back-link" href="/digest">&larr; Back to all digests</a>
    </footer>

  </article>
</main>

<?php include __DIR__ . '/_partials/layout_close.php'; ?>
