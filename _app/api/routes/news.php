<?php
declare(strict_types=1);

$action = trim((string)($id ?? ''), '/');
if (preg_match('#^read/(\d+)$#', $action, $matches)) {
    markAnnouncementRead((int)$matches[1]);
}
apiError('Unknown news route', 404);

function markAnnouncementRead(int $announcementId): void {
    requireMethod('POST');
    $principal = getAuthPrincipal();
    if (!$principal) {
        apiError('Authentication required', 401);
    }
    $db = getDB();
    $subjectType = subjectTypeForUserType((string)$principal['user_type']);
    $subjectRef = (string)$principal['subject_ref'];

    $exists = $db->prepare('SELECT id FROM announcements WHERE id = ? LIMIT 1');
    $exists->execute([$announcementId]);
    if (!$exists->fetch()) {
        apiError('Announcement not found.', 404);
    }

    $stmt = $db->prepare('INSERT INTO announcement_reads (announcement_id, subject_type, subject_ref, read_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE read_at = UTC_TIMESTAMP()');
    $stmt->execute([$announcementId, $subjectType, $subjectRef]);

    apiSuccess(['announcement_id' => $announcementId, 'read' => true]);
}
