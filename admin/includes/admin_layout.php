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
  <title><?php echo ops_h($title); ?> — COG$ Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?php echo ops_h(admin_url('assets/admin.min.css')); ?>">
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
