<?php
/**
 * Ask AI — Public FAQ Chat Proxy
 * POST /_app/api/ask
 *
 * Accepts a user question, sends it to Claude with FAQ knowledge context,
 * returns the AI response. No authentication required (public widget).
 *
 * Rate limited: 10 requests per IP per minute.
 * Requires ANTHROPIC_API_KEY in .env
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ── Rate limiting (simple file-based) ────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateDir = sys_get_temp_dir() . '/cogs_ask_rate';
if (!is_dir($rateDir)) @mkdir($rateDir, 0755, true);
$rateFile = $rateDir . '/' . md5($ip) . '.json';
$rateData = file_exists($rateFile) ? json_decode((string)file_get_contents($rateFile), true) : [];
$now = time();
$rateData = array_filter($rateData ?: [], fn($t) => $t > $now - 60);
if (count($rateData) >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
    exit;
}
$rateData[] = $now;
file_put_contents($rateFile, json_encode($rateData));

// ── Parse input ──────────────────────────────────────────────────────────────
$body = json_decode((string)file_get_contents('php://input'), true);
$question = trim((string)($body['question'] ?? ''));
$history = is_array($body['history'] ?? null) ? $body['history'] : [];

if ($question === '' || mb_strlen($question) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a question (max 1000 characters).']);
    exit;
}

// ── Load knowledge base ──────────────────────────────────────────────────────
$knowledgePath = __DIR__ . '/../ask-knowledge.txt';
$knowledge = file_exists($knowledgePath) ? file_get_contents($knowledgePath) : '';

// ── API key ──────────────────────────────────────────────────────────────────
$apiKey = (string)env('ANTHROPIC_API_KEY', '');
if ($apiKey === '') {
    http_response_code(500);
    error_log('[ask] ANTHROPIC_API_KEY not configured');
    echo json_encode(['error' => 'AI assistant is not configured yet.']);
    exit;
}

// ── System prompt ────────────────────────────────────────────────────────────
$systemPrompt = <<<PROMPT
You are the COG$ of Australia Foundation assistant. You answer questions about the Foundation based ONLY on the knowledge provided below. You are friendly, clear, and concise.

RULES:
- Answer ONLY from the knowledge base below. If the answer is not in the knowledge, say "I don't have that information yet — please email members@cogsaustralia.org or check the FAQ page."
- Never make up information, speculate, or provide legal/financial advice.
- Keep answers concise — 2-4 sentences for simple questions, a short paragraph for complex ones.
- Use Australian English spelling.
- If asked about prices, dates, or specific numbers, quote them exactly from the knowledge base.
- If someone wants to join, direct them to the join page at /join/index.html
- You represent a private community foundation. Be warm but professional.

KNOWLEDGE BASE:
{$knowledge}

ADDITIONAL CONTEXT:
- Website: cogsaustralia.org (private community, direct link only)
- Foundation Governance Day: 14 May 2026
- Personal membership: $4 once only
- Kids S-NFT: $1 per child
- Business B-NFT: $40 per ABN
- Contact: members@cogsaustralia.org
- ABN: 61 734 327 831
- Trustee: Thomas Boyd Cunliffe
- Location: Drake Village, NSW 2469
PROMPT;

// ── Build messages ───────────────────────────────────────────────────────────
$messages = [];
// Include up to 6 recent history messages for context
$recentHistory = array_slice($history, -6);
foreach ($recentHistory as $msg) {
    $role = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($msg['content'] ?? ''));
    if ($content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}
$messages[] = ['role' => 'user', 'content' => $question];

// ── Call Anthropic API ───────────────────────────────────────────────────────
$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 600,
    'system'     => $systemPrompt,
    'messages'   => $messages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    error_log('[ask] cURL error: ' . $curlErr);
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach the AI service. Please try again.']);
    exit;
}

$data = json_decode((string)$response, true);

if ($httpCode !== 200 || !is_array($data)) {
    error_log('[ask] API error ' . $httpCode . ': ' . substr((string)$response, 0, 500));
    http_response_code(502);
    echo json_encode(['error' => 'AI service returned an error. Please try again.']);
    exit;
}

// ── Extract response text ────────────────────────────────────────────────────
$answer = '';
foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') {
        $answer .= (string)$block['text'];
    }
}

echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
