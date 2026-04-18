<?php
/**
 * COGs Dev DB Proxy
 * Allows Claude to run read/write queries against the live DB during development.
 * REMOVE or RESTRICT before going live to members.
 * 
 * Protected by bearer token — never expose this file publicly without the token.
 */

// ── Auth ──────────────────────────────────────────────────────────────────
define('PROXY_TOKEN', 'cogs-dev-proxy-2026-xK9mP3nQ7rL2vW8j');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer ' . PROXY_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Only allow POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ── Parse request ──────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$sql  = trim((string)($body['sql'] ?? ''));

if ($sql === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No SQL provided']);
    exit;
}

// ── Connect ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../_app/api/config/database.php';

try {
    $db   = getDB();
    $stmt = $db->prepare($sql);
    $params = $body['params'] ?? [];
    $stmt->execute($params);

    $upper = strtoupper(strtok(ltrim($sql), " \t\n"));
    if ($upper === 'SELECT' || $upper === 'SHOW' || $upper === 'DESCRIBE' || $upper === 'EXPLAIN') {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
    } else {
        echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
