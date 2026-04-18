<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = ['raw' => $raw];
$dir = __DIR__ . '/../storage';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$file = $dir . '/public-capture-' . date('Y-m-d') . '.log';
$record = [
    'timestamp' => date('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'data' => $data
];
file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
echo json_encode(['ok' => true, 'message' => 'Captured']);
?>