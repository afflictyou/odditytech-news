<?php
// -----------------------------------------------------------------------------
// odditytech.news — v1 (view toggle + search + tag filter)
// PHP 7.1+ compatible. Runs as-is on Namecheap shared hosting.
// Schema (table `headlines`):
//   id, title, summary, source_url, source_name, category, tags, published_at
//   NOTE: no `status` column. Category is a single value; tags is a separate
//   comma-separated free-form string column.
// Credentials are loaded from a .env file one directory above public_html.
// -----------------------------------------------------------------------------

// --- Load .env --------------------------------------------------------------
$envPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        if ($k === '') continue;
        $_ENV[$k] = $v;
    }
}

// --- Query state ------------------------------------------------------------
$headlines      = [];
$categories     = ['All'];
$activeCategory = isset($_GET['cat']) ? $_GET['cat'] : 'All';
$page           = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage        = 12;
$offset         = ($page - 1) * $perPage;
$totalPages     = 1;

// --- DB ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . (isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'localhost') .
        ';dbname=' . (isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : '') .
        ';charset=utf8mb4',
        isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : '',
        isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $cats = $pdo->query('SELECT DISTINCT category FROM headlines ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    $categories = array_merge(['All'], $cats);

    if ($activeCategory === 'All') {
        $count = $pdo->query('SELECT COUNT(*) FROM headlines')->fetchColumn();
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM headlines WHERE category = :cat');
        $stmt->execute([':cat' => $activeCategory]);
        $count = $stmt->fetchColumn();
    }
    $totalPages = max(1, (int)ceil($count / $perPage));

    if ($activeCategory === 'All') {
        $stmt = $pdo->prepare('SELECT * FROM headlines ORDER BY published_at DESC LIMIT :limit OFFSET :offset');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM headlines WHERE category = :cat ORDER BY published_at DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':cat', $activeCategory);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $headlines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fail silently; render empty state below.
}

// --- Helpers ----------------------------------------------------------------
function timeAgo($datetime) {
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function categoryColor($cat) {
    $colors = [
        'AI'       => '#00ffa3',
        'Robotics' => '#00cfff',
        'Biotech'  => '#ff6eb4',
        'Physics'  => '#ffb800',
        'Quantum'  => '#b388ff',
        'Space'    => '#ff7043',
        'Neuro'    => '#69ff47',
    ];
    return isset($colors[$cat]) ? $colors[$cat] : '#aaaaaa';
}

// Normalize a free-form tag string into a stable slug for matching/dedup.
// Display text stays untouched; only the slug is used as a data attribute.
function normalize_tag($tag) {
    $t = strtolower(trim($tag));
    $t = preg_replace('/\s+/', '-', $t);
    $t = preg_replace('/[^a-z0-9\-]/', '', $t);
    $t = preg_replace('/-+/', '-', $t);
    return trim($t, '-');
}

// Split a comma-separated tag column into [['display' => ..., 'slug' => ...], ...]
function tagList($tagsString) {
    if ($tagsString === null || $tagsString === '') return [];
    $out = [];
    $seen = [];
    foreach (explode(',', $tagsString) as $raw) {
        $display = trim($raw);
        if ($display === '') continue;
        $slug = normalize_tag($display);
        if ($slug === '' || isset($seen[$slug])) continue;
        $seen[$slug] = true;
        $out[] = ['display' => $display, 'slug' => $slug];
    }
    return $out;
}

// Collect unique tags across the current page for the top-of-page filter bar.
$allTags = [];
foreach ($headlines as $h) {
    if (empty($h['tags'])) continue;
    foreach (tagList($h['tags']) as $t) {
        if (!isset($allTags[$t['slug']])) {
            $allTags[$t['slug']] = $t['display'];
        }
    }
}
ksort($allTags);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ODDITYTECH.NEWS — Signals from the Fringe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Barlow+Condensed:wght@300;400;600;700;900&family=Barlow:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=2">
</head>
<body>

<div class="scanline" aria-hidden="true"></div>

<header>
  <div class="header-inner">
    <div class="logo-block">
      <div class="logo-signal" aria-hidden="true">
        <span class="signal-dot"></span>
        <span class="signal-dot"></span>
        <span class="signal-dot"></span>
      </div>
      <div>
        <h1 class="logo">ODDITYTECH<span class="logo-dot">.</span>NEWS</h1>
        <p class="tagline">Signals from the Fringe of Science &amp; Technology</p>
      </div>
    </div>
    <div class="header-meta">
      <span class="live-badge"><span class="live-dot" aria-hidden="true"></span> LIVE FEED</span>
      <span class="update-time">Updated nightly by SSCI</span>
    </div>
  </div>
</header>

<!-- SIG-178: section nav (Feed | Digest). Sits above the category filter so
     it never interferes with feed pagination or card layout. -->
<nav class="section-nav" aria-label="Section">
  <div class="section-nav-inner">
    <a href="/" class="section-tab active" aria-current="page">Feed</a>
    <a href="/digest" class="section-tab">Digest</a>
  </div>
</nav>

<nav class="category-nav" aria-label="Category">
  <div class="nav-inner">
    <?php foreach ($categories as $cat): ?>
    <a href="?cat=<?= urlencode($cat) ?>"
       class="cat-pill<?= $cat === $activeCategory ? ' active' : '' ?>"
       <?php if ($cat !== 'All'): ?>style="--cat-color: <?= categoryColor($cat) ?>"<?php endif; ?>>
      <?= htmlspecialchars($cat) ?>
    </a>
    <?php endforeach; ?>
  </div>
</nav>

<section class="toolbar" aria-label="Filters">
  <div class="toolbar-inner">
    <div class="toolbar-section toolbar-search">
      <label class="visually-hidden" for="search-input">Search signals</label>
      <input type="search"
             id="search-input"
             class="search-input"
             placeholder="// SEARCH SIGNALS"
             autocomplete="off"
             spellcheck="false">
    </div>
    <div class="toolbar-section toolbar-view">
      <span class="toolbar-label">VIEW</span>
      <div class="view-toggle" role="group" aria-label="View mode">
        <button type="button" class="view-btn" data-view="grid"    aria-label="Grid view"    title="Grid">&#9638;</button>
        <button type="button" class="view-btn" data-view="compact" aria-label="Compact view" title="Compact">&#9636;</button>
        <button type="button" class="view-btn" data-view="list"    aria-label="List view"    title="List">&#8801;</button>
      </div>
    </div>
  </div>
  <?php if (!empty($allTags)): ?>
  <div class="tag-bar" aria-label="Tag filters">
    <span class="tag-bar-label">TAGS</span>
    <?php foreach ($allTags as $slug => $display): ?>
      <button type="button" class="tag-chip" data-tag-filter="<?= htmlspecialchars($slug) ?>" aria-pressed="false"><?= htmlspecialchars($display) ?></button>
    <?php endforeach; ?>
    <button type="button" class="tag-chip tag-clear" data-tag-clear hidden>CLEAR</button>
  </div>
  <?php endif; ?>
</section>

<main>
  <div class="container">

    <?php if (empty($headlines)): ?>
    <div class="empty-state">
      <div class="empty-icon">&#9672;</div>
      <h2>Awaiting first transmission</h2>
      <p>SSCI is scanning the fringes. Check back soon.</p>
    </div>
    <?php else: ?>

    <div class="headline-grid">
      <?php foreach ($headlines as $i => $h):
          $tags     = tagList(isset($h['tags']) ? $h['tags'] : '');
          $tagSlugs = [];
          foreach ($tags as $t) { $tagSlugs[] = $t['slug']; }
          $tagSlugStr = implode(' ', $tagSlugs);
      ?>
      <article class="card"
               data-title="<?= htmlspecialchars(strtolower($h['title']), ENT_QUOTES) ?>"
               data-summary="<?= htmlspecialchars(strtolower(isset($h['summary']) ? $h['summary'] : ''), ENT_QUOTES) ?>"
               data-tags="<?= htmlspecialchars($tagSlugStr, ENT_QUOTES) ?>"
               data-category="<?= htmlspecialchars($h['category'], ENT_QUOTES) ?>"
               style="--cat-color: <?= categoryColor($h['category']) ?>; animation-delay: <?= $i * 0.05 ?>s">
        <div class="card-header">
          <span class="card-category"><?= htmlspecialchars($h['category']) ?></span>
          <span class="card-time"><?= htmlspecialchars(timeAgo($h['published_at'])) ?></span>
        </div>
        <h2 class="card-title">
          <a href="<?= htmlspecialchars($h['source_url']) ?>" target="_blank" rel="noopener noreferrer">
            <?= htmlspecialchars($h['title']) ?>
          </a>
        </h2>
        <?php if (!empty($h['summary'])): ?>
        <p class="card-summary"><?= htmlspecialchars($h['summary']) ?></p>
        <?php endif; ?>
        <div class="card-footer">
          <span class="card-source">&#10961; <?= htmlspecialchars(isset($h['source_name']) ? $h['source_name'] : '') ?></span>
          <?php if (!empty($tags)): ?>
          <div class="card-tags">
            <?php foreach ($tags as $t): ?>
              <button type="button" class="tag" data-tag="<?= htmlspecialchars($t['slug']) ?>"><?= htmlspecialchars($t['display']) ?></button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="empty-state empty-state-filtered" hidden>
      <div class="empty-icon">&#9671;</div>
      <h2>No signals match these filters</h2>
      <p>Clear search or tag filters to see all transmissions on this page.</p>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination" data-pagination>
      <?php if ($page > 1): ?>
        <a href="?cat=<?= urlencode($activeCategory) ?>&amp;page=<?= $page - 1 ?>" class="page-btn">&larr; PREV</a>
      <?php endif; ?>
      <span class="page-info"><?= $page ?> / <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?cat=<?= urlencode($activeCategory) ?>&amp;page=<?= $page + 1 ?>" class="page-btn">NEXT &rarr;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</main>

<footer>
  <div class="container">
    <p>ODDITYTECH.NEWS &mdash; Curated by <a href="https://www.youtube.com/@mikebemiss8186" target="_blank" rel="noopener noreferrer">challengeyourself.blog</a> &mdash; Powered by SSCI</p>
  </div>
</footer>

<script src="/assets/js/main.js?v=1"></script>
</body>
</html>