<?php
declare(strict_types=1);

// Migration 0004 — normalize existing `headlines.tags` through the canonical
// alias map. SIG-181 (B4 of the SIG-174 plan).
//
// Rewrites each row's comma-separated `tags` column through tag_normalize_list()
// so aliases collapse to their canonical slug (e.g. `memristor` -> `neuromorphic`,
// `kv-cache` -> `llm`). Unmapped tags pass through unchanged.
//
// IDEMPOTENCE:
//   The function tag_normalize_list($current_tags) is the identity for any input
//   that is already canonical+slugified+deduped. So a second run computes the
//   same output as the first and the UPDATE is a no-op (and we skip the write
//   when before == after to avoid touching `updated_at` on identical rows).
//
// USAGE (CLI, from anywhere; the script auto-resolves its config):
//
//     php docs/migrations/0004_normalize_tags.php           # apply
//     php docs/migrations/0004_normalize_tags.php --dry-run # report counts only
//
// Reads DB credentials from the same `.env` one directory above public_html
// that the runtime endpoints use.
//
// IMPORTANT: This file lives under docs/ so the FTPS deploy intentionally skips
// it (the deploy ignores `docs/**`). Apply it by SSH or by uploading it once
// out-of-band and `php`-running it against the prod environment.

require_once __DIR__ . '/../../api/v1/_lib/tag_resolver.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from the CLI, not the web.\n");
    exit(2);
}

$dryRun = in_array('--dry-run', $argv, true);

// --- Load .env --------------------------------------------------------------
// In CLI there is no DOCUMENT_ROOT, so walk up from this file:
//   docs/migrations/0004_normalize_tags.php  -> repo root  -> .env one above public_html.
// In a typical Namecheap layout the repo IS public_html, and .env sits one
// directory above. We accept either layout.
$repoRoot = dirname(__DIR__, 2);
$envCandidates = [
    dirname($repoRoot) . '/.env',
    $repoRoot . '/.env',
];
$envLoaded = false;
foreach ($envCandidates as $envPath) {
    if (!is_readable($envPath)) continue;
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === '') continue;
        $_ENV[$k] = $v;
    }
    $envLoaded = true;
    fwrite(STDOUT, "Loaded env from: $envPath\n");
    break;
}
if (!$envLoaded) {
    fwrite(STDERR, "Could not find a .env file in: " . implode(', ', $envCandidates) . "\n");
    exit(3);
}

// --- DB connect -------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost')
            . ';dbname=' . ($_ENV['DB_NAME'] ?? '')
            . ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(4);
}

// --- Scan & rewrite ---------------------------------------------------------
$rows = $pdo->query('SELECT id, tags FROM headlines WHERE tags IS NOT NULL AND tags != \'\'')
            ->fetchAll(PDO::FETCH_ASSOC);

$total      = count($rows);
$unchanged  = 0;
$rewritten  = 0;
$samples    = [];

$update = $pdo->prepare('UPDATE headlines SET tags = :tags WHERE id = :id');

foreach ($rows as $row) {
    $before = (string)$row['tags'];
    $list   = tag_normalize_list($before);
    $after  = $list ? substr(implode(',', $list), 0, 500) : null;

    if ($after === $before) {
        $unchanged++;
        continue;
    }

    $rewritten++;
    if (count($samples) < 10) {
        $samples[] = sprintf('  id=%d : %s  ->  %s', (int)$row['id'], $before, $after ?? '(null)');
    }

    if (!$dryRun) {
        $update->execute([':tags' => $after, ':id' => (int)$row['id']]);
    }
}

$mode = $dryRun ? 'DRY-RUN' : 'APPLIED';
fwrite(STDOUT, "[$mode] Migration 0004 — normalize headlines.tags\n");
fwrite(STDOUT, "  rows scanned   : $total\n");
fwrite(STDOUT, "  rows unchanged : $unchanged\n");
fwrite(STDOUT, "  rows rewritten : $rewritten\n");
if ($samples) {
    fwrite(STDOUT, "  sample changes :\n" . implode("\n", $samples) . "\n");
}
fwrite(STDOUT, "Idempotency check: re-running this migration after a successful apply\n"
             . "should report 'rows rewritten: 0'.\n");
