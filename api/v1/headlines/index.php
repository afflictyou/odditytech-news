<?php
// SIG-252: deploy-time OPcache invalidation. LSAPI persists bytecode across
// requests; when the FTPS deploy replaces this file, the running workers may
// still serve old bytecode until OPcache notices. Invalidating self up-front
// is cheap and forces a re-parse on the next hit if disk content changed.
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Load .env from one level above public_html
$envPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $_ENV[$k] = $v;
    }
}

// Auth
$apiKey = $_ENV['INGEST_API_KEY'] ?? '';
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$apiKey || !hash_equals($apiKey, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'GET' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// DB connection
try {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if ($method === 'GET') {
    // Strict YYYY-MM-DD parser: rejects non-calendar dates ("2026-02-30") and any other shape.
    $parseIsoDate = static function (string $raw): ?DateTimeImmutable {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        [$_, $y, $mo, $d] = $m;
        if (!checkdate((int)$mo, (int)$d, (int)$y)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
        return $dt === false ? null : $dt;
    };

    $where = [];
    $params = [];

    if (isset($_GET['canonical_paper_url']) && $_GET['canonical_paper_url'] !== '') {
        $where[] = 'canonical_paper_url = :canonical_paper_url';
        $params[':canonical_paper_url'] = (string)$_GET['canonical_paper_url'];
    }

    // since=YYYY-MM-DD — inclusive lower bound on published_at (start of day, UTC).
    if (isset($_GET['since']) && $_GET['since'] !== '') {
        $since = $parseIsoDate((string)$_GET['since']);
        if ($since === null) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid 'since' parameter: must be a calendar date in YYYY-MM-DD format"]);
            exit;
        }
        $where[] = 'published_at >= :since';
        $params[':since'] = $since->format('Y-m-d 00:00:00');
    }

    // until=YYYY-MM-DD — inclusive upper bound on published_at (end of day, UTC).
    if (isset($_GET['until']) && $_GET['until'] !== '') {
        $until = $parseIsoDate((string)$_GET['until']);
        if ($until === null) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid 'until' parameter: must be a calendar date in YYYY-MM-DD format"]);
            exit;
        }
        $where[] = 'published_at <= :until';
        $params[':until'] = $until->format('Y-m-d 23:59:59');
    }

    // order=published_at[:asc|:desc] — default :desc. Only published_at is sortable today.
    $orderBy = 'published_at DESC';
    if (isset($_GET['order']) && $_GET['order'] !== '') {
        $raw = (string)$_GET['order'];
        $parts = explode(':', $raw, 2);
        $field = $parts[0];
        $dir = strtolower($parts[1] ?? 'desc');
        if ($field !== 'published_at' || ($dir !== 'asc' && $dir !== 'desc')) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid 'order' parameter: must be 'published_at' with optional ':asc' or ':desc' suffix"]);
            exit;
        }
        $orderBy = 'published_at ' . strtoupper($dir);
    }

    $sql = '
        SELECT id, title, summary, source_url, source_name, category, tags,
               published_at, canonical_paper_url
        FROM headlines
    ';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $orderBy . ' LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        if (!empty($row['published_at'])) {
            // Persisted as MySQL DATETIME in UTC; surface as ISO-8601 with explicit Z offset.
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['published_at'], new DateTimeZone('UTC'));
            if ($dt !== false) {
                $row['published_at'] = $dt->format('Y-m-d\TH:i:s\Z');
            }
        }
    }
    unset($row);

    http_response_code(200);
    echo json_encode(['headlines' => $rows, 'count' => count($rows)]);
    exit;
}

if ($method === 'PATCH') {
    // SIG-263: partial row update for backfills. Whitelist columns explicitly so
    // adding new writable fields is a one-line change; nothing else is exposed.
    // Absent key -> leave column alone; JSON null -> clear (if nullable); empty
    // string -> reject (mirror POST's canonical_paper_url contract).
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => "Missing or invalid 'id' query parameter"]);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $writable = [
        'canonical_paper_url' => [
            'max'                  => 512,
            'nullable'             => true,
            'reject_empty_string'  => true,
        ],
    ];

    $sets = [];
    $params = [':id' => $id];
    $updated = [];
    foreach ($writable as $col => $rules) {
        if (!array_key_exists($col, $body)) {
            continue;
        }
        $val = $body[$col];

        if ($val === null) {
            if (empty($rules['nullable'])) {
                http_response_code(400);
                echo json_encode(['error' => "$col cannot be null"]);
                exit;
            }
            $sets[] = "$col = NULL";
            $updated[] = $col;
            continue;
        }

        if (!is_string($val) && !is_numeric($val)) {
            http_response_code(400);
            echo json_encode(['error' => "$col must be a string or null"]);
            exit;
        }

        $val = (string)$val;
        if ($val === '' && !empty($rules['reject_empty_string'])) {
            http_response_code(400);
            echo json_encode(['error' => "$col cannot be empty; send null to clear"]);
            exit;
        }
        if (isset($rules['max'])) {
            $val = substr($val, 0, (int)$rules['max']);
        }

        $sets[] = "$col = :$col";
        $params[":$col"] = $val;
        $updated[] = $col;
    }

    if (!$sets) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'No writable fields supplied',
            'allowed' => array_keys($writable),
        ]);
        exit;
    }

    // 404 before UPDATE so callers can distinguish missing row from no-op write.
    $exists = $pdo->prepare('SELECT id FROM headlines WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Headline not found', 'id' => $id]);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE headlines SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);

    $sel = $pdo->prepare('
        SELECT id, title, summary, source_url, source_name, category, tags,
               published_at, canonical_paper_url
        FROM headlines
        WHERE id = :id
    ');
    $sel->execute([':id' => $id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['id'] = (int)$row['id'];
        if (!empty($row['published_at'])) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['published_at'], new DateTimeZone('UTC'));
            if ($dt !== false) {
                $row['published_at'] = $dt->format('Y-m-d\TH:i:s\Z');
            }
        }
    }

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'id'       => $id,
        'updated'  => $updated,
        'headline' => $row,
    ]);
    exit;
}

// POST: parse body
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$required = ['title', 'summary', 'source_url', 'source_name', 'category'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

// Optional canonical paper URL (SIG-177 dedup field).
$canonicalPaperUrl = null;
if (isset($body['canonical_paper_url']) && $body['canonical_paper_url'] !== '') {
    $canonicalPaperUrl = substr((string)$body['canonical_paper_url'], 0, 512);
}

// Insert headline
$stmt = $pdo->prepare('
    INSERT INTO headlines (title, summary, source_url, source_name, category, tags, published_at, canonical_paper_url)
    VALUES (:title, :summary, :source_url, :source_name, :category, :tags, :published_at, :canonical_paper_url)
');

$stmt->execute([
    ':title'               => substr($body['title'], 0, 500),
    ':summary'             => $body['summary'],
    ':source_url'          => substr($body['source_url'], 0, 2083),
    ':source_name'         => substr($body['source_name'], 0, 255),
    ':category'            => substr($body['category'], 0, 100),
    ':tags'                => isset($body['tags']) ? substr(implode(',', (array)$body['tags']), 0, 500) : null,
    ':published_at'        => $body['published_at'] ?? date('Y-m-d H:i:s'),
    ':canonical_paper_url' => $canonicalPaperUrl,
]);

http_response_code(201);
echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
