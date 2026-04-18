<?php
declare(strict_types=1);

require_once __DIR__ . '/CRMAdapterInterface.php';

final class NullCRMAdapter implements CRMAdapterInterface {
    public function isEnabled(): bool {
        return false;
    }

    public function sync(string $entity, array $payload): array {
        return ['status' => 'skipped', 'message' => 'CRM disabled'];
    }
}
