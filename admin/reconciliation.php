<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();

$cards = [
    'Unallocated payments' => ops_fetch_val($pdo, "SELECT COUNT(*) FROM payments p LEFT JOIN (SELECT payment_id, SUM(amount_cents) alloc FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.payment_status='paid' AND COALESCE(a.alloc,0) < p.amount_cents"),
    'Funding ready for approval' => ops_fetch_val($pdo, "SELECT COUNT(*) FROM member_reservation_lines WHERE paid_units >= requested_units AND approved_units < requested_units"),
    'Pending approvals' => ops_fetch_val($pdo, "SELECT COUNT(*) FROM approval_requests WHERE request_status='pending'"),
    'Manual queue' => ops_table_exists($pdo,'mint_queue') ? ops_fetch_val($pdo, "SELECT COUNT(*) FROM mint_queue WHERE queue_status IN ('locked_manual','manual_hold','held_manual','ready_for_blockchain','minted_later')") : 0,
];
$unallocated = ops_fetch_all($pdo, "SELECT p.id, m.full_name, m.member_number, p.amount_cents, p.received_at, COALESCE(a.alloc,0) allocated_cents FROM payments p JOIN members m ON m.id=p.member_id LEFT JOIN (SELECT payment_id, SUM(amount_cents) alloc FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.payment_status='paid' AND COALESCE(a.alloc,0) < p.amount_cents ORDER BY p.id DESC");

ob_start();
ops_admin_help_assets_once();
?>
<style>
.recon-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.recon-metrics .card{padding:16px}
.recon-metrics .k{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:6px}
.recon-metrics .v{font-size:1.45rem;font-weight:800}
@media(max-width:980px){.recon-metrics{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.recon-metrics{grid-template-columns:1fr}}
</style>
<?= ops_admin_info_panel(
    'Stage 7 — Audit, diagnostics, and control review',
    'What this page does',
    'Reconciliation is the control-review page for money and entitlement drift between payments, allocations, approvals, and the manual queue. Use it to find items that are financially received but not yet fully matched to the next operational step.',
    [
        'Use this page to spot gaps in the money-to-entitlement chain before they become bigger operational errors.',
        'Treat the counts as control-review indicators, not as the page where the operational fix itself is always completed.',
        'Move to Payments, Approvals, or the legacy bridge pages to actually resolve the underlying record when needed.',
    ]
) ?>

<?= ops_admin_workflow_panel(
    'Typical workflow',
    'Reconciliation tells you where the chain between cash received and entitlement processing is incomplete.',
    [
        ['title' => 'Check the metrics', 'body' => 'Use the top counts to see whether there is drift between payments, approvals, and queue processing.'],
        ['title' => 'Review unallocated payments', 'body' => 'These rows show paid records where the amount received has not yet been fully allocated.'],
        ['title' => 'Move to the source page', 'body' => 'Use Payments, Approvals, or the queue pages to correct the underlying issue.'],
        ['title' => 'Recheck this page', 'body' => 'Return here to confirm the mismatch count has actually cleared.'],
    ]
) ?>

<?= ops_admin_guide_panel(
    'How to use this page',
    'This page is best used as a daily or pre-publication checkpoint. It shows where the operational ledger is out of balance between stages.',
    [
        ['title' => 'Unallocated payments', 'body' => 'Money has been marked paid, but not all of it has been distributed to payment allocations.'],
        ['title' => 'Funding ready for approval', 'body' => 'Reservation lines have enough paid units but still have not been fully approved.'],
        ['title' => 'Pending approvals', 'body' => 'Approval requests are still waiting for an operator decision.'],
        ['title' => 'Manual queue', 'body' => 'Legacy bridge items still sitting in manual processing states.'],
    ]
) ?>

<?= ops_admin_status_panel(
    'Status guide',
    'These counts are signals that the financial and entitlement chain needs review.',
    [
        ['label' => 'Non-zero metric', 'body' => 'Something in the payment-to-entitlement path still needs operator attention.'],
        ['label' => 'Unallocated payment row', 'body' => 'A paid record still has money that has not been fully allocated.'],
        ['label' => 'Funding ready for approval', 'body' => 'Paid reservation units are ready for approval review but are not yet approved.'],
        ['label' => 'Manual queue count', 'body' => 'Legacy bridge items remain in a manual state and should be checked against the authoritative path.'],
    ]
) ?>

<div class="recon-metrics">
<?php foreach($cards as $k=>$v): ?>
  <div class="card"><div class="k"><?= ops_h($k) ?></div><div class="v"><?= (int)$v ?></div></div>
<?php endforeach; ?>
</div>

<div class="section">
  <h2 style="margin-top:0">Unallocated payments<?= ops_admin_help_button('Unallocated payments', 'These are paid records where the received amount is greater than the amount currently allocated in payment_allocations. Use this as a control signal that the payment path is incomplete.') ?></h2>
  <p class="muted">Review these rows first when the reconciliation counts are not clean. A paid record should not stay partially allocated for long.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Partner<?= ops_admin_help_button('Partner', 'The person linked to the payment record. Use the Partner number to trace the payment on the source page if needed.') ?></th><th>Amount</th><th>Allocated<?= ops_admin_help_button('Allocated', 'This is the total currently matched into payment allocations. If it is less than the amount received, there is still unallocated money.') ?></th><th>Received</th></tr></thead>
      <tbody>
      <?php if(!$unallocated): ?><tr><td colspan="5">No unallocated payments.</td></tr><?php endif; ?>
      <?php foreach($unallocated as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= ops_h($p['full_name'].' · '.$p['member_number']) ?></td>
          <td>$<?= number_format(((int)$p['amount_cents'])/100,2) ?></td>
          <td>$<?= number_format(((int)$p['allocated_cents'])/100,2) ?></td>
          <td><?= ops_h($p['received_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('Reconciliation', 'reconciliation', $body);
