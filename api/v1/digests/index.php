<?php
// -----------------------------------------------------------------------------
// /api/v1/digests/ — REST endpoint for the `/digest` publication (SIG-176).
// PHP 7.1+ compatible. Runs as-is on Namecheap shared hosting.
//
// Routes (dispatched by REQUEST_METHOD + the trailing path segment):
//   POST   /api/v1/digests/             create a draft (auth required)
//   GET    /api/v1/digests/             list digests
//   GET    /api/v1/digests/{slug}       fetch one by slug
//   PATCH  /api/v1/digests/{id}         update by integer id (auth required)
//
// Auth: the same X-API-KEY surface SSCI Integration uses for /api/v1/headlines/.
// Public (unauthenticated) requests are forced to `status=published` server-side;
// drafts are never leaked.
// -----------------------------------------------------------------------------

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

// --- Helpers ----------------------------------------------------------------
function fail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra));
    exit;
}

// Returns the role of the X-API-KEY presented on this request:
//   - 'publisher' : matches PUBLISHER_API_KEY (CEO publish key, narrow-scoped).
//   - 'editor'    : matches INGEST_API_KEY  (Editor key — drafts only for publish PATCH).
//   - ''          : no/invalid key.
// Publisher takes precedence so the same value cannot accidentally degrade to editor.
function authed_key_role(): string {
    $publisherKey = $_ENV['PUBLISHER_API_KEY'] ?? '';
    $editorKey    = $_ENV['INGEST_API_KEY']    ?? '';
    $provided     = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($provided === '') return '';
    if ($publisherKey !== '' && hash_equals($publisherKey, $provided)) return 'publisher';
    if ($editorKey    !== '' && hash_equals($editorKey,    $provided)) return 'editor';
    return '';
}

function is_authed(): bool {
    return authed_key_role() !== '';
}

function require_auth(): void {
    if (!is_authed()) fail(401, 'Unauthorized');
}

// Strict mode applies once PUBLISHER_API_KEY is configured on the server.
// Until rotation, INGEST_API_KEY retains compat publish (instruction-enforced as before).
function publisher_split_enforced(): bool {
    return !empty($_ENV['PUBLISHER_API_KEY']);
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = 'digest';
    return substr($s, 0, 200);
}

function row_to_payload(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'slug'          => $r['slug'],
        'title'         => $r['title'],
        'summary'       => $r['summary'],
        'body_markdown' => $r['body_markdown'],
        'lead_cluster'  => $r['lead_cluster'],
        'status'        => $r['status'],
        'published_at'  => $r['published_at'],
        'created_at'    => $r['created_at'],
        'updated_at'    => $r['updated_at'],
    ];
}

// --- DB ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') .
        ';dbname=' . ($_ENV['DB_NAME'] ?? '') .
        ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail(500, 'Database connection failed');
}

// --- Routing ----------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = isset($_GET['path']) ? trim((string)$_GET['path'], '/') : '';

if ($method === 'POST' && $path === '') {
    handle_create($pdo);
} elseif ($method === 'GET' && $path === '') {
    handle_list($pdo);
} elseif ($method === 'GET' && $path !== '') {
    handle_get_by_slug($pdo, $path);
} elseif ($method === 'PATCH' && $path !== '') {
    handle_patch($pdo, $path);
} else {
    fail(405, 'Method not allowed');
}

// --- POST /api/v1/digests/ --------------------------------------------------
function handle_create(PDO $pdo): void {
    $role = authed_key_role();
    if ($role === '') fail(401, 'Unauthorized');
    if ($role === 'publisher') {
        fail(403, 'Publisher key cannot create digests; use INGEST_API_KEY');
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) fail(400, 'Invalid JSON');

    $errors = [];
    if (empty($body['title']))         $errors[] = 'title is required';
    if (empty($body['body_markdown'])) $errors[] = 'body_markdown is required';
    if ($errors) fail(422, 'Validation failed', ['errors' => $errors]);

    $status = ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $slug   = !empty($body['slug']) ? slugify((string)$body['slug']) : slugify((string)$body['title']);

    // Ensure slug uniqueness by suffixing -2, -3, … on collision.
    $base = $slug; $n = 1;
    $check = $pdo->prepare('SELECT 1 FROM digests WHERE slug = :slug');
    while (true) {
        $check->execute([':slug' => $slug]);
        if (!$check->fetchColumn()) break;
        $n++;
        $slug = substr($base, 0, 200 - strlen("-$n")) . "-$n";
    }

    $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare('
        INSERT INTO digests (slug, title, summary, body_markdown, lead_cluster, status, published_at)
        VALUES (:slug, :title, :summary, :body_markdown, :lead_cluster, :status, :published_at)
    ');
    $stmt->execute([
        ':slug'          => $slug,
        ':title'         => substr((string)$body['title'], 0, 500),
        ':summary'       => isset($body['summary']) ? (string)$body['summary'] : null,
        ':body_markdown' => (string)$body['body_markdown'],
        ':lead_cluster'  => isset($body['lead_cluster']) ? substr((string)$body['lead_cluster'], 0, 255) : null,
        ':status'        => $status,
        ':published_at'  => $publishedAt,
    ]);

    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare('SELECT * FROM digests WHERE id = :id');
    $row->execute([':id' => $id]);
    $persisted = $row->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode(row_to_payload($persisted));
}

// --- GET /api/v1/digests/ ---------------------------------------------------
function handle_list(PDO $pdo): void {
    $authed       = is_authed();
    $statusFilter = isset($_GET['status']) ? (string)$_GET['status'] : null;
    $orderRaw     = isset($_GET['order']) ? (string)$_GET['order'] : '-published_at';

    // Public requests are silently coerced to status=published — never leak drafts.
    if (!$authed) {
        $statusFilter = 'published';
    }

    $where = [];
    $params = [];
    if ($statusFilter !== null && $statusFilter !== '') {
        if (!in_array($statusFilter, ['draft', 'published'], true)) {
            fail(400, 'Invalid status; must be draft or published');
        }
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    // Whitelist order — only published_at is sortable to keep the surface small.
    $orderDir = 'DESC';
    $orderCol = 'published_at';
    if (strpos($orderRaw, '-') === 0) {
        $orderDir = 'DESC';
        $orderCol = substr($orderRaw, 1);
    } else {
        $orderDir = 'ASC';
        $orderCol = $orderRaw;
    }
    if (!in_array($orderCol, ['published_at', 'created_at'], true)) {
        fail(400, 'Invalid order; supported: published_at, created_at');
    }

    $sql = 'SELECT * FROM digests';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    // Put NULL published_at last for DESC so drafts (when listed authed) sort sensibly.
    $sql .= " ORDER BY $orderCol IS NULL, $orderCol $orderDir, id DESC";
    $sql .= ' LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'digests' => array_map('row_to_payload', $rows),
        'count'   => count($rows),
    ]);
}

// --- GET /api/v1/digests/{slug} ---------------------------------------------
function handle_get_by_slug(PDO $pdo, string $slug): void {
    $stmt = $pdo->prepare('SELECT * FROM digests WHERE slug = :slug');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) fail(404, 'Not found');
    if ($row['status'] !== 'published' && !is_authed()) fail(404, 'Not found');

    echo json_encode(row_to_payload($row));
}

// --- PATCH /api/v1/digests/{id} ---------------------------------------------
function handle_patch(PDO $pdo, string $idPath): void {
    $role = authed_key_role();
    if ($role === '') fail(401, 'Unauthorized');

    if (!ctype_digit($idPath)) fail(400, 'PATCH expects a numeric id in the path');
    $id = (int)$idPath;

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) fail(400, 'Invalid JSON');

    // Two-key boundary (SIG-290): only PUBLISHER_API_KEY can flip status to published
    // once the publisher key is configured on the server. INGEST_API_KEY remains the
    // Editor key — drafts only. Publisher key, conversely, can ONLY publish: it cannot
    // write content fields or move a row back to draft.
    $touchesStatus = array_key_exists('status', $body);
    $newStatus     = $touchesStatus ? $body['status'] : null;

    if ($role === 'editor' && publisher_split_enforced()
        && $touchesStatus && $newStatus === 'published') {
        fail(403, 'Editor key cannot publish; use PUBLISHER_API_KEY');
    }

    if ($role === 'publisher') {
        // Publisher key is publish-only: must set status=published, must not touch
        // anything else, and must not write draft.
        if (!$touchesStatus || $newStatus !== 'published') {
            fail(403, 'Publisher key may only PATCH status=published');
        }
        foreach (['title', 'summary', 'body_markdown', 'lead_cluster', 'slug', 'published_at'] as $f) {
            if (array_key_exists($f, $body)) {
                fail(403, 'Publisher key may not modify ' . $f);
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM digests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) fail(404, 'Not found');

    $sets = [];
    $params = [':id' => $id];

    foreach (['title', 'summary', 'body_markdown', 'lead_cluster'] as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $body[$field] === null ? null : (string)$body[$field];
        }
    }

    if (array_key_exists('slug', $body)) {
        $newSlug = slugify((string)$body['slug']);
        if ($newSlug !== $current['slug']) {
            $chk = $pdo->prepare('SELECT 1 FROM digests WHERE slug = :slug AND id <> :id');
            $chk->execute([':slug' => $newSlug, ':id' => $id]);
            if ($chk->fetchColumn()) fail(409, 'Slug already in use');
        }
        $sets[] = 'slug = :slug';
        $params[':slug'] = $newSlug;
    }

    $explicitPublishedAt = array_key_exists('published_at', $body);

    if (array_key_exists('status', $body)) {
        $newStatus = $body['status'];
        if (!in_array($newStatus, ['draft', 'published'], true)) {
            fail(400, 'Invalid status; must be draft or published');
        }
        $sets[] = 'status = :status';
        $params[':status'] = $newStatus;

        // Publish-on-transition: stamp published_at when moving to published
        // and the caller didn't supply an explicit value of their own.
        if ($newStatus === 'published' && empty($current['published_at']) && !$explicitPublishedAt) {
            $sets[] = 'published_at = :published_at';
            $params[':published_at'] = date('Y-m-d H:i:s');
        }
    }

    // Explicit override of published_at (e.g. backdating, or clearing to null).
    if ($explicitPublishedAt) {
        $sets[] = 'published_at = :published_at';
        $params[':published_at'] = $body['published_at'] === null ? null : (string)$body['published_at'];
    }

    if (!$sets) fail(400, 'No updatable fields provided');

    $sql = 'UPDATE digests SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    $row = $pdo->prepare('SELECT * FROM digests WHERE id = :id');
    $row->execute([':id' => $id]);
    echo json_encode(row_to_payload($row->fetch(PDO::FETCH_ASSOC)));
}
