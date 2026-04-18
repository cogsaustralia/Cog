<?php
declare(strict_types=1);

require_once __DIR__ . '/CRMAdapterInterface.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';

final class EspoCRMAdapter implements CRMAdapterInterface {
    private string $baseUrl;
    private string $apiKey;

    public function __construct() {
        $configured = rtrim((string) env('ESPCRM_BASE_URL', ''), '/');
        if ($configured !== '' && !str_ends_with($configured, '/api/v1')) {
            $configured .= '/api/v1';
        }
        $this->baseUrl = $configured;
        $this->apiKey = (string) env('ESPCRM_API_KEY', '');
    }

    public function isEnabled(): bool {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function sync(string $entity, array $payload): array {
        if (!$this->isEnabled()) {
            return ['status' => 'skipped', 'message' => 'EspoCRM not configured'];
        }

        return match ($entity) {
            'snft_member' => $this->syncSnftMember($payload),
            'bnft_business' => $this->syncBnftBusiness($payload),
            'vault_interest' => $this->syncVaultInterest($payload),
            default => ['status' => 'skipped', 'message' => 'Unknown entity'],
        };
    }

    private function syncSnftMember(array $payload): array {
        $names = $this->splitName((string) ($payload['full_name'] ?? ''));
        $description = $this->buildDescription([
            'Source: COG$ Phase A Website',
            'Membership: SNFT',
            'Member Number: ' . (string) ($payload['member_number'] ?? ''),
            'State: ' . (string) ($payload['state'] ?? ''),
            'Suburb: ' . (string) ($payload['suburb'] ?? ''),
            'Postcode: ' . (string) ($payload['postcode'] ?? ''),
            'Reserved COG$: ' . (string) ($payload['reserved_tokens'] ?? 0),
            'ASX (investment) COG$: ' . (string) ($payload['investment_tokens'] ?? 0),
            'Donation COG$: ' . (string) ($payload['donation_tokens'] ?? 0),
            'Pay It Forward COG$: ' . (string) ($payload['pay_it_forward_tokens'] ?? 0),
            'Kids S-NFT COG$: ' . (string) ($payload['kids_tokens'] ?? 0),
            'Landholder hectares: ' . (string) ($payload['landholder_hectares'] ?? 0),
            'Landholder COG$: ' . (string) ($payload['landholder_tokens'] ?? 0),
        ]);

        $data = array_filter([
            'firstName' => $names['first'],
            'lastName' => $names['last'],
            'emailAddress' => $payload['email'] ?? null,
            'phoneNumber' => $payload['mobile'] ?? null,
            'description' => $description,
        ], static fn ($v) => $v !== null && $v !== '');

        $customMember = (string) env('ESPCRM_CONTACT_MEMBER_NUMBER_FIELD', '');
        $customType = (string) env('ESPCRM_CONTACT_MEMBERSHIP_TYPE_FIELD', '');
        $customState = (string) env('ESPCRM_CONTACT_STATE_FIELD', '');

        if ($customMember !== '') {
            $data[$customMember] = $payload['member_number'] ?? null;
        }
        if ($customType !== '') {
            $data[$customType] = 'SNFT';
        }
        if ($customState !== '') {
            $data[$customState] = $payload['state'] ?? null;
        }

        $contactId = $this->upsertByField('Contact', 'emailAddress', (string) ($payload['email'] ?? ''), $data);
        return ['status' => 'synced', 'contact_id' => $contactId];
    }

    private function syncBnftBusiness(array $payload): array {
        $accountDescription = $this->buildDescription([
            'Source: COG$ Phase A Website',
            'Membership: BNFT',
            'ABN: ' . (string) ($payload['abn'] ?? ''),
            'Entity Type: ' . (string) ($payload['entity_type'] ?? ''),
            'State: ' . (string) ($payload['state'] ?? ''),
            'Industry: ' . (string) ($payload['industry'] ?? ''),
            'BNFT Status: Reserved',
            'Reserved COG$: ' . (string) ($payload['reserved_tokens'] ?? 0),
            'ASX (investment) COG$: ' . (string) ($payload['invest_tokens'] ?? 0),
            'Donation COG$: ' . (string) ($payload['donation_tokens'] ?? 0),
            'Pay It Forward COG$: ' . (string) ($payload['pay_it_forward_tokens'] ?? 0),
            'Use Case: ' . (string) ($payload['use_case'] ?? ''),
        ]);

        $accountData = array_filter([
            'name' => $payload['legal_name'] ?? null,
            'website' => $payload['website'] ?? null,
            'phoneNumber' => $payload['mobile'] ?? null,
            'description' => $accountDescription,
        ], static fn ($v) => $v !== null && $v !== '');

        $customAbn = (string) env('ESPCRM_ACCOUNT_ABN_FIELD', '');
        $customBnftStatus = (string) env('ESPCRM_ACCOUNT_BNFT_STATUS_FIELD', '');
        if ($customAbn !== '') {
            $accountData[$customAbn] = $payload['abn'] ?? null;
        }
        if ($customBnftStatus !== '') {
            $accountData[$customBnftStatus] = 'BNFT reserved';
        }

        $accountId = null;
        if ($customAbn !== '' && !empty($payload['abn'])) {
            $accountId = $this->upsertByField('Account', $customAbn, (string) $payload['abn'], $accountData);
        } else {
            $accountId = $this->upsertByField('Account', 'name', (string) ($payload['legal_name'] ?? ''), $accountData);
        }

        $names = $this->splitName((string) ($payload['contact_name'] ?? ''));
        $contactDescription = $this->buildDescription([
            'Source: COG$ Phase A Website',
            'Membership: BNFT',
            'Related Business: ' . (string) ($payload['legal_name'] ?? ''),
            'ABN: ' . (string) ($payload['abn'] ?? ''),
            'Role: ' . (string) ($payload['position_title'] ?? ''),
        ]);

        $contactData = array_filter([
            'firstName' => $names['first'],
            'lastName' => $names['last'],
            'emailAddress' => $payload['email'] ?? null,
            'phoneNumber' => $payload['mobile'] ?? null,
            'description' => $contactDescription,
        ], static fn ($v) => $v !== null && $v !== '');

        $customType = (string) env('ESPCRM_CONTACT_MEMBERSHIP_TYPE_FIELD', '');
        if ($customType !== '') {
            $contactData[$customType] = 'BNFT';
        }

        $contactId = $this->upsertByField('Contact', 'emailAddress', (string) ($payload['email'] ?? ''), $contactData);

        if ($accountId !== null && $contactId !== null) {
            $this->relateContactToAccount($accountId, $contactId);
        }

        return ['status' => 'synced', 'account_id' => $accountId, 'contact_id' => $contactId];
    }

    private function syncVaultInterest(array $payload): array {
        $names = $this->splitName((string) ($payload['name'] ?? ''));
        $description = $this->buildDescription([
            'Source: COG$ Phase A Website',
            'Vault interest',
            'Pathway: ' . (string) ($payload['pathway'] ?? ''),
            'Interest: ' . (string) ($payload['interest'] ?? ''),
            'Notes: ' . (string) ($payload['notes'] ?? ''),
        ]);

        $data = array_filter([
            'firstName' => $names['first'],
            'lastName' => $names['last'],
            'emailAddress' => $payload['email'] ?? null,
            'description' => $description,
        ], static fn ($v) => $v !== null && $v !== '');

        $contactId = $this->upsertByField('Contact', 'emailAddress', (string) ($payload['email'] ?? ''), $data);
        return ['status' => 'synced', 'contact_id' => $contactId];
    }

    private function upsertByField(string $entityType, string $attribute, string $value, array $data): ?string {
        if ($value === '') {
            throw new RuntimeException("Cannot sync {$entityType} without {$attribute}.");
        }

        $existingId = $this->findOneId($entityType, $attribute, $value);
        if ($existingId !== null) {
            $this->request('PUT', $entityType . '/' . rawurlencode($existingId), $data);
            return $existingId;
        }

        $created = $this->request('POST', $entityType, $data);
        return $created['id'] ?? null;
    }

    private function findOneId(string $entityType, string $attribute, string $value): ?string {
        if ($value === '') {
            return null;
        }

        $searchParams = [
            'maxSize' => 1,
            'select' => 'id',
            'where' => [[
                'type' => 'equals',
                'attribute' => $attribute,
                'value' => $value,
            ]],
        ];

        $response = $this->request('GET', $entityType, null, ['searchParams' => json_encode($searchParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        if (!isset($response['list'][0]['id'])) {
            return null;
        }
        return (string) $response['list'][0]['id'];
    }

    private function relateContactToAccount(string $accountId, string $contactId): void {
        try {
            $this->request('POST', 'Account/' . rawurlencode($accountId) . '/contacts', ['id' => $contactId]);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'duplicate') || str_contains($message, 'already') || str_contains($message, 'exists')) {
                return;
            }
            throw $e;
        }
    }

    private function buildDescription(array $lines): string {
        $lines = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $lines), static fn ($line) => $line !== '' && !str_ends_with($line, ':')));
        return implode("\n", $lines);
    }

    private function splitName(string $fullName): array {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Member';
        return ['first' => $first, 'last' => $last];
    }

    private function request(string $method, string $path, ?array $body = null, ?array $query = null): array {
        if (!$this->isEnabled()) {
            throw new RuntimeException('EspoCRM is not configured.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $headers = [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error) {
            throw new RuntimeException('EspoCRM request failed: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if ($httpCode >= 400) {
            $message = 'EspoCRM error';
            if (is_array($decoded)) {
                $message = (string) ($decoded['error'] ?? $decoded['message'] ?? $decoded['reason'] ?? $message);
            }
            throw new RuntimeException($message . ' (HTTP ' . $httpCode . ')');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
