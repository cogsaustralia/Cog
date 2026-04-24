<?php
declare(strict_types=1);

/**
 * tdr_gate.php
 * TDR prerequisite gate helper for admin pages.
 *
 * Usage at top of any admin page after ops_require_admin() and $pdo = ops_db():
 *
 *   require_once __DIR__ . '/includes/tdr_gate.php';
 *   tdr_gate($pdo, ['TDR-20260425-002','TDR-20260425-003'], 'ASX Purchases');
 *
 * If any listed TDR is not fully_executed, renders a blocking warning and exits.
 * Pass $soft=true to warn without blocking (for non-critical advisories).
 */

function tdr_gate(PDO $pdo, array $requiredRefs, string $operationLabel, bool $soft = false): void
{
    if (empty($requiredRefs)) return;

    try {
        $placeholders = implode(',', array_fill(0, count($requiredRefs), '?'));
        $stmt = $pdo->prepare(
            "SELECT decision_ref, title, status
             FROM trustee_decisions
             WHERE decision_ref IN ({$placeholders})
               AND status != 'fully_executed'
             ORDER BY decision_ref"
        );
        $stmt->execute($requiredRefs);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // DB error — fail open (don't block admin in case of schema mismatch)
        return;
    }

    if (empty($pending)) return;

    $statusLabels = [
        'draft'             => 'Draft — not yet issued for execution',
        'pending_execution' => 'Pending — execution token issued, awaiting trustee',
        'superseded'        => 'Superseded',
    ];

    $colour = $soft ? 'rgba(212,148,74' : 'rgba(192,85,58';
    $icon   = $soft ? '⚠' : '🔒';
    $title  = $soft
        ? "Advisory — {$operationLabel} should not proceed until the following TDRs are executed"
        : "Blocked — {$operationLabel} requires the following TDRs to be executed first";

    echo '<div style="background:' . $colour . ',.08);border:1px solid ' . $colour . ',.35);'
       . 'border-radius:8px;padding:16px 20px;margin-bottom:22px">';
    echo '<div style="font-size:.8rem;font-weight:700;color:' . ($soft ? 'var(--warn)' : 'var(--err)') . ';margin-bottom:10px">'
       . $icon . ' ' . htmlspecialchars($title, ENT_QUOTES) . '</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:.8rem">';
    echo '<thead><tr>'
       . '<th style="text-align:left;padding:5px 8px;color:var(--sub);font-weight:600;font-size:.72rem;border-bottom:1px solid rgba(255,255,255,.08)">Reference</th>'
       . '<th style="text-align:left;padding:5px 8px;color:var(--sub);font-weight:600;font-size:.72rem;border-bottom:1px solid rgba(255,255,255,.08)">Title</th>'
       . '<th style="text-align:left;padding:5px 8px;color:var(--sub);font-weight:600;font-size:.72rem;border-bottom:1px solid rgba(255,255,255,.08)">Current Status</th>'
       . '</tr></thead><tbody>';

    foreach ($pending as $row) {
        $statusLabel = $statusLabels[$row['status']] ?? $row['status'];
        echo '<tr style="border-bottom:1px solid rgba(255,255,255,.05)">';
        echo '<td style="padding:6px 8px"><a href="./trustee_decisions.php?id='
           . urlencode($row['decision_ref'])
           . '" style="color:var(--gold);font-family:monospace;font-size:.78rem;font-weight:700;text-decoration:none">'
           . htmlspecialchars($row['decision_ref'], ENT_QUOTES) . '</a></td>';
        echo '<td style="padding:6px 8px;color:var(--text)">'
           . htmlspecialchars($row['title'], ENT_QUOTES) . '</td>';
        echo '<td style="padding:6px 8px;color:var(--sub)">'
           . htmlspecialchars($statusLabel, ENT_QUOTES) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    if (!$soft) {
        echo '<p style="font-size:.78rem;color:var(--sub);margin-top:12px">'
           . 'Execute the above TDR(s) in '
           . '<a href="./trustee_decisions.php" style="color:var(--gold)">Trustee Decisions</a>'
           . ' before using this section.</p>';
    }

    echo '</div>';

    if (!$soft) {
        // Render a minimal shell so the page doesn't look broken, then stop
        // The calling page has already rendered its header/sidebar by this point,
        // so we just stop further output.
        echo '<div style="height:200px"></div>';
        // Close any open content divs — caller must have rendered admin-shell
        echo '</div><!-- .main --></div><!-- .admin-shell --></body></html>';
        exit;
    }
}
