<?php
declare(strict_types=1);

/**
 * COG$ Hub Project Lifecycle — state machine helpers.
 *
 * Maps onto JVPA cl.6.3:
 *   - Every phase has a minimum deliberation period of 7 days.
 *   - The vote phase may be reduced to 48 hours when urgency_flagged = 1,
 *     certified by the initiating member at submission.
 *
 * Phase order: draft → open_for_input → deliberation → vote → accountability
 *
 * Legacy statuses (proposed, active, paused, completed, archived) are preserved
 * in the enum but are not part of the new lifecycle state machine.
 */

const HUB_PHASES = [
    'draft',
    'open_for_input',
    'deliberation',
    'vote',
    'accountability',
];

/** Minimum hours before a phase may be advanced past. 0 = no minimum. */
const HUB_PHASE_MIN_HOURS = [
    'draft'          => 0,    // initiator-controlled, no minimum
    'open_for_input' => 168,  // 7 days
    'deliberation'   => 168,  // 7 days
    'vote'           => 168,  // 7 days (48h when urgency_flagged = 1)
    'accountability' => 0,    // ongoing — no advance beyond this phase
];

/**
 * Return the next phase key, or null if already at the final phase or unknown.
 */
function hubNextPhase(string $current): ?string {
    $i = array_search($current, HUB_PHASES, true);
    if ($i === false || $i >= count(HUB_PHASES) - 1) {
        return null;
    }
    return HUB_PHASES[$i + 1];
}

/**
 * Calculate target end datetime for a phase, as a MySQL datetime string.
 * Returns null for phases with no minimum (draft, accountability).
 */
function hubPhaseTargetEnd(string $phase, bool $urgent, ?string $openedAt = null): ?string {
    $minHours = HUB_PHASE_MIN_HOURS[$phase] ?? 0;
    if ($minHours === 0) {
        return null;
    }
    $hours = ($phase === 'vote' && $urgent) ? 48 : $minHours;
    $base  = $openedAt ? strtotime($openedAt) : time();
    return date('Y-m-d H:i:s', $base + ($hours * 3600));
}

/**
 * Advance a project to its next lifecycle phase.
 *
 * Rules:
 *  - Only the project coordinator (lead_member_id) may advance.
 *  - Cannot advance a project not in HUB_PHASES (legacy statuses).
 *  - Cannot advance from accountability (final phase).
 *  - Returns the new phase name and timestamps on success.
 *  - Throws RuntimeException with a user-facing message on failure.
 */
function hubAdvancePhase(PDO $db, int $projectId, int $memberId): array {
    $stmt = $db->prepare(
        'SELECT status, urgency_flagged, lead_member_id, phase_opened_at
           FROM hub_projects WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$projectId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        throw new RuntimeException('Project not found.');
    }
    if ((int)$p['lead_member_id'] !== $memberId) {
        throw new RuntimeException('Only the project coordinator can advance the phase.');
    }
    if (!in_array((string)$p['status'], HUB_PHASES, true)) {
        throw new RuntimeException('This project uses the legacy status model and cannot be advanced. Please update the status manually.');
    }

    $next = hubNextPhase((string)$p['status']);
    if ($next === null) {
        throw new RuntimeException('This project is already in the final phase (accountability).');
    }

    $openedAt  = date('Y-m-d H:i:s');
    $targetEnd = hubPhaseTargetEnd($next, (bool)$p['urgency_flagged'], $openedAt);

    $db->prepare(
        'UPDATE hub_projects
            SET status            = ?,
                phase_opened_at   = ?,
                phase_target_end_at = ?,
                updated_at        = NOW()
          WHERE id = ?'
    )->execute([$next, $openedAt, $targetEnd, $projectId]);

    return [
        'status'              => $next,
        'phase_opened_at'     => $openedAt,
        'phase_target_end_at' => $targetEnd,
    ];
}
