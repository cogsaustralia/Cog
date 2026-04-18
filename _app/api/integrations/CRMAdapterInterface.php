<?php
declare(strict_types=1);

interface CRMAdapterInterface {
    public function isEnabled(): bool;
    public function sync(string $entity, array $payload): array;
}
