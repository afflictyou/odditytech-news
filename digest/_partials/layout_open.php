<?php
// -----------------------------------------------------------------------------
// digest/_partials/layout_open.php (SIG-178)
// Shared <head> + <header> + section nav (FEED / DIGEST) for the digest
// publication. Variables expected (all optional):
//   $page_title — string appended to "ODDITYTECH.NEWS — "
//   $page_meta  — string used as <meta name="description">
//   $section    — 'digest' or 'feed' (default 'digest'); controls active tab
//   $preview    — bool; when true, render a small "DRAFT PREVIEW" badge
// -----------------------------------------------------------------------------

if (!isset($page_title)) $page_title = 'Digest';
if (!isset($page_meta))  $page_meta  = 'Every-few-days editorial digest of obscure AI, robotics, and tech signals.';
if (!isset($section))    $section    = 'digest';
if (!isset($preview))    $preview    = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ODDITYTECH.NEWS — <?= digest_escape($page_title) ?></title>
<meta name="description" content="<?= digest_escape($page_meta) ?>">
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
        <h1 class="logo"><a href="/" style="color:inherit;text-decoration:none;">ODDITYTECH<span class="logo-dot">.</span>NEWS</a></h1>
        <p class="tagline">Signals from the Fringe of Science &amp; Technology</p>
      </div>
    </div>
    <div class="header-meta">
      <?php if ($preview): ?>
        <span class="live-badge" style="color:#ffb800;border-color:rgba(255,184,0,0.4);background:rgba(255,184,0,0.06);">
          <span class="live-dot" style="background:#ffb800;box-shadow:0 0 8px #ffb800;" aria-hidden="true"></span>
          DRAFT PREVIEW
        </span>
      <?php else: ?>
        <span class="live-badge"><span class="live-dot" aria-hidden="true"></span> LIVE FEED</span>
      <?php endif; ?>
      <span class="update-time">Updated nightly by SSCI</span>
    </div>
  </div>
</header>

<nav class="section-nav" aria-label="Section">
  <div class="section-nav-inner">
    <a href="/" class="section-tab<?= $section === 'feed' ? ' active' : '' ?>"
       <?= $section === 'feed' ? 'aria-current="page"' : '' ?>>Feed</a>
    <a href="/digest" class="section-tab<?= $section === 'digest' ? ' active' : '' ?>"
       <?= $section === 'digest' ? 'aria-current="page"' : '' ?>>Digest</a>
  </div>
</nav>
