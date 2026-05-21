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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse body
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

// Insert headline
$stmt = $pdo->prepare('
    INSERT INTO headlines (title, summary, source_url, source_name, category, tags, published_at)
    VALUES (:title, :summary, :source_url, :source_name, :category, :tags, :published_at)
');

$stmt->execute([
    ':title'        => substr($body['title'], 0, 500),
    ':summary'      => $body['summary'],
    ':source_url'   => substr($body['source_url'], 0, 2083),
    ':source_name'  => substr($body['source_name'], 0, 255),
    ':category'     => substr($body['category'], 0, 100),
    ':tags'         => isset($body['tags']) ? substr(implode(',', (array)$body['tags']), 0, 500) : null,
    ':published_at' => $body['published_at'] ?? date('Y-m-d H:i:s'),
]);

http_response_code(201);
echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
