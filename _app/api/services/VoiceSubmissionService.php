<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/mailer.php';

/**
 * VoiceSubmissionService
 * Core business logic for the Member Voice Submission feature.
 * File storage: /home4/cogsaust/secure_uploads/voice_submissions/{partner_id}/{id}.{ext}
 * Compliance gate: every submission requires admin clearance before use.
 */
class VoiceSubmissionService
{
    private const SECURE_UPLOAD_BASE = '/home4/cogsaust/secure_uploads/voice_submissions';

    private const ALLOWED_AUDIO_MIME = ['audio/mpeg', 'audio/webm', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/aac', 'application/ogg'];
    private const ALLOWED_VIDEO_MIME = ['video/mp4', 'video/webm', 'video/x-matroska', 'video/ogg'];
    private const AUDIO_MAX_BYTES    = 5 * 1024 * 1024;   // 5 MB
    private const VIDEO_MAX_BYTES    = 50 * 1024 * 1024;  // 50 MB
    private const MAX_DURATION_SEC   = 30;
    private const TEXT_MAX_CHARS     = 280;
    private const CONSENT_VERSION    = 'v1.0';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Member-facing methods
    // ──────────────────────────────────────────────────────────────────────────

    public function create(int $partnerId, string $memberEmail, string $firstName, string $stateCode, array $post, array $files, array $server): array
    {
        $type = trim((string)($post['submission_type'] ?? ''));
        if (!in_array($type, ['text', 'audio', 'video'], true)) {
            throw new InvalidArgumentException('submission_type must be text, audio, or video.');
        }
        if (($post['consent_given'] ?? '') !== '1') {
            throw new InvalidArgumentException('Consent is required to submit.');
        }

        $textContent  = null;
        $textCharCount = null;
        $filePath     = null;
        $fileOrig     = null;
        $fileMime     = null;
        $fileSize     = null;
        $duration     = null;

        if ($type === 'text') {
            $textContent   = trim((string)($post['text_content'] ?? ''));
            $textCharCount = mb_strlen($textContent);
            if ($textCharCount === 0) {
                throw new InvalidArgumentException('Submission text cannot be empty.');
            }
            if ($textCharCount > self::TEXT_MAX_CHARS) {
                throw new InvalidArgumentException('Submission text must be 280 characters or fewer.');
            }
        } else {
            // audio or video
            $uploadKey = 'submission_file';
            if (empty($files[$uploadKey]) || ($files[$uploadKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('File upload failed or no file received.');
            }
            $file    = $files[$uploadKey];
            $tmpPath = (string)$file['tmp_name'];
            $fileSize = (int)$file['size'];
            $fileOrig = substr((string)($file['name'] ?? 'upload'), 0, 255);

            // Verify mime by magic bytes
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $fileMime = (string)finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            // Strip codec parameters (e.g. "audio/webm; codecs=opus" → "audio/webm")
            $fileMimeBase = strtolower(trim(explode(';', $fileMime)[0]));

            $allowed = $type === 'audio' ? self::ALLOWED_AUDIO_MIME : self::ALLOWED_VIDEO_MIME;
            if (!in_array($fileMimeBase, $allowed, true)) {
                error_log("VoiceSubmission: rejected {$type} file with finfo mime={$fileMime} base={$fileMimeBase}");
                throw new InvalidArgumentException("File type not allowed for {$type} submissions (detected: {$fileMimeBase}).");
            }
            $fileMime = $fileMimeBase; // store the clean base type

            $maxBytes = $type === 'audio' ? self::AUDIO_MAX_BYTES : self::VIDEO_MAX_BYTES;
            if ($fileSize > $maxBytes) {
                $maxMb = $maxBytes / 1024 / 1024;
                throw new InvalidArgumentException("File too large. Maximum size is {$maxMb} MB.");
            }

            // Duration check via ffprobe if available
            $duration = $this->getMediaDuration($tmpPath);
            if ($duration !== null && $duration > self::MAX_DURATION_SEC) {
                throw new InvalidArgumentException('Submission must be 30 seconds or shorter.');
            }

            // Move to secure storage
            $ext = $this->mimeToExt($fileMime);
            // Insert row first to get the ID for the path
            $insertId = $this->insertRow(
                $partnerId, $type, null, null,
                '__PENDING__', $fileOrig, $fileMime, $fileSize, $duration,
                $post, $firstName, $stateCode, $server
            );

            $dir = self::SECURE_UPLOAD_BASE . '/' . $partnerId;
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $dest = $dir . '/' . $insertId . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $dest)) {
                // Clean up the orphan row
                $this->db->prepare('DELETE FROM member_voice_submissions WHERE id = ?')->execute([$insertId]);
                throw new RuntimeException('Failed to store uploaded file securely.');
            }
            chmod($dest, 0640);

            // Update with real path
            $this->db->prepare('UPDATE member_voice_submissions SET file_path = ? WHERE id = ?')
                ->execute([$dest, $insertId]);

            $this->queueNotifications($partnerId, $memberEmail, $firstName, $insertId);
            return ['submission_id' => $insertId, 'status' => 'pending_review', 'message' => "Your submission is in review. We'll email you when it's cleared."];
        }

        // Text path — no file
        $insertId = $this->insertRow(
            $partnerId, $type, $textContent, $textCharCount,
            null, null, null, null, null,
            $post, $firstName, $stateCode, $server
        );

        $this->queueNotifications($partnerId, $memberEmail, $firstName, $insertId);
        return ['submission_id' => $insertId, 'status' => 'pending_review', 'message' => "Your submission is in review. We'll email you when it's cleared."];
    }

    public function listForMember(int $partnerId): array
    {
        $rows = $this->db->prepare(
            'SELECT id, submission_type, text_content, compliance_status,
                    used_in_post_url, created_at, withdrawn_at, rejection_reason_to_member,
                    display_name_first, display_state
             FROM member_voice_submissions
             WHERE partner_id = ?
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $rows->execute([$partnerId]);
        return $rows->fetchAll(PDO::FETCH_ASSOC);
    }

    public function withdraw(int $partnerId, int $submissionId, string $reason): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, compliance_status, used_in_post_url, partner_id
             FROM member_voice_submissions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Submission not found.');
        }
        if ((int)$row['partner_id'] !== $partnerId) {
            throw new RuntimeException('Forbidden.');
        }
        if ($row['compliance_status'] === 'withdrawn') {
            return ['submission_id' => $submissionId, 'status' => 'withdrawn'];
        }

        $this->db->prepare(
            'UPDATE member_voice_submissions
             SET compliance_status = "withdrawn", withdrawn_at = NOW(), withdrawn_reason = ?
             WHERE id = ?'
        )->execute([substr($reason, 0, 1000), $submissionId]);

        return [
            'submission_id'  => $submissionId,
            'status'         => 'withdrawn',
            'social_removal' => !empty($row['used_in_post_url']),
        ];
    }

    public function streamFile(int $partnerId, bool $isAdmin, int $submissionId): void
    {
        $stmt = $this->db->prepare(
            'SELECT partner_id, file_path, file_mime_type, compliance_status
             FROM member_voice_submissions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['file_path'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'File not found.']);
            exit;
        }
        if (!$isAdmin && (int)$row['partner_id'] !== $partnerId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden.']);
            exit;
        }
        $path = $row['file_path'];
        if (!file_exists($path)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'File not available.']);
            exit;
        }

        $mime = $row['file_mime_type'] ?: 'application/octet-stream';
        $size = filesize($path);
        $ext  = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $size);
        header('Cache-Control: private, no-store');
        // Add attachment header when ?download=1 is passed (admin download button)
        if (!empty($_GET['download'])) {
            $fname = 'cogs-voice-' . $submissionId . '.' . $ext;
            header('Content-Disposition: attachment; filename="' . $fname . '"');
        }
        readfile($path);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Admin-facing methods
    // ──────────────────────────────────────────────────────────────────────────

    public function adminList(array $get): array
    {
        $status   = in_array($get['status'] ?? '', ['pending_review','cleared_for_use','rejected','withdrawn'], true)
                    ? $get['status'] : 'pending_review';
        $type     = in_array($get['type'] ?? '', ['text','audio','video'], true) ? $get['type'] : null;
        $state    = trim((string)($get['state'] ?? ''));
        $page     = max(1, (int)($get['page'] ?? 1));
        $perPage  = 25;

        $where   = ['mvs.compliance_status = ?'];
        $params  = [$status];
        if ($type) { $where[] = 'mvs.submission_type = ?'; $params[] = $type; }
        if ($state !== '' && $state !== 'all') { $where[] = 'mvs.display_state = ?'; $params[] = $state; }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int)($this->db->prepare("SELECT COUNT(*) FROM member_voice_submissions mvs $whereSql")
            ->execute($params) ? $this->db->prepare("SELECT COUNT(*) FROM member_voice_submissions mvs $whereSql")->execute($params) : 0);

        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM member_voice_submissions mvs $whereSql");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT mvs.id, mvs.partner_id, mvs.submission_type,
                    mvs.text_content, mvs.duration_seconds,
                    mvs.display_name_first, mvs.display_state,
                    mvs.compliance_status, mvs.used_in_post_url,
                    mvs.created_at, mvs.compliance_reviewed_at,
                    mvs.rejection_reason_to_member,
                    COALESCE(mvs.display_name_first, m.first_name) AS member_first_name,
                    m.email AS member_email
             FROM member_voice_submissions mvs
             JOIN partners p ON p.id = mvs.partner_id
             JOIN members m ON m.id = p.member_id
             $whereSql
             ORDER BY mvs.created_at ASC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'items'    => $rows,
        ];
    }

    public function adminApprove(int $submissionId, int $adminId, string $notes): array
    {
        $row = $this->fetchForAdmin($submissionId);
        if ($row['compliance_status'] !== 'pending_review') {
            throw new InvalidArgumentException('Only pending_review submissions can be approved.');
        }
        $this->db->prepare(
            'UPDATE member_voice_submissions
             SET compliance_status = "cleared_for_use",
                 compliance_reviewer_admin_id = ?,
                 compliance_reviewed_at = NOW(),
                 compliance_notes = ?
             WHERE id = ?'
        )->execute([$adminId, substr($notes, 0, 2000), $submissionId]);

        try { $this->auditLog($adminId, 'approve_voice_submission', 'submission_id=' . $submissionId); } catch (Throwable $e) {}
        try { $this->sendMemberEmail($row, 'approved'); } catch (Throwable $e) {
            error_log('adminApprove sendMemberEmail failed: ' . $e->getMessage());
        }
        return ['submission_id' => $submissionId, 'status' => 'cleared_for_use'];
    }

    public function adminReject(int $submissionId, int $adminId, string $internalNotes, string $memberReason): array
    {
        $row = $this->fetchForAdmin($submissionId);
        if (!in_array($row['compliance_status'], ['pending_review', 'cleared_for_use'], true)) {
            throw new InvalidArgumentException('Cannot reject a withdrawn submission.');
        }
        $this->db->prepare(
            'UPDATE member_voice_submissions
             SET compliance_status = "rejected",
                 compliance_reviewer_admin_id = ?,
                 compliance_reviewed_at = NOW(),
                 compliance_notes = ?,
                 rejection_reason_to_member = ?
             WHERE id = ?'
        )->execute([$adminId, substr($internalNotes, 0, 2000), substr($memberReason, 0, 2000), $submissionId]);

        $this->auditLog($adminId, 'reject_voice_submission', 'submission_id=' . $submissionId);
        try { $this->sendMemberEmail($row, 'rejected', $memberReason); } catch (Throwable $e) {
            error_log('adminReject sendMemberEmail failed: ' . $e->getMessage());
        }
        return ['submission_id' => $submissionId, 'status' => 'rejected'];
    }

    public function adminMarkUsed(int $submissionId, int $adminId, string $postUrl): array
    {
        $row = $this->fetchForAdmin($submissionId);
        if ($row['compliance_status'] !== 'cleared_for_use') {
            throw new InvalidArgumentException('Only cleared submissions can be marked as used.');
        }
        if (empty(trim($postUrl))) {
            throw new InvalidArgumentException('Post URL is required.');
        }
        $this->db->prepare(
            'UPDATE member_voice_submissions
             SET used_in_post_url = ?, used_at = NOW(), used_by_admin_id = ?
             WHERE id = ?'
        )->execute([substr($postUrl, 0, 500), $adminId, $submissionId]);

        try { $this->auditLog($adminId, 'mark_voice_submission_used', 'submission_id=' . $submissionId . ' url=' . $postUrl); } catch (Throwable $e) {}
        return ['submission_id' => $submissionId, 'used_in_post_url' => $postUrl];
    }

    public function adminWithdraw(int $submissionId, int $adminId, string $reason): array
    {
        $row = $this->fetchForAdmin($submissionId);
        if ($row['compliance_status'] === 'withdrawn') {
            return ['submission_id' => $submissionId, 'status' => 'withdrawn'];
        }
        $this->db->prepare(
            'UPDATE member_voice_submissions
             SET compliance_status = "withdrawn", withdrawn_at = NOW(), withdrawn_reason = ?
             WHERE id = ?'
        )->execute([substr('Admin: ' . $reason, 0, 1000), $submissionId]);

        try { $this->auditLog($adminId, 'withdraw_voice_submission_admin', 'submission_id=' . $submissionId); } catch (Throwable $e) {}
        return ['submission_id' => $submissionId, 'status' => 'withdrawn', 'social_removal' => !empty($row['used_in_post_url'])];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function insertRow(
        int $partnerId, string $type, ?string $textContent, ?int $textCharCount,
        ?string $filePath, ?string $fileOrig, ?string $fileMime, ?int $fileSize, ?int $duration,
        array $post, string $firstName, string $stateCode, array $server
    ): int {
        $displayFirst = trim((string)($post['display_name_first'] ?? $firstName));
        $displayState = trim((string)($post['display_state'] ?? $stateCode));

        $this->db->prepare(
            'INSERT INTO member_voice_submissions
               (partner_id, submission_type, text_content, text_char_count,
                file_path, file_original_name, file_mime_type, file_size_bytes, duration_seconds,
                consent_text_version, consent_given_at,
                display_name_first, display_state,
                compliance_status,
                submission_ip, submission_user_agent,
                created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,?,"pending_review",?,?,NOW(),NOW())'
        )->execute([
            $partnerId, $type, $textContent, $textCharCount,
            $filePath, $fileOrig, $fileMime, $fileSize, $duration,
            self::CONSENT_VERSION,
            substr($displayFirst, 0, 60) ?: null,
            substr($displayState, 0, 40) ?: null,
            $this->getClientIp($server),
            substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function fetchForAdmin(int $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mvs.*, m.email AS member_email,
                    COALESCE(mvs.display_name_first, m.first_name) AS member_first_name
             FROM member_voice_submissions mvs
             JOIN partners p ON p.id = mvs.partner_id
             JOIN members m ON m.id = p.member_id
             WHERE mvs.id = ? LIMIT 1'
        );
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Submission not found.');
        }
        return $row;
    }

    private function getMediaDuration(string $tmpPath): ?int
    {
        $ffprobe = trim((string)shell_exec('which ffprobe 2>/dev/null'));
        if (empty($ffprobe)) {
            return null;
        }
        $escaped = escapeshellarg($tmpPath);
        $out = shell_exec("$ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $escaped 2>/dev/null");
        $secs = (float)trim((string)$out);
        return $secs > 0 ? (int)ceil($secs) : null;
    }

    private function mimeToExt(string $mime): string
    {
        return match($mime) {
            'audio/mpeg'  => 'mp3',
            'audio/webm'  => 'webm',
            'audio/wav'   => 'wav',
            'audio/ogg'   => 'ogg',
            'audio/mp4'   => 'm4a',
            'audio/aac'   => 'aac',
            'video/mp4'   => 'mp4',
            'video/webm'  => 'webm',
            'video/x-matroska' => 'mkv',
            default       => 'bin',
        };
    }

    private function getClientIp(array $server): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            $v = trim((string)($server[$h] ?? ''));
            if ($v !== '') return trim(explode(',', $v)[0]);
        }
        return '';
    }

    private function auditLog(int $adminId, string $accessType, string $notes): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_access_log
                   (admin_user_id, username, access_type, page_or_view, notes, created_at)
                 SELECT ?, username, ?, "admin/voice_submissions", ?, NOW()
                 FROM admin_users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$adminId, $accessType, substr($notes, 0, 500), $adminId]);
        } catch (Throwable $e) {
            error_log('VoiceSubmissionService::auditLog failed: ' . $e->getMessage());
        }
    }

    private function queueNotifications(int $partnerId, string $email, string $firstName, int $submissionId): void
    {
        try {
            if (!function_exists('queueEmail')) return;

            // Acknowledgement to member
            queueEmail(
                $this->db, 'voice_submission', $submissionId, $email,
                'voice_submission_ack',
                'Your COG$ voice submission is in review',
                ['first_name' => $firstName, 'submission_id' => $submissionId]
            );

            // Notification to operator
            $adminEmail = defined('MAIL_ADMIN_EMAIL') ? MAIL_ADMIN_EMAIL : '';
            if ($adminEmail !== '') {
                queueEmail(
                    $this->db, 'voice_submission', $submissionId, $adminEmail,
                    'voice_submission_operator_alert',
                    '[COG$] New voice submission #' . $submissionId . ' in review',
                    ['submission_id' => $submissionId, 'partner_id' => $partnerId]
                );
            }
        } catch (Throwable $e) {
            error_log('VoiceSubmissionService::queueNotifications failed: ' . $e->getMessage());
        }
    }

    private function sendMemberEmail(array $row, string $event, string $memberReason = ''): void
    {
        try {
            if (!function_exists('queueEmail')) return;
            $email     = (string)($row['member_email'] ?? '');
            $firstName = (string)($row['member_first_name'] ?? 'Member');
            $id        = (int)$row['id'];
            if ($email === '') return;

            if ($event === 'approved') {
                queueEmail(
                    $this->db, 'voice_submission', $id, $email,
                    'voice_submission_approved',
                    'Your COG$ voice submission is cleared',
                    ['first_name' => $firstName, 'submission_id' => $id]
                );
            } elseif ($event === 'rejected') {
                queueEmail(
                    $this->db, 'voice_submission', $id, $email,
                    'voice_submission_rejected',
                    'About your COG$ submission',
                    ['first_name' => $firstName, 'submission_id' => $id, 'reason' => $memberReason]
                );
            }
        } catch (Throwable $e) {
            error_log('VoiceSubmissionService::sendMemberEmail failed: ' . $e->getMessage());
        }
    }
}
