<?php
declare(strict_types=1);

require_once __DIR__ . '/CRMAdapterInterface.php';
require_once __DIR__ . '/NullCRMAdapter.php';
require_once __DIR__ . '/EspoCRMAdapter.php';

function crmAdapter(): CRMAdapterInterface {
    static $adapter = null;
    if ($adapter instanceof CRMAdapterInterface) {
        return $adapter;
    }

    $adapter = match (CRM_PROVIDER) {
        'espocrm' => new EspoCRMAdapter(),
        default => new NullCRMAdapter(),
    };

    return $adapter;
}

function processCrmQueue(PDO $db, int $limit = 10): array {
    $adapter = crmAdapter();
    if (!$adapter->isEnabled()) {
        return ['processed' => 0, 'enabled' => false, 'provider' => CRM_PROVIDER];
    }

    $stmt = $db->prepare('
        SELECT id, sync_entity, entity_id, payload_json, attempts
        FROM crm_sync_queue
        WHERE status IN ("pending", "failed")
        ORDER BY id ASC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $processed = 0;
    foreach ($rows as $row) {
        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        try {
            $adapter->sync((string) $row['sync_entity'], $payload);
            $update = $db->prepare('UPDATE crm_sync_queue SET status = "synced", attempts = attempts + 1, last_error = NULL, synced_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $update->execute([(int) $row['id']]);
        } catch (Throwable $e) {
            $update = $db->prepare('UPDATE crm_sync_queue SET status = "failed", attempts = attempts + 1, last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $update->execute([$e->getMessage(), (int) $row['id']]);
        }
        $processed++;
    }

    return ['processed' => $processed, 'enabled' => true, 'provider' => CRM_PROVIDER];
}
