<?php
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
if ($method !== 'POST' && $method !== 'GET') {
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
    $where = [];
    $params = [];

    if (isset($_GET['canonical_paper_url']) && $_GET['canonical_paper_url'] !== '') {
        $where[] = 'canonical_paper_url = :canonical_paper_url';
        $params[':canonical_paper_url'] = (string)$_GET['canonical_paper_url'];
    }

    $sql = '
        SELECT id, title, summary, source_url, source_name, category, tags,
               published_at, canonical_paper_url
        FROM headlines
    ';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY published_at DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode(['headlines' => $rows, 'count' => count($rows)]);
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
