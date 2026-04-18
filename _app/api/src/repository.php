<?php
require_once __DIR__ . '/bootstrap.php';

function repo_list_token_classes(PDO $pdo): array {
    return $pdo->query('SELECT * FROM token_classes ORDER BY display_order ASC, id ASC')->fetchAll();
}

function repo_list_members(PDO $pdo, ?string $memberType = null): array {
    if ($memberType && in_array($memberType, ['personal', 'business'], true)) {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE member_type = ? ORDER BY created_at DESC');
        $stmt->execute([$memberType]);
        return $stmt->fetchAll();
    }
    return $pdo->query('SELECT * FROM members ORDER BY created_at DESC')->fetchAll();
}

function repo_get_member(PDO $pdo, int $memberId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    if (!$member) return null;

    $lineStmt = $pdo->prepare('SELECT rl.*, tc.class_code, tc.display_name, tc.unit_price_cents, tc.display_order, tc.member_type AS class_member_type, tc.is_locked, tc.approval_required, tc.payment_required, tc.wallet_visible_by_default, tc.wallet_editable_by_default FROM member_reservation_lines rl JOIN token_classes tc ON tc.id = rl.token_class_id WHERE rl.member_id = ? ORDER BY tc.display_order ASC, tc.id ASC');
    $lineStmt->execute([$memberId]);
    $member['reservation_lines'] = $lineStmt->fetchAll();

    $paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE member_id = ? ORDER BY created_at DESC');
    $paymentStmt->execute([$memberId]);
    $member['payments'] = $paymentStmt->fetchAll();

    return $member;
}

function repo_member_summary(PDO $pdo, int $memberId): array {
    $stmt = $pdo->prepare('SELECT * FROM v_member_wallet_summary WHERE member_id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    return $stmt->fetch() ?: [];
}
