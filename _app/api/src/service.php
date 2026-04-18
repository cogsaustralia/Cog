<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';

function cogs_canonical_payment_required(string $classCode, int $fallback = 0): int {
    return match (strtoupper(trim($classCode))) {
        'PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT', 'PAY_IT_FORWARD_COG', 'DONATION_COG' => 1,
        'LANDHOLDER_COG', 'ASX_INVESTMENT_COG', 'RWA_COG', 'LR_COG' => 0,
        default => $fallback,
    };
}

function svc_admin_login(PDO $pdo, string $email, string $password): array {
    $identifier = trim(strtolower($email));
    $stmt = $pdo->prepare('SELECT id, username, email, display_name, role_name, password_hash, is_active FROM admin_users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $admin = $stmt->fetch();
    if (!$admin || !password_verify($password, (string)$admin['password_hash'])) {
        cogs_json(['ok' => false, 'error' => 'Invalid email or password'], 422);
    }
    $legacyAdminId = cogs_find_legacy_admin_id_by_email($pdo, (string)$admin['email']);
    $_SESSION['admin_user'] = [
        'id' => (int)$admin['id'],
        'admin_user_id' => (int)$admin['id'],
        'legacy_admin_id' => $legacyAdminId,
        'username' => $admin['username'],
        'email' => $admin['email'],
        'display_name' => $admin['display_name'],
        'role_name' => $admin['role_name'],
    ];
    $_SESSION['admin'] = [
        'id' => $legacyAdminId ?: (int)$admin['id'],
        'email' => $admin['email'],
        'role' => $admin['role_name'],
        'name' => $admin['display_name'],
    ];
    $pdo->prepare('UPDATE admin_users SET last_login_at = ?, updated_at = ? WHERE id = ?')->execute([cogs_now(), cogs_now(), $admin['id']]);
    return $_SESSION['admin_user'];
}

function svc_create_token_class(PDO $pdo, array $input, array $admin): array {
    $code = strtoupper(trim((string)($input['class_code'] ?? '')));
    $name = trim((string)($input['display_name'] ?? ''));
    $memberType = trim((string)($input['member_type'] ?? 'both'));
    $price = (int)($input['unit_price_cents'] ?? 0);
    $canonicalPaymentRequired = cogs_canonical_payment_required($code, cogs_bool($input['payment_required'] ?? 0));
    $minUnits = (int)($input['min_units'] ?? 0);
    $maxUnits = (int)($input['max_units'] ?? 999999);
    $stepUnits = max(1, (int)($input['step_units'] ?? 1));
    $sortOrder = (int)($input['display_order'] ?? 999);

    if (!$code || !cogs_validate_class_code($code)) {
        cogs_json(['ok' => false, 'error' => 'Invalid class code'], 422);
    }
    if (!$name) {
        cogs_json(['ok' => false, 'error' => 'Display name is required'], 422);
    }
    if (!in_array($memberType, ['personal', 'business', 'both'], true)) {
        cogs_json(['ok' => false, 'error' => 'Invalid member type'], 422);
    }
    if ($price < 0 || $minUnits < 0 || $maxUnits < $minUnits) {
        cogs_json(['ok' => false, 'error' => 'Invalid pricing or limits'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM token_classes WHERE class_code = ? LIMIT 1');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        cogs_json(['ok' => false, 'error' => 'Class code already exists'], 409);
    }

    $ins = $pdo->prepare('INSERT INTO token_classes (class_code, display_name, member_type, unit_price_cents, min_units, max_units, step_units, display_order, is_active, is_locked, approval_required, payment_required, wallet_visible_by_default, wallet_editable_by_default, is_system_class, admin_creatable, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([
        $code,
        $name,
        $memberType,
        $price,
        $minUnits,
        $maxUnits,
        $stepUnits,
        $sortOrder,
        cogs_bool($input['is_active'] ?? 1),
        cogs_bool($input['is_locked'] ?? 0),
        cogs_bool($input['approval_required'] ?? 1),
        $canonicalPaymentRequired,
        cogs_bool($input['wallet_visible_by_default'] ?? 1),
        cogs_bool($input['wallet_editable_by_default'] ?? 1),
        0,
        1,
        cogs_admin_actor_id($pdo, $admin),
        cogs_now(),
        cogs_now(),
    ]);
    $id = (int)$pdo->lastInsertId();
    cogs_log_activity($pdo, null, $id, 'class_created', ['class_code' => $code], 'admin', $admin['id'] ?? null);
    return cogs_fetch_token_class($pdo, $id);
}

function svc_update_token_class(PDO $pdo, array $input, array $admin): array {
    $id = (int)($input['id'] ?? 0);
    $class = cogs_fetch_token_class($pdo, $id);
    if (!$class) cogs_json(['ok' => false, 'error' => 'Class not found'], 404);

    $protected = ['PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT'];
    $isProtected = in_array($class['class_code'], $protected, true);

    $displayName = trim((string)($input['display_name'] ?? $class['display_name']));
    $memberType = trim((string)($input['member_type'] ?? $class['member_type']));
    $price = isset($input['unit_price_cents']) ? (int)$input['unit_price_cents'] : (int)$class['unit_price_cents'];
    $minUnits = isset($input['min_units']) ? (int)$input['min_units'] : (int)$class['min_units'];
    $maxUnits = isset($input['max_units']) ? (int)$input['max_units'] : (int)$class['max_units'];
    $stepUnits = isset($input['step_units']) ? max(1, (int)$input['step_units']) : (int)$class['step_units'];
    $displayOrder = isset($input['display_order']) ? (int)$input['display_order'] : (int)$class['display_order'];
    $isActive = array_key_exists('is_active', $input) ? cogs_bool($input['is_active']) : (int)$class['is_active'];
    $walletVisible = array_key_exists('wallet_visible_by_default', $input) ? cogs_bool($input['wallet_visible_by_default']) : (int)$class['wallet_visible_by_default'];
    $walletEditable = array_key_exists('wallet_editable_by_default', $input) ? cogs_bool($input['wallet_editable_by_default']) : (int)$class['wallet_editable_by_default'];
    $approvalRequired = array_key_exists('approval_required', $input) ? cogs_bool($input['approval_required']) : (int)$class['approval_required'];
    $paymentRequired = array_key_exists('payment_required', $input) ? cogs_bool($input['payment_required']) : (int)$class['payment_required'];
    $paymentRequired = cogs_canonical_payment_required((string)$class['class_code'], $paymentRequired);
    $isLocked = array_key_exists('is_locked', $input) ? cogs_bool($input['is_locked']) : (int)$class['is_locked'];

    if ($isProtected) {
        $memberType = $class['member_type'];
        $isLocked = (int)$class['is_locked'];
    }

    $stmt = $pdo->prepare('UPDATE token_classes SET display_name = ?, member_type = ?, unit_price_cents = ?, min_units = ?, max_units = ?, step_units = ?, display_order = ?, is_active = ?, is_locked = ?, approval_required = ?, payment_required = ?, wallet_visible_by_default = ?, wallet_editable_by_default = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$displayName, $memberType, $price, $minUnits, $maxUnits, $stepUnits, $displayOrder, $isActive, $isLocked, $approvalRequired, $paymentRequired, $walletVisible, $walletEditable, cogs_now(), $id]);
    cogs_log_activity($pdo, null, $id, 'class_updated', ['display_name' => $displayName], 'admin', cogs_admin_user_actor_id($admin));
    return cogs_fetch_token_class($pdo, $id);
}

function svc_toggle_class(PDO $pdo, int $id, bool $active, array $admin): array {
    $class = cogs_fetch_token_class($pdo, $id);
    if (!$class) cogs_json(['ok' => false, 'error' => 'Class not found'], 404);
    $pdo->prepare('UPDATE token_classes SET is_active = ?, updated_at = ? WHERE id = ?')->execute([$active ? 1 : 0, cogs_now(), $id]);
    cogs_log_activity($pdo, null, $id, $active ? 'class_activated' : 'class_deactivated', [], 'admin', cogs_admin_user_actor_id($admin));
    return cogs_fetch_token_class($pdo, $id);
}

function svc_reorder_classes(PDO $pdo, array $items, array $admin): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE token_classes SET display_order = ?, updated_at = ? WHERE id = ?');
        foreach ($items as $item) {
            $stmt->execute([(int)$item['display_order'], cogs_now(), (int)$item['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cogs_log_activity($pdo, null, null, 'class_reordered', ['count' => count($items)], 'admin', cogs_admin_user_actor_id($admin));
    return repo_list_token_classes($pdo);
}

function svc_backfill_class(PDO $pdo, int $tokenClassId, array $options, array $admin): array {
    $class = cogs_fetch_token_class($pdo, $tokenClassId);
    if (!$class) cogs_json(['ok' => false, 'error' => 'Class not found'], 404);

    $memberType = $options['member_type'] ?? $class['member_type'];
    $eligibleTypes = $memberType === 'both' ? ['personal', 'business'] : [$memberType];
    $onlyActive = !empty($options['only_active_members']);

    $sql = 'SELECT id, member_type FROM members WHERE member_type IN (' . implode(',', array_fill(0, count($eligibleTypes), '?')) . ')';
    $params = $eligibleTypes;
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();

    $inserted = 0;
    $check = $pdo->prepare('SELECT id FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO member_reservation_lines (member_id, token_class_id, requested_units, approved_units, paid_units, approval_status, payment_status, created_at, updated_at) VALUES (?, ?, 0, 0, 0, ?, ?, ?, ?)');
    foreach ($members as $member) {
        if ($class['member_type'] !== 'both' && $class['member_type'] !== $member['member_type']) {
            continue;
        }
        $check->execute([(int)$member['id'], $tokenClassId]);
        if ($check->fetch()) {
            continue;
        }
        $paymentRequired = cogs_canonical_payment_required((string)$class['class_code'], (int)$class['payment_required']);
        $insert->execute([(int)$member['id'], $tokenClassId, $class['approval_required'] ? 'pending' : 'not_required', $paymentRequired ? 'pending' : 'not_required', cogs_now(), cogs_now()]);
        $inserted++;
    }
    cogs_log_activity($pdo, null, $tokenClassId, 'class_backfilled', ['inserted' => $inserted], 'admin', cogs_admin_user_actor_id($admin));
    return ['token_class_id' => $tokenClassId, 'inserted' => $inserted];
}

function svc_create_payment(PDO $pdo, array $input, array $admin): array {
    $stmt = $pdo->prepare('INSERT INTO payments (member_id, payment_type, amount_cents, currency_code, payment_status, external_reference, notes, received_at, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int)$input['member_id'],
        (string)($input['payment_type'] ?? 'manual'),
        (int)($input['amount_cents'] ?? 0),
        (string)($input['currency_code'] ?? 'AUD'),
        (string)($input['payment_status'] ?? 'paid'),
        (string)($input['external_reference'] ?? ''),
        (string)($input['notes'] ?? ''),
        $input['received_at'] ?? cogs_now(),
        cogs_admin_actor_id($pdo, $admin),
        cogs_now(),
        cogs_now(),
    ]);
    $paymentId = (int)$pdo->lastInsertId();
    cogs_log_activity($pdo, (int)$input['member_id'], null, 'payment_created', ['payment_id' => $paymentId], 'admin', cogs_admin_user_actor_id($admin));
    $sel = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $sel->execute([$paymentId]);
    return $sel->fetch();
}

function svc_allocate_payment(PDO $pdo, array $input, array $admin): array {
    $paymentId = (int)($input['payment_id'] ?? 0);
    $allocations = $input['allocations'] ?? [];
    if (!$paymentId || !is_array($allocations) || !$allocations) {
        cogs_json(['ok' => false, 'error' => 'Payment and allocations are required'], 422);
    }
    $paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $paymentStmt->execute([$paymentId]);
    $payment = $paymentStmt->fetch();
    if (!$payment) cogs_json(['ok' => false, 'error' => 'Payment not found'], 404);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO payment_allocations (payment_id, member_id, token_class_id, units_allocated, amount_cents, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($allocations as $allocation) {
            $insert->execute([
                $paymentId,
                (int)$payment['member_id'],
                (int)$allocation['token_class_id'],
                (int)$allocation['units_allocated'],
                (int)$allocation['amount_cents'],
                cogs_now(),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cogs_log_activity($pdo, (int)$payment['member_id'], null, 'payment_allocated', ['payment_id' => $paymentId], 'admin', cogs_admin_user_actor_id($admin));
    return ['payment_id' => $paymentId, 'allocated_count' => count($allocations)];
}

function svc_confirm_signup_payment(PDO $pdo, int $memberId, array $admin): array {
    $pdo->beginTransaction();
    try {
        $member = repo_get_member($pdo, $memberId);
        if (!$member) cogs_json(['ok' => false, 'error' => 'Member not found'], 404);
        foreach ($member['reservation_lines'] as $line) {
            if (!in_array($line['class_code'], ['PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT'], true)) {
                continue;
            }
            $approvedUnits = (int)$line['requested_units'];
            $stmt = $pdo->prepare('UPDATE member_reservation_lines SET paid_units = ?, approved_units = ?, approval_status = ?, payment_status = ?, approved_at = ?, approved_by_admin_id = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$approvedUnits, $approvedUnits, 'approved', 'paid', cogs_now(), cogs_admin_actor_id($pdo, $admin), cogs_now(), (int)$line['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cogs_log_activity($pdo, $memberId, null, 'signup_payment_confirmed', [], 'admin', cogs_admin_user_actor_id($admin));
    return repo_member_summary($pdo, $memberId);
}

function svc_set_approved_units(PDO $pdo, int $lineId, int $approvedUnits, string $status, array $admin, ?string $reason = null): array {
    $stmt = $pdo->prepare('SELECT rl.*, tc.class_code FROM member_reservation_lines rl JOIN token_classes tc ON tc.id = rl.token_class_id WHERE rl.id = ? LIMIT 1');
    $stmt->execute([$lineId]);
    $line = $stmt->fetch();
    if (!$line) cogs_json(['ok' => false, 'error' => 'Reservation line not found'], 404);
    if ($approvedUnits > (int)$line['requested_units']) {
        cogs_json(['ok' => false, 'error' => 'Approved units cannot exceed requested units'], 422);
    }
    $upd = $pdo->prepare('UPDATE member_reservation_lines SET approved_units = ?, approval_status = ?, approved_at = ?, approved_by_admin_id = ?, updated_at = ? WHERE id = ?');
    $upd->execute([$approvedUnits, $status, cogs_now(), cogs_admin_actor_id($pdo, $admin), cogs_now(), $lineId]);
    cogs_log_activity($pdo, (int)$line['member_id'], (int)$line['token_class_id'], 'line_approval_changed', ['approved_units' => $approvedUnits, 'status' => $status, 'reason' => $reason], 'admin', cogs_admin_user_actor_id($admin));
    $stmt->execute([$lineId]);
    return $stmt->fetch();
}
