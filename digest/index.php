<?php
// -----------------------------------------------------------------------------
// digest/index.php (SIG-178)
//
// Public landing page for the /digest publication. Lists published digests,
// most-recent first, with title + 1-sentence summary + publish date. Each
// entry links to /digest/<slug> (resolved by digest/show.php).
//
// Data source: GET /api/v1/digests/?status=published&order=-published_at
// (the API enforces status=published for anonymous callers anyway — see
// SIG-176 — but we pass the filter explicitly for clarity and to keep the
// query stable if the contract ever changes).
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/_partials/bootstrap.php';

$api_url = digest_api_base() . '/?status=published&order=-published_at';
list($status, $payload, $raw) = digest_api_get($api_url);

$digests = [];
$fetch_error = null;
if ($status === 200 && is_array($payload)) {
    // SIG-176 list shape: {"digests": [...], "count": N}. Fall back to a raw
    // top-level list for resilience if the contract ever changes.
    if (isset($payload['digests']) && is_array($payload['digests'])) {
        $digests = $payload['digests'];
    } elseif (digest_array_is_list($payload)) {
        $digests = $payload;
    }
} else {
    $fetch_error = sprintf('Upstream returned HTTP %d.', $status);
}

$page_title = 'Digest';
$page_meta  = 'Every-few-days editorial digest of obscure AI, robotics, and tech signals — most-recent first.';
$section    = 'digest';
$preview    = false;
include __DIR__ . '/_partials/layout_open.php';
?>

<main>
  <div class="digest-container">

    <header class="digest-page-header">
      <h2 class="digest-page-title">The Digest</h2>
      <p class="digest-page-lede">
        Every few days we cluster the strangest, most under-covered signals from the
        feed into a single editorial brief. Read by humans, written by humans-and-agents.
      </p>
    </header>

    <?php if ($fetch_error !== null): ?>
      <section class="digest-empty">
        <div class="empty-icon">&#9888;</div>
        <h3>Digest temporarily unavailable</h3>
        <p><?= digest_escape($fetch_error) ?> Try again in a moment.</p>
      </section>

    <?php elseif (empty($digests)): ?>
      <section class="digest-empty">
        <div class="empty-icon">&#9672;</div>
        <h3>No digest issues yet</h3>
        <p>The first edition is on the way. Check back soon.</p>
      </section>

    <?php else: ?>
      <ol class="digest-list" role="list">
        <?php foreach ($digests as $d):
            $slug    = (string)($d['slug'] ?? '');
            if ($slug === '') continue;
            $title   = (string)($d['title'] ?? 'Untitled digest');
            $summary = (string)($d['summary'] ?? '');
            $pubdate = digest_format_date(isset($d['published_at']) ? (string)$d['published_at'] : null);
        ?>
          <li class="digest-list-item">
            <a class="digest-list-link" href="/digest/<?= digest_escape($slug) ?>">
              <div class="digest-list-meta">
                <?php if ($pubdate !== ''): ?>
                  <time class="digest-list-date"><?= digest_escape($pubdate) ?></time>
                <?php endif; ?>
              </div>
              <h3 class="digest-list-title"><?= digest_escape($title) ?></h3>
              <?php if ($summary !== ''): ?>
                <p class="digest-list-summary"><?= digest_escape($summary) ?></p>
              <?php endif; ?>
              <span class="digest-list-arrow" aria-hidden="true">Read &rarr;</span>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/_partials/layout_close.php'; ?>
