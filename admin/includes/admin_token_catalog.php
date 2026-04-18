<?php
declare(strict_types=1);

if (!function_exists('trust_identity_class_codes')) {
    function trust_identity_class_codes(): array
    {
        return ['PERSONAL_SNFT', 'KIDS_SNFT', 'BUSINESS_BNFT'];
    }
}

if (!function_exists('trust_canonical_class_meta')) {
    function trust_canonical_class_meta(): array
    {
        return [
            'PERSONAL_SNFT' => ['admin_code' => 'S-NFT', 'unit_class_code' => 'S', 'display_name' => 'Personal S-NFT COG$'],
            'KIDS_SNFT' => ['admin_code' => 'kS-NFT', 'unit_class_code' => 'S', 'display_name' => 'Kids S-NFT COG$'],
            'BUSINESS_BNFT' => ['admin_code' => 'B-NFT', 'unit_class_code' => 'B', 'display_name' => 'Business B-NFT COG$'],
            'LANDHOLDER_COG' => ['admin_code' => 'LANDHOLDER_COG', 'unit_class_code' => 'Lh', 'display_name' => 'Landholder COG$'],
            'ASX_INVESTMENT_COG' => ['admin_code' => 'ASX_INVESTMENT_COG', 'unit_class_code' => 'A', 'display_name' => 'ASX COG$'],
            'PAY_IT_FORWARD_COG' => ['admin_code' => 'PAY_IT_FORWARD_COG', 'unit_class_code' => 'P', 'display_name' => 'Pay It Forward COG$'],
            'DONATION_COG' => ['admin_code' => 'DONATION_COG', 'unit_class_code' => 'D', 'display_name' => 'Donation COG$'],
            'RWA_COG' => ['admin_code' => 'RWA_COG', 'unit_class_code' => 'R', 'display_name' => 'RWA COG$'],
            'LR_COG' => ['admin_code' => 'LR_COG', 'unit_class_code' => 'Lr', 'display_name' => 'Resident COG$'],
        ];
    }
}

if (!function_exists('trust_catalog_row')) {
    function trust_catalog_row(array $row): array
    {
        $meta = trust_canonical_class_meta()[(string)($row['class_code'] ?? '')] ?? [];
        $row['admin_code'] = (string)($row['admin_code'] ?? ($meta['admin_code'] ?? ($row['class_code'] ?? '')));
        $row['unit_class_code'] = (string)($row['unit_class_code'] ?? ($meta['unit_class_code'] ?? ''));
        $row['display_name'] = (string)($row['display_name'] ?? ($meta['display_name'] ?? ''));
        return $row;
    }
}
if (!function_exists('trust_catalog_rows')) {
    function trust_catalog_rows(array $rows): array
    {
        return array_map('trust_catalog_row', $rows);
    }
}
if (!function_exists('trust_code_only')) {
    function trust_code_only(array $row): string
    {
        $row = trust_catalog_row($row);
        return (string)($row['admin_code'] ?? '');
    }
}
?>