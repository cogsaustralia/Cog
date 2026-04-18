<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_paths.php';

function admin_nav_items(): array {
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => admin_url('dashboard.php')],
        'members' => ['label' => 'Members', 'href' => admin_url('members.php')],
        'payments' => ['label' => 'Payments', 'href' => admin_url('payments.php')],
        'reconciliation' => ['label' => 'Reconciliation', 'href' => admin_url('reconciliation.php')],
        'approvals' => ['label' => 'Approvals', 'href' => admin_url('approvals.php')],
        'mint_queue' => ['label' => 'Mint / Live Queue', 'href' => admin_url('mint_queue.php')],
        'classes' => ['label' => 'Classes', 'href' => admin_url('classes.php')],
        'email_access' => ['label' => 'Email / Access', 'href' => admin_url('email_access.php')],
        'email_templates' => ['label' => 'Email Templates', 'href' => admin_url('email_templates.php')],
        'audit' => ['label' => 'Audit', 'href' => admin_url('audit.php')],
        'exceptions' => ['label' => 'Exceptions', 'href' => admin_url('exceptions.php')],
        'settings' => ['label' => 'Settings', 'href' => admin_url('settings.php')],
    ];
}

function admin_render_header(string $active, string $title, string $subtitle = ''): void {
    $nav = admin_nav_items();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo ops_h($title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--bg:#0b1220;--panel:#111827;--panel2:#0f172a;--text:#f8fafc;--muted:#94a3b8;--line:#334155;--accent:#7dd3fc;--good:#34d399;--warn:#fbbf24;--bad:#fb7185}
    *{box-sizing:border-box} body{margin:0;background:linear-gradient(180deg,#09111f,#0f172a);color:var(--text);font-family:Arial,Helvetica,sans-serif}
    a{color:inherit} .shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .side{background:#0b1220;border-right:1px solid #223046;padding:24px 16px;position:sticky;top:0;height:100vh;overflow:auto}
    .brand{padding:8px 10px 18px}.brand h1{margin:0 0 6px;font-size:24px}.brand p{margin:0;color:var(--muted);font-size:13px;line-height:1.4}
    .nav{display:flex;flex-direction:column;gap:6px}.nav a{display:block;padding:11px 12px;border-radius:12px;text-decoration:none;color:#dbe7f3;border:1px solid transparent}.nav a:hover{background:#121d32;border-color:#233149}.nav a.active{background:#13233b;color:#7dd3fc;border-color:#26405e;font-weight:700}
    .sidefoot{margin-top:18px;padding:14px 10px;border-top:1px solid #223046;color:var(--muted);font-size:13px}.sidefoot a{text-decoration:none;color:#7dd3fc}
    .main{padding:28px 22px 40px}.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}.title h1{margin:0 0 8px;font-size:32px}.title p{margin:0;color:var(--muted)}
    .topactions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:#162032;color:var(--text);text-decoration:none;font-weight:700}.btn.primary{background:var(--accent);color:#07111f;border-color:transparent}.btn.ghost{color:var(--accent)}
    .panel{background:rgba(17,24,39,.97);border:1px solid var(--line);border-radius:18px;padding:18px}.panel h2{margin:0 0 10px;font-size:20px}.sub{margin:0 0 14px;color:var(--muted)}
    .grid{display:grid;gap:16px}.grid.two{grid-template-columns:1.1fr .9fr}.grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.grid.four{grid-template-columns:repeat(4,minmax(0,1fr))}
    .msg,.err{padding:12px 14px;border-radius:12px;margin:0 0 16px}.msg{background:#0f2f1f;border:1px solid #166534;color:#dcfce7}.err{background:#3f1d1d;border:1px solid #7f1d1d;color:#fee2e2}
    table{width:100%;border-collapse:collapse} th,td{text-align:left;padding:10px 8px;border-top:1px solid #223045;font-size:14px;vertical-align:top} th{color:var(--muted);font-weight:600;border-top:none;padding-top:0}
    .badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:#172132}.good{color:#86efac;border-color:#166534;background:#0f2f1f}.warn{color:#fde68a;border-color:#854d0e;background:#3b2a0c}.bad{color:#fda4af;border-color:#9f1239;background:#3b0d1f}
    input,select,textarea{width:100%;padding:10px 11px;border-radius:10px;border:1px solid #334155;background:#020617;color:#f8fafc} textarea{min-height:100px;resize:vertical}
    label{display:block;margin:0 0 6px;color:#cbd5e1;font-size:14px} .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.row.three{grid-template-columns:repeat(3,1fr)} .stack{display:grid;gap:12px}
    button{padding:10px 14px;border-radius:10px;border:1px solid #334155;background:#1e293b;color:#f8fafc;cursor:pointer;font-weight:700}
    button.primary{background:linear-gradient(90deg,#7dd3fc,#c084fc);color:#08111f;border:0}
    .muted{color:var(--muted)} .kpi{font-size:28px;font-weight:800}.small{font-size:12px} .mono{font-family:monospace;white-space:pre-wrap}
    @media (max-width: 980px){.shell{grid-template-columns:1fr}.side{position:relative;height:auto}.grid.two,.grid.three,.grid.four,.row,.row.three{grid-template-columns:1fr}.main{padding:20px 14px 34px}}
  </style>
</head>
<body>
<div class="shell">
  <aside class="side">
    <div class="brand"><h1>COG$ Admin</h1><p>Stage 4 compliance + queue controls</p></div>
    <nav class="nav">
      <?php foreach ($nav as $key => $item): ?>
        <a class="<?php echo $key === $active ? 'active' : ''; ?>" href="<?php echo ops_h($item['href']); ?>"><?php echo ops_h($item['label']); ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidefoot">Logged in as admin user #<?php echo ops_h((string)($_SESSION['admin_user_id'] ?? '')); ?><?php if (!empty($_SESSION['admin_id']) && (string)($_SESSION['admin_id'] ?? '') !== (string)($_SESSION['admin_user_id'] ?? '')): ?><br><span class="small">Legacy bridge #<?php echo ops_h((string)$_SESSION['admin_id']); ?></span><?php endif; ?><br><a href="<?php echo ops_h(admin_url('logout.php')); ?>">Log out</a></div>
  </aside>
  <main class="main">
    <div class="topbar">
      <div class="title"><h1><?php echo ops_h($title); ?></h1><?php if ($subtitle !== ''): ?><p><?php echo ops_h($subtitle); ?></p><?php endif; ?></div>
      <div class="topactions"><a class="btn ghost" href="<?php echo ops_h(admin_url('dashboard.php')); ?>">Home</a></div>
    </div>
    <?php
}

function admin_render_footer(): void {
    echo "</main></div></body></html>";
}
