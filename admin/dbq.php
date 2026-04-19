<?php
/*
 * ══ COG$ Secure DB Query Endpoint ══════════════════════════════════════════
 * READ-ONLY query API for use during the build stage only.
 * DISABLE by renaming or deleting this file when build is complete.
 *
 * Security:
 *   - Token required (X-Dbq-Token header or ?token= param)
 *   - Only SELECT and SHOW statements permitted
 *   - All queries logged with timestamp and IP
 *   - Returns JSON only
 * ══════════════════════════════════════════════════════════════════════════ */

declare(strict_types=1);

define('DBQ_TOKEN', '4d53d5da58011a02539461edb20f504eb199dc46cfcfa0bafba49e0d979964c4');
define('DBQ_LOG',   sys_get_temp_dir() . '/dbq_log.txt');

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// Token check
$token = $_SERVER['HTTP_X_DBQ_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals(DBQ_TOKEN, (string)$token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorised']);
    exit;
}

// Read query
$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];
$sql  = trim((string)($body['sql'] ?? ($_GET['sql'] ?? '')));

if ($sql === '') {
    echo json_encode(['ok' => false, 'error' => 'No SQL provided']);
    exit;
}

// Whitelist: SELECT and SHOW only
if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only SELECT and SHOW statements are permitted']);
    exit;
}

// Log
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ts = date('Y-m-d H:i:s');
@file_put_contents(DBQ_LOG, "[{$ts}] [{$ip}] " . str_replace("\n", ' ', $sql) . "\n", FILE_APPEND | LOCK_EX);

// Connect via existing API infrastructure
require_once __DIR__ . '/../_app/api/config/database.php';

try {
    $pdo  = getDB();
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'      => true,
        'count'   => count($rows),
        'columns' => count($rows) > 0 ? array_keys($rows[0]) : [],
        'rows'    => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
