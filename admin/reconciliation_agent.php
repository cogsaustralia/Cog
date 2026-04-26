<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

function ra_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function ra_rows(PDO $p, string $q): array {
    try { $s = $p->query($q); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

function ra_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

// ── Handle AJAX chat POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

    // CSRF — token sent in JSON body alongside message/history. Frontend
    // reads it from the server-rendered window.__csrfToken below.
    admin_csrf_verify_json($body);

    $userMessage = trim((string)($body['message'] ?? ''));
    $history     = is_array($body['history'] ?? null) ? $body['history'] : [];

    if ($userMessage === '') {
        echo json_encode(['error' => 'Empty message.']);
        exit;
    }

    // ── Fetch live ledger snapshot for system prompt ──────────────────────────
    $invariants = ra_rows($pdo, "SELECT code, name, violation_count FROM v_godley_invariant_status ORDER BY code");
    $consolidated = ra_rows($pdo, "SELECT sub_trust, display_name, balance_cents, entry_count FROM v_godley_consolidated");
    $recentTxRefs = ra_rows($pdo, "
        SELECT DISTINCT transaction_ref, MIN(entry_date) AS entry_date, source_table
        FROM ledger_entries
        GROUP BY transaction_ref, source_table
        ORDER BY MIN(entry_date) DESC, MIN(id) DESC
        LIMIT 10
    ");
    $recentEntries = [];
    if (!empty($recentTxRefs)) {
        $refs = array_column($recentTxRefs, 'transaction_ref');
        $ph = implode(',', array_fill(0, count($refs), '?'));
        try {
            $s = $pdo->prepare("
                SELECT le.transaction_ref, le.entry_type, le.amount_cents,
                       le.classification, le.flow_category, le.entry_date,
                       sa.display_name AS account_name, sa.account_type
                FROM ledger_entries le
                JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
                WHERE le.transaction_ref IN ($ph)
                ORDER BY le.transaction_ref, le.id
            ");
            $s->execute($refs);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $recentEntries[$row['transaction_ref']][] = $row;
            }
        } catch (Throwable $e) {}
    }
    $sectorBalances = ra_rows($pdo, "
        SELECT sa.display_name, sa.account_type,
               COALESCE(SUM(CASE WHEN le.entry_type='debit'  THEN le.amount_cents ELSE 0 END),0) AS dr,
               COALESCE(SUM(CASE WHEN le.entry_type='credit' THEN le.amount_cents ELSE 0 END),0) AS cr,
               COALESCE(SUM(CASE WHEN le.entry_type='debit'  THEN le.amount_cents
                                 WHEN le.entry_type='credit' THEN -le.amount_cents END),0) AS net,
               COUNT(le.id) AS entries
        FROM stewardship_accounts sa
        LEFT JOIN ledger_entries le ON le.stewardship_account_id = sa.id
        WHERE sa.status = 'active'
        GROUP BY sa.id, sa.display_name, sa.account_type
        ORDER BY sa.id
    ");

    // ── Build system prompt ───────────────────────────────────────────────────
    $invLines = [];
    foreach ($invariants as $inv) {
        $status = (int)$inv['violation_count'] === 0 ? 'CLEAR' : 'VIOLATION (' . $inv['violation_count'] . ')';
        $invLines[] = '  ' . $inv['code'] . ' [' . $status . '] — ' . $inv['name'];
    }

    $consLines = [];
    foreach ($consolidated as $c) {
        $bal = (int)$c['balance_cents'];
        $consLines[] = '  ' . $c['display_name'] . ': ' . ra_dollars(abs($bal)) . ($bal < 0 ? ' Cr' : ($bal > 0 ? ' Dr' : ' (zero)')) . ' | ' . $c['entry_count'] . ' entries';
    }

    $sectorLines = [];
    foreach ($sectorBalances as $s) {
        $net = (int)$s['net'];
        $sectorLines[] = '  ' . $s['display_name'] . ' (' . $s['account_type'] . '): Dr ' . ra_dollars((int)$s['dr']) . ' / Cr ' . ra_dollars((int)$s['cr']) . ' | Net ' . ra_dollars(abs($net)) . ($net < 0 ? ' Cr' : ($net > 0 ? ' Dr' : ' zero')) . ' | ' . $s['entries'] . ' entries';
    }

    $txLines = [];
    foreach ($recentTxRefs as $tx) {
        $ref = $tx['transaction_ref'];
        $txLines[] = '  Transaction: ' . $ref . ' (' . $tx['source_table'] . ', ' . $tx['entry_date'] . ')';
        foreach ($recentEntries[$ref] ?? [] as $e) {
            $txLines[] = '    ' . strtoupper($e['entry_type']) . ' ' . ra_dollars((int)$e['amount_cents']) . ' → ' . $e['account_name'] . ' [' . $e['flow_category'] . '/' . $e['classification'] . ']';
        }
    }

    $today = date('d M Y');
    $systemPrompt = <<<SYS
You are the COGs of Australia Foundation Godley Reconciliation Agent — a read-only accounting and compliance analysis assistant for the CJVM Hybrid Trust. You have been provided with a live snapshot of the trust's ledger state as at {$today}.

AUTHORITY BOUNDARY — STRICTLY ENFORCED:
- You may analyse, explain, summarise, reconcile, and answer questions about the ledger data below.
- You may NOT initiate, approve, prepare, or simulate any trust operation, token mint, transfer, or governance action.
- You may NOT provide legal or financial advice. You may describe what the data shows.
- You may NOT reveal database credentials, member PII beyond what is in the snapshot, or internal system architecture details.
- If asked to perform a write operation of any kind, decline clearly and explain the boundary.

FOUNDATION BACKGROUND:
The COGs of Australia Foundation Community Joint Venture Mainspring Hybrid Trust operates under the Godley double-entry accounting methodology. Every transaction must net to zero across all sector accounts. There are 14 stewardship sectors (Sub-Trust A operating/admin/members pool, P-Class Suspense, Sub-Trust B, Sub-Trust C operating/gift fund, Trustee Admin, External ASX/Vendor/ATO/Grantee, and Member/Donor sectors). Twelve constitutional invariants (I1–I12) are continuously monitored — any violation is a potential breach of trust deed obligations.

INVARIANT DESCRIPTIONS:
I1: Sub-trust ring-fencing — no commingling between A, B, C except valid BDS/DDS transfers
I2: Dividend split exactness — BDS 50/50, DDS 50/25/25, max $0.01 rounding tolerance
I3: 5-business-day transfer — dividend split to Sub-Trust B within 5 business days of receipt
I4: 60-day Sub-Trust B distribution — 100% of STB inflows distributed within 60 calendar days
I5: 2-business-day Sub-Trust C direct — $2.00 direct transfer on Donation COG$ issue within 2 business days
I6: Partners Pool non-disposal — STA-PARTNERS-POOL may only receive inflows or permitted EXTERNAL-ASX asset swaps
I7: Anti-capture cap — no single member/entity may hold more than 1,000,000 Beneficial Units
I8: Fixed unit consideration — S-Class $4.00, kS-Class $1.00, B-Class $40.00 exactly
I9: No fiat redemption or secondary sale — no reverse MEMBER←STA fiat flows
I10: First Nations grant minimum 30% — FN grantees must receive ≥30% of Sub-Trust C grant outflows per FY
I11: Social justice mechanism — each S/kS Unit issue must activate exactly one Sub-Trust B income unit
I12: ASX Stewardship Season lock — Class A (ASX COG$) Units locked for 12 months from issue

LIVE LEDGER SNAPSHOT ({$today}):

=== CONSTITUTIONAL INVARIANTS ===
SYS;

    $systemPrompt .= implode("\n", $invLines);
    $systemPrompt .= "\n\n=== CONSOLIDATED BALANCE SHEET ===\n";
    $systemPrompt .= implode("\n", $consLines);
    $systemPrompt .= "\n\n=== SECTOR ACCOUNT BALANCES ===\n";
    $systemPrompt .= implode("\n", $sectorLines);
    $systemPrompt .= "\n\n=== 10 MOST RECENT TRANSACTION GROUPS ===\n";
    $systemPrompt .= empty($txLines) ? '  No transactions recorded yet.' : implode("\n", $txLines);
    $systemPrompt .= "\n\nAnswer questions about the above snapshot accurately and concisely. If a figure is not in the snapshot, say so rather than guessing. Keep responses focused and professional.";

    // ── Build messages array ──────────────────────────────────────────────────
    $messages = [];
    foreach ($history as $h) {
        $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string)($h['content'] ?? ''));
        if ($content !== '') {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    // ── Call Anthropic API ────────────────────────────────────────────────────
    $apiKey = ops_env('ANTHROPIC_API_KEY');
    if ($apiKey === '') {
        echo json_encode(['error' => 'ANTHROPIC_API_KEY not configured in .env — set it before using the agent.']);
        exit;
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        echo json_encode(['error' => 'Network error contacting Anthropic API: ' . $curlErr]);
        exit;
    }

    $resp = json_decode($raw ?: '{}', true) ?: [];

    if ($httpCode !== 200) {
        $errMsg = (string)($resp['error']['message'] ?? ('HTTP ' . $httpCode));
        echo json_encode(['error' => 'Anthropic API error: ' . $errMsg]);
        exit;
    }

    $reply = '';
    foreach ($resp['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $reply .= $block['text'];
        }
    }

    echo json_encode(['reply' => $reply, 'snapshot_date' => $today]);
    exit;
}

// ── Page data (GET) ───────────────────────────────────────────────────────────
$invStatus = ra_rows($pdo, "SELECT code, name, violation_count FROM v_godley_invariant_status ORDER BY code");
$totalViol = array_sum(array_column($invStatus, 'violation_count'));
$apiKeySet = ops_env('ANTHROPIC_API_KEY') !== '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Reconciliation | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>
:root {
  --bg:#0c1319; --panel:#17212b; --panel2:#1f2c38;
  --text:#eef2f7; --sub:#9fb0c1; --dim:#6b7f8f;
  --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.14);
  --gold:#d4b25c; --ok:#52b87a; --okb:rgba(82,184,122,.12);
  --warn:#c8901a; --warnb:rgba(200,144,26,.12);
  --err:#c46060; --errb:rgba(196,96,96,.12);
  --blue:#5a9ed4; --purple:#9b7dd4;
  --r:18px; --r2:12px;
}
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Inter,Arial,sans-serif; background:linear-gradient(160deg,var(--bg),#101b25 60%,var(--bg)); color:var(--text); min-height:100vh; }
a { color:inherit; text-decoration:none; }

.main { padding:24px 28px; min-width:0; display:flex; flex-direction:column; height:100vh; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:16px; flex-wrap:wrap; flex-shrink:0; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:600px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); }

/* Status strip */
.status-strip { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; flex-shrink:0; align-items:center; }
.inv-mini { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700; border:1px solid transparent; }
.inv-mini.ok  { background:rgba(82,184,122,.08);  border-color:rgba(82,184,122,.2);  color:var(--ok); }
.inv-mini.err { background:rgba(196,96,96,.10);   border-color:rgba(196,96,96,.3);   color:var(--err); }
.inv-mini .dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.inv-mini.ok  .dot { background:var(--ok); }
.inv-mini.err .dot { background:var(--err); box-shadow:0 0 5px var(--err); }

/* Authority banner */
.authority-banner { padding:10px 16px; border-radius:var(--r2); margin-bottom:14px; font-size:12px; background:rgba(155,125,212,.08); border:1px solid rgba(155,125,212,.25); color:#c8b8f0; flex-shrink:0; display:flex; gap:10px; align-items:center; }
.authority-banner strong { color:#d4c8f8; }

/* No-key warning */
.no-key-banner { padding:12px 16px; border-radius:var(--r2); margin-bottom:14px; font-size:13px; font-weight:600; background:var(--warnb); border:1px solid rgba(200,144,26,.3); color:#e8cc80; flex-shrink:0; }

/* Chat layout */
.chat-shell { flex:1; display:flex; flex-direction:column; background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; min-height:0; }
.chat-thread { flex:1; overflow-y:auto; padding:16px 20px; display:flex; flex-direction:column; gap:14px; }
.chat-thread:empty::before { content:'Ask me about invariant status, sector balances, recent transactions, or compliance deadlines.'; color:var(--dim); font-size:13px; align-self:center; margin-top:40px; }
.msg { display:flex; gap:10px; align-items:flex-start; }
.msg.user { flex-direction:row-reverse; }
.msg-avatar { width:30px; height:30px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; }
.msg.user .msg-avatar { background:rgba(212,178,92,.2); color:var(--gold); border:1px solid rgba(212,178,92,.3); }
.msg.agent .msg-avatar { background:rgba(90,158,212,.15); color:var(--blue); border:1px solid rgba(90,158,212,.25); }
.msg-bubble { max-width:75%; padding:10px 14px; border-radius:14px; font-size:13px; line-height:1.6; }
.msg.user .msg-bubble { background:rgba(212,178,92,.1); border:1px solid rgba(212,178,92,.2); color:var(--text); border-bottom-right-radius:4px; }
.msg.agent .msg-bubble { background:var(--panel2); border:1px solid var(--line2); color:var(--text); border-bottom-left-radius:4px; }
.msg-bubble p { margin-bottom:8px; }
.msg-bubble p:last-child { margin-bottom:0; }
.msg-bubble code { background:rgba(255,255,255,.08); padding:1px 5px; border-radius:4px; font-family:monospace; font-size:11.5px; }
.msg-bubble pre { background:rgba(0,0,0,.3); border-radius:8px; padding:10px 12px; overflow-x:auto; font-size:11.5px; font-family:monospace; margin:6px 0; }
.msg-bubble ul, .msg-bubble ol { padding-left:18px; margin:6px 0; }
.msg-bubble li { margin-bottom:3px; }
.msg-bubble strong { color:var(--gold); }

/* Thinking indicator */
.thinking { display:flex; gap:10px; align-items:flex-start; }
.thinking-dots { display:inline-flex; gap:5px; padding:12px 16px; background:var(--panel2); border:1px solid var(--line2); border-radius:14px; border-bottom-left-radius:4px; }
.thinking-dots span { width:6px; height:6px; border-radius:50%; background:var(--dim); animation:blink 1.2s infinite; }
.thinking-dots span:nth-child(2) { animation-delay:.2s; }
.thinking-dots span:nth-child(3) { animation-delay:.4s; }
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }

/* Input bar */
.chat-input-bar { display:flex; gap:10px; padding:14px 16px; border-top:1px solid var(--line); flex-shrink:0; background:var(--panel2); }
.chat-input { flex:1; background:var(--panel); border:1px solid var(--line2); border-radius:10px; color:var(--text); font-size:13px; padding:10px 14px; font-family:inherit; resize:none; line-height:1.5; max-height:120px; }
.chat-input:focus { outline:1px solid rgba(212,178,92,.35); }
.chat-input::placeholder { color:var(--dim); }
.chat-send { padding:10px 18px; border-radius:10px; border:1px solid rgba(212,178,92,.3); background:rgba(212,178,92,.12); color:var(--gold); font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; flex-shrink:0; transition:background .12s; }
.chat-send:hover:not(:disabled) { background:rgba(212,178,92,.22); }
.chat-send:disabled { opacity:.4; cursor:not-allowed; }

/* Suggested questions */
.suggestions { display:flex; flex-wrap:wrap; gap:7px; padding:10px 16px; border-top:1px solid var(--line); flex-shrink:0; }
.sug-btn { padding:5px 12px; border-radius:8px; font-size:11.5px; font-weight:600; border:1px solid var(--line); background:rgba(255,255,255,.03); color:var(--sub); cursor:pointer; transition:background .1s, color .1s; }
.sug-btn:hover { background:rgba(255,255,255,.07); color:var(--text); }

.err-msg { color:var(--err); font-size:12px; font-style:italic; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('reconciliation'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Reconciliation agent<?php echo ops_admin_help_button('Reconciliation agent', 'AI-powered read-only analysis of the Godley double-entry ledger. The agent has access to a live snapshot of invariant status, sector balances, and recent transactions. It cannot execute any trust operation.'); ?></h1>
    <p>Live ledger analysis for the CJVM Hybrid Trust. Ask about invariant violations, sector balances, recent transactions, or compliance deadlines.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?php echo ra_h(admin_url('accounting.php')); ?>">← Accounting</a>
    <a class="btn" href="<?php echo ra_h(admin_url('ledger.php')); ?>">Ledger</a>
  </div>
</div>

<!-- Invariant status strip -->
<div class="status-strip">
  <span style="font-size:11px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.06em">Live invariants:</span>
  <?php foreach ($invStatus as $inv):
    $viol = (int)$inv['violation_count'];
    $cls  = $viol === 0 ? 'ok' : 'err';
  ?>
  <div class="inv-mini <?php echo $cls; ?>" title="<?php echo ra_h($inv['name']); ?>">
    <span class="dot"></span>
    <span style="font-family:monospace;font-size:10px;opacity:.7"><?php echo ra_h($inv['code']); ?></span>
    <?php if ($viol > 0): ?><span style="background:var(--err);color:#fff;border-radius:4px;padding:0 4px;font-size:9px"><?php echo $viol; ?></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($totalViol === 0): ?>
    <span style="font-size:11px;color:var(--ok);font-weight:700;margin-left:4px">✓ All clear</span>
  <?php else: ?>
    <span style="font-size:11px;color:var(--err);font-weight:700;margin-left:4px">⚠ <?php echo $totalViol; ?> violation<?php echo $totalViol !== 1 ? 's' : ''; ?></span>
  <?php endif; ?>
</div>

<!-- Authority boundary banner -->
<div class="authority-banner">
  <span style="font-size:16px;flex-shrink:0">🔒</span>
  <span><strong>Read-only agent.</strong> This agent may analyse, explain, and reconcile ledger data. It cannot initiate, approve, or simulate any trust operation, token mint, transfer, or governance action. Per Godley Spec §7 Phase 5 and Rollout Doc Agent #8.</span>
</div>

<?php if (!$apiKeySet): ?>
<div class="no-key-banner">
  ⚠ ANTHROPIC_API_KEY not set in .env — add it to enable the agent. The chat interface is loaded but queries will return an error until the key is configured.
</div>
<?php endif; ?>

<!-- Chat shell -->
<div class="chat-shell">
  <div class="chat-thread" id="chatThread"></div>

  <div class="suggestions" id="suggestionsBar">
    <span style="font-size:10.5px;color:var(--dim);align-self:center;font-weight:600">Try:</span>
    <button class="sug-btn" type="button">Which invariants have violations?</button>
    <button class="sug-btn" type="button">What is the current Sub-Trust A balance?</button>
    <button class="sug-btn" type="button">Explain the most recent transactions.</button>
    <button class="sug-btn" type="button">Is there an I4 deadline risk?</button>
    <button class="sug-btn" type="button">Are the grand totals balanced?</button>
    <button class="sug-btn" type="button">Summarise the overall ledger health.</button>
  </div>

  <div class="chat-input-bar">
    <textarea class="chat-input" id="chatInput" placeholder="Ask about invariants, balances, transactions, or compliance deadlines…" rows="1"></textarea>
    <button class="chat-send" id="chatSend" type="button">Send</button>
  </div>
</div>

</main>
</div>

<script>
  // Server-rendered CSRF token. Sent in every fetch body to satisfy
  // admin_csrf_verify_json() in the POST handler above. Same per-session
  // token shared with form-based admin pages.
  window.__csrfToken = <?= json_encode(admin_csrf_token(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
</script>

<script>
(function(){
  var thread  = document.getElementById('chatThread');
  var input   = document.getElementById('chatInput');
  var sendBtn = document.getElementById('chatSend');
  var sugBar  = document.getElementById('suggestionsBar');
  var history = [];
  var busy    = false;

  /* ── Auto-resize textarea ── */
  input.addEventListener('input', function(){
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  /* ── Enter to send (shift+enter = newline) ── */
  input.addEventListener('keydown', function(e){
    if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); send(); }
  });

  sendBtn.addEventListener('click', send);

  /* ── Suggested questions ── */
  document.querySelectorAll('.sug-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      input.value = btn.textContent;
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
      sugBar.style.display = 'none';
      send();
    });
  });

  function send() {
    var msg = input.value.trim();
    if(!msg || busy) return;
    busy = true;
    sendBtn.disabled = true;
    sugBar.style.display = 'none';

    appendMsg('user', msg);
    history.push({ role:'user', content:msg });
    input.value = '';
    input.style.height = 'auto';

    var thinkingEl = appendThinking();

    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        _csrf: window.__csrfToken,
        message: msg,
        history: history.slice(0, -1)
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      thinkingEl.remove();
      if(data.error){
        appendMsg('agent', '**Error:** ' + data.error, true);
      } else {
        var reply = data.reply || '(no response)';
        appendMsg('agent', reply);
        history.push({ role:'assistant', content:reply });
      }
    })
    .catch(function(err){
      thinkingEl.remove();
      appendMsg('agent', '**Network error:** ' + err.message, true);
    })
    .finally(function(){
      busy = false;
      sendBtn.disabled = false;
      input.focus();
    });
  }

  function appendMsg(role, text, isError) {
    var wrap = document.createElement('div');
    wrap.className = 'msg ' + (role === 'user' ? 'user' : 'agent');

    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.textContent = role === 'user' ? 'A' : '◈';

    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    if(isError) bubble.classList.add('err-msg');
    bubble.innerHTML = renderMarkdown(text);

    wrap.appendChild(avatar);
    wrap.appendChild(bubble);
    thread.appendChild(wrap);
    thread.scrollTop = thread.scrollHeight;
    return wrap;
  }

  function appendThinking() {
    var wrap = document.createElement('div');
    wrap.className = 'thinking';
    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.style.cssText = 'width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;background:rgba(90,158,212,.15);color:var(--blue);border:1px solid rgba(90,158,212,.25)';
    avatar.textContent = '◈';
    var dots = document.createElement('div');
    dots.className = 'thinking-dots';
    dots.innerHTML = '<span></span><span></span><span></span>';
    wrap.appendChild(avatar);
    wrap.appendChild(dots);
    thread.appendChild(wrap);
    thread.scrollTop = thread.scrollHeight;
    return wrap;
  }

  /* ── Minimal markdown renderer ── */
  function renderMarkdown(text) {
    /* Escape HTML first */
    var t = text
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;');

    /* Code blocks */
    t = t.replace(/```[\s\S]*?```/g, function(m){
      return '<pre>' + m.slice(3, m.length-3).replace(/^[a-z]+\n/,'') + '</pre>';
    });
    /* Inline code */
    t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
    /* Bold */
    t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    /* Headers */
    t = t.replace(/^### (.+)$/gm, '<strong style="font-size:13px;display:block;margin-top:8px;color:var(--gold)">$1</strong>');
    t = t.replace(/^## (.+)$/gm,  '<strong style="font-size:13.5px;display:block;margin-top:8px;color:var(--gold)">$1</strong>');
    t = t.replace(/^# (.+)$/gm,   '<strong style="font-size:14px;display:block;margin-top:8px;color:var(--gold)">$1</strong>');
    /* Lists */
    t = t.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    t = t.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
    t = t.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
    /* Paragraphs — double newlines */
    t = t.replace(/\n\n+/g, '</p><p>');
    /* Single newlines inside paragraphs */
    t = t.replace(/\n/g, '<br>');
    return '<p>' + t + '</p>';
  }
})();
</script>
</body>
</html>
