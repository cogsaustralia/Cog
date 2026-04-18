<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$labels = ops_label_settings($pdo);
$partnerLabel = $labels['public_label_partner'] ?? 'Partner';
$contributionLabel = $labels['public_label_contribution'] ?? 'partnership contribution';
$internalMemberLabel = $labels['internal_label_member'] ?? 'Member';

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function msg_rows(PDO $pdo, string $sql, array $params = []): array { try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; } }
function msg_one(PDO $pdo, string $sql, array $params = []): ?array { try { return ops_fetch_one($pdo, $sql, $params); } catch (Throwable $e) { return null; } }
function msg_val(PDO $pdo, string $sql, array $params = [], int $default = 0): int { try { return (int)ops_fetch_val($pdo, $sql, $params); } catch (Throwable $e) { return $default; } }
function msg_has_col(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}
function msg_now_sql(): string { return gmdate('Y-m-d H:i:s'); }
function msg_parse_dt_local(string $value): ?string {
    $value = trim($value);
    if ($value === '') { return null; }
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}
function msg_excerpt(string $text, int $limit = 160): string {
    $plain = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));
    if ($plain === '') { return ''; }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plain) > $limit ? (mb_substr($plain, 0, $limit - 1) . '…') : $plain;
    }
    return strlen($plain) > $limit ? (substr($plain, 0, $limit - 1) . '…') : $plain;
}
function msg_scope_from_legacy_audience(?string $aud): string {
    return match ((string)$aud) {
        'snft', 'personal' => 'personal',
        'bnft', 'business' => 'business',
        'landholder' => 'landholder',
        default => 'all',
    };
}
function msg_legacy_audience_from_scope(string $scope): string {
    return match ($scope) {
        'personal' => 'snft',
        'business' => 'bnft',
        default => 'all',
    };
}
function msg_wallet_status_from_ui(string $uiStatus, ?string $openAt): string {
    return match ($uiStatus) {
        'closed' => 'archived',
        'draft' => 'draft',
        default => ($openAt !== null && strtotime($openAt) > time()) ? 'scheduled' : 'sent',
    };
}
function msg_wallet_status_to_ui(?string $status): string {
    return match ((string)$status) {
        'archived' => 'closed',
        'scheduled', 'sent' => 'open',
        default => 'draft',
    };
}
function msg_schedule_status_from_ui(string $uiStatus, ?string $openAt): string {
    return match ($uiStatus) {
        'closed' => 'closed',
        'draft' => 'draft',
        default => ($openAt !== null && strtotime($openAt) > time()) ? 'scheduled' : 'open',
    };
}
function msg_schedule_status_to_ui(?string $status): string {
    return match ((string)$status) {
        'closed', 'archived', 'resolved', 'withdrawn', 'executed' => 'closed',
        'open', 'scheduled', 'submitted', 'sponsored', 'declared' => 'open',
        default => 'draft',
    };
}
function msg_proposal_register_status(string $uiStatus, ?string $openAt): string {
    return match ($uiStatus) {
        'closed' => 'resolved',
        'draft' => 'draft',
        default => ($openAt !== null && strtotime($openAt) > time()) ? 'submitted' : 'open',
    };
}
function msg_community_poll_status(string $uiStatus, ?string $openAt): string {
    return match ($uiStatus) {
        'closed' => 'closed',
        'draft' => 'draft',
        default => ($openAt !== null && strtotime($openAt) > time()) ? 'deliberation' : 'open',
    };
}
function msg_flagged_terms(string $text): array {
    $patterns = [
        'member' => '/\bmember(s)?\b/i',
        'membership' => '/\bmembership\b/i',
        'scheme' => '/\bscheme\b/i',
        'fund' => '/\bfund(s| manager)?\b/i',
        'AFSL' => '/\bAFSL\b/i',
        'PDS' => '/\bPDS\b|\bProduct Disclosure Statement\b/i',
        'MIS' => '/\bMIS\b|managed investment scheme/i',
    ];
    $hits = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $text)) {
            $hits[] = $label;
        }
    }
    return $hits;
}
function msg_warning_html(array $terms): string {
    if (!$terms) { return ''; }
    return 'Language review: legacy or regulatory terms detected — ' . implode(', ', $terms) . '.';
}
function msg_status_badge(string $uiStatus): string {
    $class = match ($uiStatus) {
        'open' => 'badge-open',
        'closed' => 'badge-closed',
        default => 'badge-draft',
    };
    return '<span class="badge ' . $class . '">' . h($uiStatus) . '</span>';
}
function msg_parse_choices_text(string $choices): array {
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $choices))));
}
function msg_choices_to_schema(array $choices): string {
    return json_encode(array_map(static fn(string $label, int $idx): array => [
        'code' => 'choice_' . ($idx + 1),
        'label' => $label,
    ], $choices, array_keys($choices)), JSON_UNESCAPED_SLASHES) ?: '[]';
}
function msg_schema_to_choices_text(?string $schema): string {
    $rows = json_decode((string)$schema, true);
    $labels = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['label'])) { $labels[] = (string)$row['label']; }
            elseif (is_string($row)) { $labels[] = $row; }
        }
    }
    return $labels ? implode("\n", $labels) : "Yes\nNo";
}

$section = trim((string)($_GET['section'] ?? ''));
if ($section === 'binding_polls') {
    $section = 'community_polls';
}
$flash = null;
$error = null;
$warning = null;
$adminUserId = function_exists('ops_current_admin_user_id') ? ops_current_admin_user_id($pdo) : null;
$legacyAdminId = function_exists('ops_legacy_admin_write_id') ? ops_legacy_admin_write_id($pdo) : (function_exists('ops_admin_id') ? ops_admin_id() : null);
$adminName = function_exists('ops_admin_name') ? ops_admin_name() : 'Administrator';
$annHasStatus = msg_has_col($pdo, 'announcements', 'status');
$annHasOpens = msg_has_col($pdo, 'announcements', 'opens_at');
$annHasCloses = msg_has_col($pdo, 'announcements', 'closes_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            $key = trim((string)($_POST['template_key'] ?? ''));
            $subject = trim((string)($_POST['subject_line'] ?? ''));
            $body = trim((string)($_POST['body_text'] ?? ''));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($key === '' || $subject === '' || $body === '') {
                throw new RuntimeException('Template key, subject, and body are required.');
            }
            $terms = array_unique(array_merge(msg_flagged_terms($subject), msg_flagged_terms($body)));
            if ($id > 0) {
                $pdo->prepare("UPDATE email_templates SET template_key=?, subject_line=?, body_text=?, is_active=?, updated_by_admin_id=?, updated_at=NOW() WHERE id=?")
                    ->execute([$key, $subject, $body, $isActive, $legacyAdminId, $id]);
                $flash = 'Template updated.';
            } else {
                $pdo->prepare("INSERT INTO email_templates (template_key, subject_line, body_text, is_active, updated_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$key, $subject, $body, $isActive, $legacyAdminId]);
                $flash = 'Template created.';
            }
            $warning = msg_warning_html($terms);
            $section = 'email_templates';
        }

        if ($action === 'save_wallet_notice') {
            $id = (int)($_POST['id'] ?? 0);
            $scope = trim((string)($_POST['audience'] ?? 'all'));
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $uiStatus = trim((string)($_POST['status'] ?? 'draft'));
            $openAt = msg_parse_dt_local((string)($_POST['open_at'] ?? ''));
            $closeAt = msg_parse_dt_local((string)($_POST['close_at'] ?? ''));
            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and body are required.');
            }
            $messageKey = 'wallet-notice-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            $dbStatus = msg_wallet_status_from_ui($uiStatus, $openAt);
            $legacyAudience = msg_legacy_audience_from_scope($scope);
            $summary = msg_excerpt($body);
            $sentAt = $dbStatus === 'draft' ? null : ($openAt ?? msg_now_sql());
            $terms = array_unique(array_merge(msg_flagged_terms($title), msg_flagged_terms($body)));
            if ($id > 0) {
                $existing = msg_one($pdo, "SELECT id, message_key FROM wallet_messages WHERE id=? LIMIT 1", [$id]);
                if (!$existing) { throw new RuntimeException('Wallet notice not found.'); }
                $messageKey = (string)($existing['message_key'] ?? $messageKey);
                $pdo->prepare("UPDATE wallet_messages SET audience=?, title=?, message_key=?, audience_scope=?, subject=?, summary=?, body=?, message_type='notice', priority='normal', status=?, sent_at=?, expires_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$legacyAudience, $title, $messageKey, $scope, $title, $summary, $body, $dbStatus, $sentAt, $closeAt, $legacyAdminId, $id]);
                $flash = 'Partner notice updated.';
            } else {
                $pdo->prepare("INSERT INTO wallet_messages (audience, title, message_key, audience_scope, subject, summary, body, message_type, priority, status, sent_at, expires_at, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'notice', 'normal', ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$legacyAudience, $title, $messageKey, $scope, $title, $summary, $body, $dbStatus, $sentAt, $closeAt, $legacyAdminId, $legacyAdminId]);
                $flash = 'Partner notice created.';
            }
            $warning = msg_warning_html($terms);
            $section = 'wallet_messages';
        }

        if ($action === 'save_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $aud = trim((string)($_POST['audience'] ?? 'all'));
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $uiStatus = trim((string)($_POST['status'] ?? 'draft'));
            $openAt = msg_parse_dt_local((string)($_POST['open_at'] ?? ''));
            $closeAt = msg_parse_dt_local((string)($_POST['close_at'] ?? ''));
            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and body are required.');
            }
            $terms = array_unique(array_merge(msg_flagged_terms($title), msg_flagged_terms($body)));
            $dbStatus = msg_schedule_status_from_ui($uiStatus, $openAt);
            if ($annHasStatus && $annHasOpens && $annHasCloses) {
                if ($id > 0) {
                    $pdo->prepare("UPDATE announcements SET audience=?, title=?, body=?, status=?, opens_at=?, closes_at=?, created_by=?, updated_by_admin_id=?, updated_at=NOW() WHERE id=?")
                        ->execute([$aud, $title, $body, $dbStatus, $openAt, $closeAt, $adminName, $legacyAdminId, $id]);
                    $flash = 'Announcement updated.';
                } else {
                    $pdo->prepare("INSERT INTO announcements (audience, title, body, status, opens_at, closes_at, created_by, updated_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$aud, $title, $body, $dbStatus, $openAt, $closeAt, $adminName, $legacyAdminId]);
                    $flash = 'Announcement created.';
                }
            } else {
                if ($id > 0) {
                    $pdo->prepare("UPDATE announcements SET audience=?, title=?, body=?, created_by=? WHERE id=?")
                        ->execute([$aud, $title, $body, $adminName, $id]);
                    $flash = 'Announcement updated.';
                } else {
                    $pdo->prepare("INSERT INTO announcements (audience, title, body, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                        ->execute([$aud, $title, $body, $adminName]);
                    $flash = 'Announcement created.';
                }
            }
            $warning = msg_warning_html($terms);
            $section = 'announcements';
        }

        if ($action === 'save_proposal') {
            $id = (int)($_POST['id'] ?? 0);
            $scope = trim((string)($_POST['audience'] ?? 'all'));
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $uiStatus = trim((string)($_POST['status'] ?? 'draft'));
            $openAt = msg_parse_dt_local((string)($_POST['open_at'] ?? ''));
            $closeAt = msg_parse_dt_local((string)($_POST['close_at'] ?? ''));
            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and body are required.');
            }
            $summary = msg_excerpt($body);
            $dbStatus = msg_schedule_status_from_ui($uiStatus, $openAt);
            $proposalKey = 'proposal-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            $terms = array_unique(array_merge(msg_flagged_terms($title), msg_flagged_terms($body)));
            if ($id > 0) {
                $existing = msg_one($pdo, "SELECT id, proposal_key FROM vote_proposals WHERE id=? LIMIT 1", [$id]);
                if (!$existing) { throw new RuntimeException('Proposal not found.'); }
                $proposalKey = (string)($existing['proposal_key'] ?? $proposalKey);
                $pdo->prepare("UPDATE vote_proposals SET proposal_key=?, title=?, summary=?, body=?, audience_scope=?, proposal_type='opinion', status=?, starts_at=?, closes_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$proposalKey, $title, $summary, $body, $scope, $dbStatus, $openAt, $closeAt, $legacyAdminId, $id]);
                $flash = 'Proposal updated.';
            } else {
                $pdo->prepare("INSERT INTO vote_proposals (proposal_key, title, summary, body, audience_scope, proposal_type, status, starts_at, closes_at, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'opinion', ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$proposalKey, $title, $summary, $body, $scope, $dbStatus, $openAt, $closeAt, $legacyAdminId, $legacyAdminId]);
                $id = (int)$pdo->lastInsertId();
                $flash = 'Proposal created.';
            }
            if (ops_has_table($pdo, 'proposal_register')) {
                $bridgeStatus = msg_proposal_register_status($uiStatus, $openAt);
                $bridge = msg_one($pdo, "SELECT id FROM proposal_register WHERE proposal_key=? LIMIT 1", [$proposalKey]);
                if ($bridge) {
                    $pdo->prepare("UPDATE proposal_register SET title=?, proposal_type='governance', summary=?, body=?, origin_type='admin', status=?, updated_at=NOW() WHERE id=?")
                        ->execute([$title, $summary, $body, $bridgeStatus, $bridge['id']]);
                } else {
                    $pdo->prepare("INSERT INTO proposal_register (proposal_key, title, proposal_type, summary, body, origin_type, status, created_at, updated_at) VALUES (?, ?, 'governance', ?, ?, 'admin', ?, NOW(), NOW())")
                        ->execute([$proposalKey, $title, $summary, $body, $bridgeStatus]);
                }
            }
            $warning = msg_warning_html($terms);
            $section = 'proposals';
        }

        if ($action === 'save_poll') {
            $id = (int)($_POST['id'] ?? 0);
            $scope = trim((string)($_POST['audience'] ?? 'all'));
            $question = trim((string)($_POST['question'] ?? ''));
            $choices = trim((string)($_POST['choices'] ?? "Yes\nNo"));
            $body = trim((string)($_POST['body'] ?? ''));
            $uiStatus = trim((string)($_POST['status'] ?? 'draft'));
            $openAt = msg_parse_dt_local((string)($_POST['open_at'] ?? ''));
            $closeAt = msg_parse_dt_local((string)($_POST['close_at'] ?? ''));
            if ($question === '' || $choices === '') {
                throw new RuntimeException('Question and choices are required.');
            }
            $choiceValues = msg_parse_choices_text($choices);
            $ballotSchema = msg_choices_to_schema($choiceValues);
            $summary = msg_excerpt($question, 140);
            $dbStatus = msg_schedule_status_from_ui($uiStatus, $openAt);
            $pollKey = 'poll-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
            $legacyAudience = msg_legacy_audience_from_scope($scope);
            $terms = array_unique(array_merge(msg_flagged_terms($question), msg_flagged_terms($body)));
            if ($id > 0) {
                $existing = msg_one($pdo, "SELECT id, poll_key, community_poll_id FROM wallet_polls WHERE id=? LIMIT 1", [$id]);
                if (!$existing) { throw new RuntimeException('Community poll not found.'); }
                $pollKey = (string)($existing['poll_key'] ?? $pollKey);
                $pdo->prepare("UPDATE wallet_polls SET audience=?, question=?, poll_key=?, title=?, summary=?, body=?, ballot_schema=?, audience_scope=?, status=?, opens_at=?, closes_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$legacyAudience, $question, $pollKey, $question, $summary, $body !== '' ? $body : null, $ballotSchema, $scope, $dbStatus, $openAt, $closeAt, $legacyAdminId, $id]);
                $communityPollId = (int)($existing['community_poll_id'] ?? 0);
                $flash = 'Community poll updated.';
            } else {
                $pdo->prepare("INSERT INTO wallet_polls (audience, question, poll_key, title, summary, body, ballot_schema, poll_type, audience_scope, status, opens_at, closes_at, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'binding_resolution', ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$legacyAudience, $question, $pollKey, $question, $summary, $body !== '' ? $body : null, $ballotSchema, $scope, $dbStatus, $openAt, $closeAt, $legacyAdminId, $legacyAdminId]);
                $id = (int)$pdo->lastInsertId();
                $communityPollId = 0;
                $flash = 'Community poll created.';
            }
            if (ops_has_table($pdo, 'community_polls')) {
                $bridgeStatus = msg_community_poll_status($uiStatus, $openAt);
                if ($communityPollId <= 0) {
                    $bridge = msg_one($pdo, "SELECT id FROM community_polls WHERE poll_key=? LIMIT 1", [$pollKey]);
                    $communityPollId = (int)($bridge['id'] ?? 0);
                }
                if ($communityPollId > 0) {
                    $pdo->prepare("UPDATE community_polls SET title=?, summary=?, body=?, resolution_type='ordinary', eligibility_scope=?, voting_opens_at=?, voting_closes_at=?, status=?, created_by_admin_user_id=?, updated_at=NOW() WHERE id=?")
                        ->execute([$question, $summary, $body !== '' ? $body : null, $scope, $openAt, $closeAt, $bridgeStatus, $adminUserId, $communityPollId]);
                } else {
                    $pdo->prepare("INSERT INTO community_polls (poll_key, title, summary, body, resolution_type, eligibility_scope, voting_opens_at, voting_closes_at, status, created_by_admin_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, 'ordinary', ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$pollKey, $question, $summary, $body !== '' ? $body : null, $scope, $openAt, $closeAt, $bridgeStatus, $adminUserId]);
                    $communityPollId = (int)$pdo->lastInsertId();
                }
                if ($communityPollId > 0 && ops_has_table($pdo, 'poll_options')) {
                    $pdo->prepare("DELETE FROM poll_options WHERE community_poll_id=?")->execute([$communityPollId]);
                    $insertOpt = $pdo->prepare("INSERT INTO poll_options (community_poll_id, option_code, option_label, display_order) VALUES (?, ?, ?, ?)");
                    foreach ($choiceValues as $idx => $label) {
                        $insertOpt->execute([$communityPollId, 'choice_' . ($idx + 1), $label, $idx + 1]);
                    }
                }
                if (msg_has_col($pdo, 'wallet_polls', 'community_poll_id')) {
                    $pdo->prepare("UPDATE wallet_polls SET community_poll_id=? WHERE id=?")->execute([$communityPollId ?: null, $id]);
                }
            }
            $warning = msg_warning_html($terms);
            $section = 'community_polls';
        }

        if ($action === 'close_early') {
            $target = (string)($_POST['target'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Record missing.');
            }
            $now = msg_now_sql();
            if ($target === 'wallet_messages') {
                $pdo->prepare("UPDATE wallet_messages SET status='archived', expires_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$now, $legacyAdminId, $id]);
                $flash = 'Partner notice closed early.';
                $section = 'wallet_messages';
            } elseif ($target === 'announcements') {
                if ($annHasStatus && $annHasCloses) {
                    $pdo->prepare("UPDATE announcements SET status='closed', closes_at=?, updated_by_admin_id=?, updated_at=NOW() WHERE id=?")
                        ->execute([$now, $legacyAdminId, $id]);
                    $flash = 'Announcement closed early.';
                } else {
                    throw new RuntimeException('Announcements need status/open/close support before early close can be used.');
                }
                $section = 'announcements';
            } elseif ($target === 'vote_proposals') {
                $existing = msg_one($pdo, "SELECT proposal_key FROM vote_proposals WHERE id=? LIMIT 1", [$id]);
                $pdo->prepare("UPDATE vote_proposals SET status='closed', closes_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$now, $legacyAdminId, $id]);
                if (!empty($existing['proposal_key']) && ops_has_table($pdo, 'proposal_register')) {
                    $pdo->prepare("UPDATE proposal_register SET status='resolved', updated_at=NOW() WHERE proposal_key=?")
                        ->execute([$existing['proposal_key']]);
                }
                $flash = 'Proposal closed early.';
                $section = 'proposals';
            } elseif ($target === 'wallet_polls') {
                $existing = msg_one($pdo, "SELECT poll_key, community_poll_id FROM wallet_polls WHERE id=? LIMIT 1", [$id]);
                $pdo->prepare("UPDATE wallet_polls SET status='closed', closes_at=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$now, $legacyAdminId, $id]);
                if (!empty($existing['community_poll_id']) && ops_has_table($pdo, 'community_polls')) {
                    $pdo->prepare("UPDATE community_polls SET status='closed', voting_closes_at=COALESCE(voting_closes_at, ?), updated_at=NOW() WHERE id=?")
                        ->execute([$now, $existing['community_poll_id']]);
                } elseif (!empty($existing['poll_key']) && ops_has_table($pdo, 'community_polls')) {
                    $pdo->prepare("UPDATE community_polls SET status='closed', voting_closes_at=COALESCE(voting_closes_at, ?), updated_at=NOW() WHERE poll_key=?")
                        ->execute([$now, $existing['poll_key']]);
                }
                $flash = 'Community poll closed early.';
                $section = 'community_polls';
            } else {
                throw new RuntimeException('Unsupported close action.');
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cards = [
    'wallet_messages' => ['label' => 'Partner Notices', 'desc' => 'Targeted operational notices for Partners and wallets.', 'ico' => '✉'],
    'announcements' => ['label' => 'News', 'desc' => 'General community news and updates — no individual read tracking required.', 'ico' => '📰'],
    'proposals' => ['label' => 'Partner Proposals', 'desc' => 'Consultation and governance proposal threads.', 'ico' => '🗳'],
    'community_polls' => ['label' => 'Partner Polls', 'desc' => 'Formal Partner poll administration and scheduling.', 'ico' => '⚖'],
    'stewardship_responses' => ['label' => 'Stewardship Responses', 'desc' => 'Review stewardship responses for targeted outreach and follow-up.', 'ico' => '◈'],
    'email_templates' => ['label' => 'Email Templates', 'desc' => 'Reusable email templates with language review.', 'ico' => '📋'],
    'language_audit' => ['label' => 'Language Audit', 'desc' => 'Find stale member / scheme / AFSL language before it reaches Partners.', 'ico' => '🔎'],
    'email_access' => ['label' => 'Email Access', 'desc' => 'Queue operations and outbound email tools.', 'ico' => '📧'],
];

$editTemplate = ($section === 'email_templates' && isset($_GET['edit'])) ? msg_one($pdo, "SELECT * FROM email_templates WHERE id=? LIMIT 1", [(int)$_GET['edit']]) : null;
$editNotice = ($section === 'wallet_messages' && isset($_GET['edit'])) ? msg_one($pdo, "SELECT * FROM wallet_messages WHERE id=? LIMIT 1", [(int)$_GET['edit']]) : null;
$editAnnouncement = ($section === 'announcements' && isset($_GET['edit'])) ? msg_one($pdo, "SELECT * FROM announcements WHERE id=? LIMIT 1", [(int)$_GET['edit']]) : null;
$editProposal = ($section === 'proposals' && isset($_GET['edit'])) ? msg_one($pdo, "SELECT * FROM vote_proposals WHERE id=? LIMIT 1", [(int)$_GET['edit']]) : null;
$editPoll = ($section === 'community_polls' && isset($_GET['edit'])) ? msg_one($pdo, "SELECT * FROM wallet_polls WHERE id=? LIMIT 1", [(int)$_GET['edit']]) : null;

$languageAuditRows = [];
if (ops_has_table($pdo, 'email_templates')) {
    foreach (msg_rows($pdo, "SELECT id, template_key AS ref_key, subject_line AS title, body_text AS body_text FROM email_templates ORDER BY id DESC") as $row) {
        $terms = array_unique(array_merge(msg_flagged_terms((string)$row['title']), msg_flagged_terms((string)$row['body_text'])));
        if ($terms) {
            $languageAuditRows[] = ['source' => 'email_templates', 'ref' => $row['ref_key'], 'title' => $row['title'], 'terms' => implode(', ', $terms), 'link' => './messages.php?section=email_templates&edit=' . (int)$row['id']];
        }
    }
}
if (ops_has_table($pdo, 'wallet_messages')) {
    foreach (msg_rows($pdo, "SELECT id, message_key AS ref_key, title, CONCAT(COALESCE(summary,''),' ',COALESCE(body,'')) AS body_text FROM wallet_messages ORDER BY id DESC LIMIT 200") as $row) {
        $terms = array_unique(array_merge(msg_flagged_terms((string)$row['title']), msg_flagged_terms((string)$row['body_text'])));
        if ($terms) {
            $languageAuditRows[] = ['source' => 'wallet_messages', 'ref' => $row['ref_key'], 'title' => $row['title'], 'terms' => implode(', ', $terms), 'link' => './messages.php?section=wallet_messages&edit=' . (int)$row['id']];
        }
    }
}
if (ops_has_table($pdo, 'announcements')) {
    foreach (msg_rows($pdo, "SELECT id, CONCAT('announcement-',id) AS ref_key, title, body AS body_text FROM announcements ORDER BY id DESC LIMIT 200") as $row) {
        $terms = array_unique(array_merge(msg_flagged_terms((string)$row['title']), msg_flagged_terms((string)$row['body_text'])));
        if ($terms) {
            $languageAuditRows[] = ['source' => 'announcements', 'ref' => $row['ref_key'], 'title' => $row['title'], 'terms' => implode(', ', $terms), 'link' => './messages.php?section=announcements&edit=' . (int)$row['id']];
        }
    }
}
if (ops_has_table($pdo, 'vote_proposals')) {
    foreach (msg_rows($pdo, "SELECT id, proposal_key AS ref_key, title, CONCAT(COALESCE(summary,''),' ',COALESCE(body,'')) AS body_text FROM vote_proposals ORDER BY id DESC LIMIT 200") as $row) {
        $terms = array_unique(array_merge(msg_flagged_terms((string)$row['title']), msg_flagged_terms((string)$row['body_text'])));
        if ($terms) {
            $languageAuditRows[] = ['source' => 'vote_proposals', 'ref' => $row['ref_key'], 'title' => $row['title'], 'terms' => implode(', ', $terms), 'link' => './messages.php?section=proposals&edit=' . (int)$row['id']];
        }
    }
}

// ── Pagination setup ──────────────────────────────────────────────────────────
$msgPage    = max(1, (int)($_GET['page'] ?? 1));
$msgPerPage = 20;

if (!function_exists('render_pager')) {
    function render_pager(string $base, int $page, int $totalPages, int $total, string $label = 'result'): string {
        if ($totalPages <= 1 && $total <= 20) return '';
        $sfx = $total !== 1 ? 's' : '';
        $ue  = fn(int $pg): string => htmlspecialchars($base . 'page=' . $pg, ENT_QUOTES, 'UTF-8');
        $o   = '<div class="pager"><span class="pg-info">' . number_format($total) . ' ' . $label . $sfx . '</span>';
        if ($page > 1) {
            $o .= '<a href="' . $ue(1) . '">«</a><a href="' . $ue($page - 1) . '">‹ Prev</a>';
        } else { $o .= '<span>«</span><span>‹ Prev</span>'; }
        for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++) {
            $o .= $pg === $page
                ? '<span class="pg-current">' . $pg . '</span>'
                : '<a href="' . $ue($pg) . '">' . $pg . '</a>';
        }
        if ($page < $totalPages) {
            $o .= '<a href="' . $ue($page + 1) . '">Next ›</a><a href="' . $ue($totalPages) . '">»</a>';
        } else { $o .= '<span>Next ›</span><span>»</span>'; }
        return $o . '</div>';
    }
}
function msg_section_pager_base(string $section): string {
    return 'messages.php?section=' . urlencode($section) . '&';
}
function msg_paginate(PDO $pdo, string $table, string $sql, array $params, int $page, int $perPage, bool $tableExists = true): array {
    if (!$tableExists) return ['rows' => [], 'total' => 0, 'totalPages' => 1, 'page' => 1];
    try {
        $total = (int)(ops_fetch_one($pdo, "SELECT COUNT(*) AS c FROM ($sql) AS _sub", $params)['c'] ?? 0);
    } catch (Throwable $e) { $total = 0; }
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    try {
        $rows = ops_fetch_all($pdo, $sql . " LIMIT $perPage OFFSET $offset", $params);
    } catch (Throwable $e) { $rows = []; }
    return compact('rows', 'total', 'totalPages', 'page');
}

ob_start();
?>
<style>
.badge-open{background:rgba(34,197,94,.14);color:#90f0b1}
.badge-closed{background:rgba(148,163,184,.16);color:#d5dbe4}
.badge-draft{background:rgba(212,178,92,.15);color:var(--gold)}
.row-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:18px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}
.tabs a{text-decoration:none;border:1px solid var(--line);padding:8px 14px;border-radius:10px;color:var(--text);background:rgba(255,255,255,.03);font-weight:600;font-size:13px}
.tabs a.active{background:rgba(212,178,92,.12);color:var(--gold);border-color:rgba(212,178,92,.3)}
.tabs a:hover:not(.active){background:rgba(255,255,255,.05)}
.btn-secondary{display:inline-block;padding:6px 12px;border-radius:9px;text-decoration:none;font-weight:700;font-size:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text)}
.btn-secondary:hover{background:rgba(255,255,255,.06)}
.field{margin-bottom:12px}
.field label{display:block;font-size:.84rem;margin-bottom:6px;color:var(--sub);font-weight:600}
.field textarea{min-height:110px;resize:vertical}
.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em}
.section-card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;text-decoration:none;color:inherit;display:block;transition:border-color .15s}
.section-card:hover{border-color:var(--line2)}
@media(max-width:900px){.row-grid,.stat-grid{grid-template-columns:1fr}}
</style>
<div class="card">
  <div class="card-head">
    <h1 style="margin:0;font-size:1.3rem">Communications <?= ops_admin_help_button('Communications', 'The authoritative communications and governance surface for notices, proposals, and polls.') ?></h1>
  </div>
  <div class="card-body" style="padding-top:8px">
    <p class="muted small" style="margin:0">Manage Partner-facing notices, announcements, templates, proposal threads, and formal poll publishing.</p>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert-ok"><?=h($flash)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-err"><?=h($error)?></div><?php endif; ?>
<?php if ($warning): ?><div class="alert alert-warn"><?=h($warning)?></div><?php endif; ?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Communications surface', 'What this page does', 'Use this page to manage Partner-facing notices, announcements, templates, proposal threads, and formal poll publishing surfaces. It is the main operator page for drafting, reviewing, and updating communications before they reach Partners.', [
    'Use Partner Notices for targeted wallet-facing updates.',
    'Use Announcements for broader community-wide notices.',
    'Use Partner Proposals and Partner Polls for governance-facing publishing.',
    'Use Language Audit before publishing if you want to catch stale member, scheme, or AFSL phrasing.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Work from message type to publication path so operators do not mix drafting, review, and live publishing.', [
    ['title' => 'Choose the communication lane', 'body' => 'Select Partner Notices, News, Partner Proposals, Partner Polls, Templates, or Language Audit.'],
    ['title' => 'Draft and review the content', 'body' => 'Complete the title, body, timing, and scope fields.'],
    ['title' => 'Open, schedule, or close the item', 'body' => 'Use the status and timing fields to control visibility.'],
    ['title' => 'Check downstream delivery', 'body' => 'Use Email Access for queue-backed outbound email actions.'],
  ]),
  ops_admin_guide_panel('Admin section guide', 'Each communications area has a different audience and operational purpose.', [
    ['title' => 'Partner Notices', 'body' => 'Short, targeted wallet notices for active Partners.'],
    ['title' => 'Announcements', 'body' => 'Broader updates and community notices visible over a date range.'],
    ['title' => 'Partner Proposals', 'body' => 'Consultation and governance proposal threads.'],
    ['title' => 'Partner Polls', 'body' => 'Formal Partner poll administration and scheduling.'],
    ['title' => 'Email Templates and Email Access', 'body' => 'Reusable outbound email content plus the queue-backed delivery tools.'],
    ['title' => 'Language Audit', 'body' => 'Catches stale legal, regulatory, or membership-era phrasing before publication.'],
  ]),
]) ?>
<div class="tabs">
  <a class="<?= $section === '' ? 'active' : '' ?>" href="./messages.php">Selector</a>
  <?php foreach ($cards as $key => $card): ?>
    <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= $key === 'email_access' ? './email_access.php' : ('./messages.php?section=' . urlencode($key)) ?>"><?= h($card['label']) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($section === ''): ?>
<div class="stat-grid" style="margin-bottom:18px">
  <div class="card"><div class="card-body"><div class="stat-label">Partner notices</div><div class="stat-value"><?= msg_val($pdo, 'SELECT COUNT(*) FROM wallet_messages') ?></div></div></div>
  <div class="card"><div class="card-body"><div class="stat-label">News items</div><div class="stat-value"><?= msg_val($pdo, 'SELECT COUNT(*) FROM announcements') ?></div></div></div>
  <div class="card"><div class="card-body"><div class="stat-label">Proposal bridge rows</div><div class="stat-value"><?= msg_val($pdo, 'SELECT COUNT(*) FROM proposal_register') ?></div></div></div>
  <div class="card"><div class="card-body"><div class="stat-label">Partner Polls</div><div class="stat-value"><?= msg_val($pdo, 'SELECT COUNT(*) FROM community_polls') ?></div></div></div>
</div>
<div class="row-grid">
  <?php foreach ($cards as $key => $card): if ($key === 'email_access') continue; ?>
    <a class="section-card" href="./messages.php?section=<?= urlencode($key) ?>">
      <strong style="display:block;font-size:1rem;margin-bottom:6px"><?= h($card['ico']) ?> <?= h($card['label']) ?></strong>
      <div class="muted small"><?= h($card['desc']) ?></div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($section === 'email_templates'): ?>
<?= ops_admin_collapsible_help('Email Templates guide', [
  ops_admin_info_panel('Template editor', 'What this section does', 'Email Templates is where you manage reusable outbound email content. Edit templates here before they are used by queue-backed email actions.', ['Template key identifies the system template.', 'Subject is what recipients see first.', 'Language review warnings should be resolved before template changes go live.']),
  ops_admin_status_panel('How to use this section', 'Treat templates as controlled source content rather than one-off campaign messages.', [
    ['label' => 'Create or edit', 'body' => 'Update reusable email copy that may be sent many times from operational actions.'],
    ['label' => 'Active', 'body' => 'The template is available for queue-backed sending.'],
    ['label' => 'Terms warning', 'body' => 'The subject or body contains legacy or regulatory language that should be reviewed.'],
  ]),
]) ?>
<?php
    $templates = msg_rows($pdo, 'SELECT * FROM email_templates ORDER BY id ASC');
    $t = $editTemplate ?: ['id'=>'','template_key'=>'','subject_line'=>'','body_text'=>'','is_active'=>1];
    $templateTerms = array_unique(array_merge(msg_flagged_terms((string)($t['subject_line'] ?? '')), msg_flagged_terms((string)($t['body_text'] ?? ''))));
?>
?>
<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Email templates</h2></div>
    <div class="card-body table-wrap"><table><thead><tr><th>Key</th><th>Subject</th><th>Status</th><th>Terms</th><th></th></tr></thead><tbody>
      <?php if (!$templates): ?><tr><td colspan="5" class="empty">No templates found.</td></tr><?php endif; ?>
      <?php foreach ($templates as $row): $terms = array_unique(array_merge(msg_flagged_terms((string)$row['subject_line']), msg_flagged_terms((string)$row['body_text']))); ?>
      <tr>
        <td class="code"><?= h($row['template_key']) ?></td>
        <td><?= h($row['subject_line']) ?></td>
        <td><?= !empty($row['is_active']) ? '<span class="st st-ok">Active</span>' : '<span class="st st-dim">Inactive</span>' ?></td>
        <td><?= $terms ? h(implode(', ', $terms)) : '—' ?></td>
        <td><a class="btn-secondary" href="./messages.php?section=email_templates&edit=<?= (int)$row['id'] ?>">Edit</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= !empty($t['id']) ? 'Edit template' : 'New template' ?></h2></div>
    <div class="card-body">
    <?php if ($templateTerms): ?><div class="alert alert-warn"><?= h(msg_warning_html($templateTerms)) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="template_id" value="<?= h((string)$t['id']) ?>">
      <div class="field"><label>Template key</label><input name="template_key" value="<?= h((string)($t['template_key'] ?? '')) ?>"></div>
      <div class="field"><label>Subject line</label><input name="subject_line" value="<?= h((string)($t['subject_line'] ?? '')) ?>"></div>
      <div class="field"><label>Body text</label><textarea name="body_text"><?= h((string)($t['body_text'] ?? '')) ?></textarea></div>
      <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= !empty($t['is_active']) ? 'checked' : '' ?>> Active</label></div>
      <button class="btn btn-gold" type="submit">Save template</button>
    </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($section === 'wallet_messages'): ?>
<?= ops_admin_collapsible_help('Partner Notices guide', [
  ops_admin_info_panel('Partner notices', 'What this section does', 'Partner Notices are targeted wallet-facing messages visible inside the live vault experience rather than by email.', ['Audience controls who sees the notice.', 'Open and close timing control visibility.', 'Draft, open, and closed states change how the notice appears in the wallet.']),
  ops_admin_workflow_panel('Typical workflow', 'Use Partner Notices for operational updates that should appear inside the Partner wallet.', [
    ['title' => 'Draft the notice', 'body' => 'Set the audience, title, body, and timing.'],
    ['title' => 'Review scope and language', 'body' => 'Check that the correct Partner audience is selected and that the text matches current JV language.'],
    ['title' => 'Open or schedule', 'body' => 'Use the status and open time to control whether the notice is visible immediately or later.'],
  ]),
]) ?>
<?php
    $wmPaged = msg_paginate($pdo, 'wallet_messages', 'SELECT * FROM wallet_messages ORDER BY id DESC', [], $msgPage, $msgPerPage, ops_has_table($pdo, 'wallet_messages'));
    $rows = $wmPaged['rows'];
    $r = $editNotice ?: ['id'=>'','audience_scope'=>'all','title'=>'','body'=>'','status'=>'draft','sent_at'=>'','expires_at'=>''];
    $noticeTerms = array_unique(array_merge(msg_flagged_terms((string)($r['title'] ?? '')), msg_flagged_terms((string)($r['body'] ?? ''))));
?>
<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Partner notices</h2></div>
    <div class="card-body table-wrap"><table><thead><tr><th>Title</th><th>Audience</th><th>Status</th><th>Read</th><th>Sent / expires</th><th>Terms</th><th></th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">No notices found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row):
        $uiStatus = msg_wallet_status_to_ui((string)$row['status']);
        $terms = array_unique(array_merge(msg_flagged_terms((string)$row['title']), msg_flagged_terms((string)($row['summary'] . ' ' . $row['body']))));
        $readCount = 0;
        if (ops_has_table($pdo, 'wallet_message_reads')) {
            try { $rc = $pdo->prepare('SELECT COUNT(DISTINCT member_id) FROM wallet_message_reads WHERE message_id = ?'); $rc->execute([(int)$row['id']]); $readCount = (int)$rc->fetchColumn(); } catch (Throwable $ignored) {}
        }
      ?>
      <tr>
        <td><?= h($row['title']) ?></td>
        <td><?= h($row['audience_scope'] ?? msg_scope_from_legacy_audience($row['audience'] ?? 'all')) ?></td>
        <td><?= msg_status_badge($uiStatus) ?></td>
        <td><a href="./messages.php?section=wallet_messages&read_track=<?= (int)$row['id'] ?>" class="small"><?= $readCount ?> read</a></td>
        <td class="small"><?= h((string)($row['sent_at'] ?? '—')) ?><br><?= h((string)($row['expires_at'] ?? '—')) ?></td>
        <td><?= $terms ? h(implode(', ', $terms)) : '—' ?></td>
        <td class="actions"><a class="btn-secondary" href="./messages.php?section=wallet_messages&edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="close_early"><input type="hidden" name="target" value="wallet_messages"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn-secondary" type="submit">Close</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?= render_pager(msg_section_pager_base('wallet_messages'), $wmPaged['page'], $wmPaged['totalPages'], $wmPaged['total'], 'notice') ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= !empty($r['id']) ? 'Edit partner notice' : 'New partner notice' ?></h2></div>
    <div class="card-body">
    <?php if ($noticeTerms): ?><div class="alert alert-warn"><?= h(msg_warning_html($noticeTerms)) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save_wallet_notice"><input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
      <div class="field"><label>Audience</label><select name="audience"><option value="all" <?= (($r['audience_scope'] ?? 'all') === 'all') ? 'selected' : '' ?>>All <?= h($partnerLabel) ?>s</option><option value="personal" <?= (($r['audience_scope'] ?? '') === 'personal') ? 'selected' : '' ?>>Personal only</option><option value="business" <?= (($r['audience_scope'] ?? '') === 'business') ? 'selected' : '' ?>>Business only</option><option value="landholder" <?= (($r['audience_scope'] ?? '') === 'landholder') ? 'selected' : '' ?>>Landholder only</option></select></div>
      <div class="field"><label>Title</label><input name="title" value="<?= h((string)($r['title'] ?? '')) ?>"></div>
      <div class="field"><label>Body</label><textarea name="body"><?= h((string)($r['body'] ?? '')) ?></textarea></div>
      <div class="field"><label>Status</label><select name="status"><option value="draft" <?= msg_wallet_status_to_ui((string)($r['status'] ?? 'draft')) === 'draft' ? 'selected' : '' ?>>Draft</option><option value="open" <?= msg_wallet_status_to_ui((string)($r['status'] ?? 'draft')) === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= msg_wallet_status_to_ui((string)($r['status'] ?? 'draft')) === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
      <div class="field"><label>Open at (Sydney)</label><input type="datetime-local" name="open_at" value="<?= !empty($r['sent_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['sent_at']))) : '' ?>"></div>
      <div class="field"><label>Close at (Sydney)</label><input type="datetime-local" name="close_at" value="<?= !empty($r['expires_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['expires_at']))) : '' ?>"></div>
      <button class="btn btn-gold" type="submit">Save notice</button>
    </form>
    </div>
  </div>
</div>

<?php
$trackId = (int)($_GET['read_track'] ?? 0);
if ($trackId > 0 && ops_has_table($pdo, 'wallet_message_reads') && ops_has_table($pdo, 'wallet_messages')):
    $trackNotice = msg_one($pdo, 'SELECT id, title, audience_scope, sent_at FROM wallet_messages WHERE id = ? LIMIT 1', [$trackId]);
    if ($trackNotice):
        $readRows = []; $unreadRows = [];
        try {
            $readStmt = $pdo->prepare('SELECT m.id AS member_id, m.full_name, m.email, m.mobile, wmr.read_at FROM wallet_message_reads wmr JOIN snft_memberships m ON m.id = wmr.member_id WHERE wmr.message_id = ? ORDER BY wmr.read_at DESC');
            $readStmt->execute([$trackId]);
            $readRows = $readStmt->fetchAll();
            $scope = (string)($trackNotice['audience_scope'] ?? 'all');
            $unreadQuery = 'SELECT m.id AS member_id, m.full_name, m.email, m.mobile FROM snft_memberships m WHERE m.id NOT IN (SELECT member_id FROM wallet_message_reads WHERE message_id = ?)';
            if ($scope === 'personal') { $unreadQuery .= " AND m.member_type = 'personal'"; }
            elseif ($scope === 'business') { $unreadQuery .= " AND m.member_type = 'business'"; }
            $unreadQuery .= ' ORDER BY m.full_name ASC';
            $unreadStmt = $pdo->prepare($unreadQuery);
            $unreadStmt->execute([$trackId]);
            $unreadRows = $unreadStmt->fetchAll();
        } catch (Throwable $e) {}
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0 0 2px">Read tracking — <?= h($trackNotice['title']) ?></h2>
      <div class="muted small">Audience: <?= h($trackNotice['audience_scope'] ?? 'all') ?> · Sent: <?= h((string)($trackNotice['sent_at'] ?? 'not sent')) ?></div>
    </div>
    <a href="./messages.php?section=wallet_messages" class="btn-secondary">← Back to notices</a>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <div class="eyebrow">Read — <?= count($readRows) ?> Partner<?= count($readRows)!==1?'s':'' ?></div>
        <?php if (!$readRows): ?><p class="muted small">No Partners have read this notice yet.</p>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>Partner</th><th>Email / mobile</th><th>Read at</th></tr></thead><tbody>
          <?php foreach ($readRows as $rr): ?>
          <tr><td><?= h($rr['full_name'] ?? '—') ?></td><td class="small"><?= h($rr['email'] ?? '—') ?><br><?= h($rr['mobile'] ?? '') ?></td><td class="small"><?= h((string)($rr['read_at'] ?? '—')) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
      <div>
        <div class="eyebrow">Not read — <?= count($unreadRows) ?> Partner<?= count($unreadRows)!==1?'s':'' ?></div>
        <?php if (!$unreadRows): ?><p class="muted small">All eligible Partners have read this notice.</p>
        <?php else: ?><div class="table-wrap" style="max-height:400px;overflow-y:auto"><table><thead><tr><th>Partner</th><th>Email / mobile</th></tr></thead><tbody>
          <?php foreach ($unreadRows as $ur): ?>
          <tr><td><?= h($ur['full_name'] ?? '—') ?></td><td class="small"><?= h($ur['email'] ?? '—') ?><br><?= h($ur['mobile'] ?? '') ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; endif; ?>

<?php endif; ?>
<?php if ($section === 'announcements'): ?>
<?= ops_admin_collapsible_help('News guide', [
  ops_admin_info_panel('News', 'What this section does', 'News items are general community-wide notices. Unlike Partner Notices, News items do not require a Partner-linked read record — they are broadcast updates visible to all without individual read tracking.', [
    'Use News for general community updates, announcements, and information.',
    'Use Partner Notices when you need individual read verification and Partner-linked records.',
    'Open and close dates define the visibility window.',
  ]),
]) ?>
<?php
    $annPaged = msg_paginate($pdo, 'announcements', 'SELECT * FROM announcements ORDER BY id DESC', [], $msgPage, $msgPerPage, ops_has_table($pdo, 'announcements'));
    $rows = $annPaged['rows'];
    $r = $editAnnouncement ?: ['id'=>'','audience'=>'all','title'=>'','body'=>'','status'=>'draft','opens_at'=>'','closes_at'=>''];
    $annTerms = array_unique(array_merge(msg_flagged_terms((string)($r['title'] ?? '')), msg_flagged_terms((string)($r['body'] ?? ''))));
?>
<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>News items</h2></div>
    <div class="card-body table-wrap"><table><thead><tr><th>Title</th><th>Audience</th><th>Status</th><th>Opens / closes</th><th>Terms</th><th></th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">No news items found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): $uiStatus = $annHasStatus ? msg_schedule_status_to_ui((string)$row['status']) : 'draft'; ?>
      <tr>
        <td><?= h($row['title']) ?></td><td><?= h($row['audience']) ?></td><td><?= msg_status_badge($uiStatus) ?></td><td class="small"><?= h((string)($row['opens_at'] ?? '—')) ?><br><?= h((string)($row['closes_at'] ?? '—')) ?></td>
        <td class="actions"><a class="btn-secondary" href="./messages.php?section=announcements&edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="close_early"><input type="hidden" name="target" value="announcements"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn-secondary" type="submit">Close</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?= render_pager(msg_section_pager_base('announcements'), $annPaged['page'], $annPaged['totalPages'], $annPaged['total'], 'news item') ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= !empty($r['id']) ? 'Edit news item' : 'New news item' ?></h2></div>
    <div class="card-body">
    <?php if ($annTerms): ?><div class="alert alert-warn"><?= h(msg_warning_html($annTerms)) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save_announcement"><input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
      <div class="field"><label>Audience</label><select name="audience"><option value="all" <?= (($r['audience'] ?? 'all') === 'all') ? 'selected' : '' ?>>All Partners</option><option value="personal" <?= (($r['audience'] ?? '') === 'personal') ? 'selected' : '' ?>>Personal only</option><option value="business" <?= (($r['audience'] ?? '') === 'business') ? 'selected' : '' ?>>Business only</option></select></div>
      <div class="field"><label>Title</label><input name="title" value="<?= h((string)($r['title'] ?? '')) ?>"></div>
      <div class="field"><label>Body</label><textarea name="body"><?= h((string)($r['body'] ?? '')) ?></textarea></div>
      <div class="field"><label>Status</label><select name="status"><option value="draft" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'draft' ? 'selected' : '' ?>>Draft</option><option value="open" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
      <div class="field"><label>Open at (Sydney)</label><input type="datetime-local" name="open_at" value="<?= !empty($r['opens_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['opens_at']))) : '' ?>"></div>
      <div class="field"><label>Close at (Sydney)</label><input type="datetime-local" name="close_at" value="<?= !empty($r['closes_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['closes_at']))) : '' ?>"></div>
      <button class="btn btn-gold" type="submit">Save news item</button>
    </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($section === 'proposals'): ?>
<?= ops_admin_collapsible_help('Partner Proposals guide', [
  ops_admin_info_panel('Partner Proposals', 'What this section does', 'Partner Proposals are the consultation and discussion side of governance publishing. Use this section for proposal-style content and bridge-linked proposal records.', ['Proposal status affects whether Partners can see or engage with the thread.', 'Audience scope controls which Partner group the thread targets.']),
]) ?>
<?php
    $vpPaged = msg_paginate($pdo, 'vote_proposals', 'SELECT vp.*, pr.status AS bridge_status, pr.id AS bridge_id FROM vote_proposals vp LEFT JOIN proposal_register pr ON pr.proposal_key = vp.proposal_key ORDER BY vp.id DESC', [], $msgPage, $msgPerPage, ops_has_table($pdo, 'vote_proposals'));
    $rows = $vpPaged['rows'];
    $r = $editProposal ?: ['id'=>'','audience_scope'=>'all','title'=>'','body'=>'','status'=>'draft','starts_at'=>'','closes_at'=>''];
    $proposalTerms = array_unique(array_merge(msg_flagged_terms((string)($r['title'] ?? '')), msg_flagged_terms((string)($r['body'] ?? ''))));
?>
<div class="row-grid">
  <div class="card">
  <div class="card-head"><h2>Partner Proposals</h2></div>
  <div class="card-body">
    <p class="muted small">Legacy <span class="code">vote_proposals</span> remain available for wallet/admin compatibility. Bridge rows are maintained in <span class="code">proposal_register</span> while the control plane remains in parallel mode.</p>
    <div class="table-wrap"><table><thead><tr><th>Title</th><th>Audience</th><th>Legacy</th><th>Bridge</th><th>Opens / closes</th><th></th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">No proposals found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['title']) ?></td><td><?= h($row['audience_scope']) ?></td><td><?= msg_status_badge(msg_schedule_status_to_ui((string)$row['status'])) ?></td><td><?= h((string)($row['bridge_status'] ?? '—')) ?></td><td class="small"><?= h((string)($row['starts_at'] ?? '—')) ?><br><?= h((string)($row['closes_at'] ?? '—')) ?></td>
        <td class="actions"><a class="btn-secondary" href="./messages.php?section=proposals&edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="close_early"><input type="hidden" name="target" value="vote_proposals"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn-secondary" type="submit">Close</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?= render_pager(msg_section_pager_base('proposals'), $vpPaged['page'], $vpPaged['totalPages'], $vpPaged['total'], 'proposal') ?>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= !empty($r['id']) ? 'Edit proposal thread' : 'New proposal thread' ?></h2></div>
    <div class="card-body">
    <?php if ($proposalTerms): ?><div class="alert alert-warn"><?= h(msg_warning_html($proposalTerms)) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save_proposal"><input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
      <div class="field"><label>Audience</label><select name="audience"><option value="all" <?= (($r['audience_scope'] ?? 'all') === 'all') ? 'selected' : '' ?>>All <?= h($partnerLabel) ?>s</option><option value="personal" <?= (($r['audience_scope'] ?? '') === 'personal') ? 'selected' : '' ?>>Personal only</option><option value="business" <?= (($r['audience_scope'] ?? '') === 'business') ? 'selected' : '' ?>>Business only</option><option value="landholder" <?= (($r['audience_scope'] ?? '') === 'landholder') ? 'selected' : '' ?>>Landholder only</option></select></div>
      <div class="field"><label>Title</label><input name="title" value="<?= h((string)($r['title'] ?? '')) ?>"></div>
      <div class="field"><label>Body / description</label><textarea name="body"><?= h((string)($r['body'] ?? '')) ?></textarea></div>
      <div class="field"><label>Status</label><select name="status"><option value="draft" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'draft' ? 'selected' : '' ?>>Draft</option><option value="open" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
      <div class="field"><label>Open at (Sydney)</label><input type="datetime-local" name="open_at" value="<?= !empty($r['starts_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['starts_at']))) : '' ?>"></div>
      <div class="field"><label>Close at (Sydney)</label><input type="datetime-local" name="close_at" value="<?= !empty($r['closes_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['closes_at']))) : '' ?>"></div>
      <button class="btn btn-gold" type="submit">Save proposal</button>
    </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($section === 'community_polls'): ?>
<?= ops_admin_collapsible_help('Partner Polls guide', [
  ops_admin_info_panel('Partner Polls', 'What this section does', 'Partner Polls is the poll publishing and administration surface for formal poll records, choices, and schedule windows.', ['Question is the Partner-facing poll title.', 'Choices become the ballot options.', 'Open and close times define the active voting window.']),
  ops_admin_status_panel('Status guide', 'Use statuses to understand whether a poll is still being prepared or is already live.', [
    ['label' => 'Draft', 'body' => 'The poll is not yet open to Partners.'],
    ['label' => 'Open', 'body' => 'The poll is live now or scheduled to open based on the timing fields.'],
    ['label' => 'Closed', 'body' => 'The poll is no longer active and should be treated as historical.'],
  ]),
]) ?>
<?php
    $wpPaged = msg_paginate($pdo, 'wallet_polls', 'SELECT wp.*, cp.status AS bridge_status, cp.id AS bridge_id FROM wallet_polls wp LEFT JOIN community_polls cp ON cp.id = wp.community_poll_id OR cp.poll_key = wp.poll_key ORDER BY wp.id DESC', [], $msgPage, $msgPerPage, ops_has_table($pdo, 'wallet_polls'));
    $rows = $wpPaged['rows'];
    $r = $editPoll ?: ['id'=>'','audience_scope'=>'all','question'=>'','body'=>'','ballot_schema'=>'','status'=>'draft','opens_at'=>'','closes_at'=>''];
    $pollTerms = array_unique(array_merge(msg_flagged_terms((string)($r['question'] ?? '')), msg_flagged_terms((string)($r['body'] ?? ''))));
?>
<div class="row-grid">
  <div class="card">
  <div class="card-head"><h2>Partner Polls</h2></div>
  <div class="card-body">
    <p class="muted small">Legacy <span class="code">wallet_polls</span> remain available for live wallet compatibility. Bridge rows are maintained in <span class="code">community_polls</span> and <span class="code">poll_options</span> while the control plane remains in parallel mode.</p>
    <div class="table-wrap"><table><thead><tr><th>Question</th><th>Audience</th><th>Legacy</th><th>Bridge</th><th>Opens / closes</th><th></th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">No Partner Polls found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h((string)($row['question'] ?: $row['title'])) ?></td><td><?= h((string)($row['audience_scope'] ?: msg_scope_from_legacy_audience($row['audience'] ?? 'all'))) ?></td><td><?= msg_status_badge(msg_schedule_status_to_ui((string)$row['status'])) ?></td><td><?= h((string)($row['bridge_status'] ?? '—')) ?></td><td class="small"><?= h((string)($row['opens_at'] ?? '—')) ?><br><?= h((string)($row['closes_at'] ?? '—')) ?></td>
        <td class="actions"><a class="btn-secondary" href="./messages.php?section=community_polls&edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="close_early"><input type="hidden" name="target" value="wallet_polls"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn-secondary" type="submit">Close</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?= render_pager(msg_section_pager_base('community_polls'), $wpPaged['page'], $wpPaged['totalPages'], $wpPaged['total'], 'poll') ?>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= !empty($r['id']) ? 'Edit Partner Poll' : 'New Partner Poll' ?></h2></div>
    <div class="card-body">
    <?php if ($pollTerms): ?><div class="alert alert-warn"><?= h(msg_warning_html($pollTerms)) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save_poll"><input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
      <div class="field"><label>Audience</label><select name="audience"><option value="all" <?= ((($r['audience_scope'] ?? msg_scope_from_legacy_audience($r['audience'] ?? 'all'))) === 'all') ? 'selected' : '' ?>>All <?= h($partnerLabel) ?>s</option><option value="personal" <?= ((($r['audience_scope'] ?? '') === 'personal')) ? 'selected' : '' ?>>Personal only</option><option value="business" <?= ((($r['audience_scope'] ?? '') === 'business')) ? 'selected' : '' ?>>Business only</option><option value="landholder" <?= ((($r['audience_scope'] ?? '') === 'landholder')) ? 'selected' : '' ?>>Landholder only</option></select></div>
      <div class="field"><label>Question</label><input name="question" value="<?= h((string)($r['question'] ?? $r['title'] ?? '')) ?>"></div>
      <div class="field"><label>Background / context (optional)</label><textarea name="body"><?= h((string)($r['body'] ?? '')) ?></textarea></div>
      <div class="field"><label>Choices (one per line)</label><textarea name="choices"><?= h(msg_schema_to_choices_text((string)($r['ballot_schema'] ?? ''))) ?></textarea></div>
      <div class="field"><label>Status</label><select name="status"><option value="draft" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'draft' ? 'selected' : '' ?>>Draft</option><option value="open" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= msg_schedule_status_to_ui((string)($r['status'] ?? 'draft')) === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
      <div class="field"><label>Open at (Sydney)</label><input type="datetime-local" name="open_at" value="<?= !empty($r['opens_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['opens_at']))) : '' ?>"></div>
      <div class="field"><label>Close at (Sydney)</label><input type="datetime-local" name="close_at" value="<?= !empty($r['closes_at']) ? h(date('Y-m-d\TH:i', strtotime((string)$r['closes_at']))) : '' ?>"></div>
      <button class="btn btn-gold" type="submit">Save Partner Poll</button>
    </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($section === 'stewardship_responses'): ?>
<?= ops_admin_collapsible_help('Stewardship guide', [
  ops_admin_info_panel('Stewardship responses', 'What this section does', 'Stewardship Responses helps operators review submitted stewardship or outreach responses and decide where follow-up is needed.', ['Use this section as a review queue.', 'Look at audience, timing, and content together before follow-up.']),
]) ?>
    $rows = ops_has_table($pdo, 'member_stewardship_responses') ? msg_rows($pdo, "SELECT msr.*, m.full_name, m.email FROM member_stewardship_responses msr LEFT JOIN members m ON m.id = msr.member_id ORDER BY msr.id DESC LIMIT 200") : [];
?>
<div class="card">
  <div class="card-head"><h2>Stewardship responses</h2></div>
  <div class="card-body">
  <div class="table-wrap"><table><thead><tr><th><?= h($internalMemberLabel) ?></th><th>Email</th><th>Response</th><th>Created</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty">No stewardship responses found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><tr><td><?= h((string)($row['full_name'] ?? '—')) ?></td><td><?= h((string)($row['email'] ?? '—')) ?></td><td><?= h((string)($row['question_key'] ?? '')) ?> = <?= h((string)($row['answer_value'] ?? '')) ?></td><td><?= h((string)($row['completed_at'] ?? '')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php if ($section === 'language_audit'): ?>
<?= ops_admin_collapsible_help('Language Audit guide', [
  ops_admin_info_panel('Language audit', 'What this section does', 'Language Audit scans templates, notices, announcements, and proposal text for stale wording such as member, scheme, AFSL, or managed-investment phrasing.', ['Use it before publishing or after bulk copy changes.', 'Each row links back to the source record for cleanup.']),
]) ?>
<div class="card">
  <div class="card-head"><h2>Language audit</h2></div>
  <div class="card-body">
  <p class="muted small">This audit scans communications records for stale legacy or regulatory terms such as member, membership, scheme, fund, AFSL, PDS, and MIS before they reach <?= h($partnerLabel) ?>s.</p>
  <div class="table-wrap"><table><thead><tr><th>Source</th><th>Reference</th><th>Title</th><th>Flagged terms</th><th></th></tr></thead><tbody>
    <?php if (!$languageAuditRows): ?><tr><td colspan="5" class="empty">No flagged records found.</td></tr><?php endif; ?>
    <?php foreach ($languageAuditRows as $row): ?><tr><td class="code"><?= h($row['source']) ?></td><td class="code"><?= h($row['ref']) ?></td><td><?= h($row['title']) ?></td><td><?= h($row['terms']) ?></td><td><a class="btn-secondary" href="<?= h($row['link']) ?>">Open</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php
$body = ob_get_clean();
ops_render_page('Communications', 'communications', $body);
