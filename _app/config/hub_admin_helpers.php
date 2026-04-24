<?php
declare(strict_types=1);
/**
 * hub_admin_helpers.php — Helper functions for the hub → admin-page mapping.
 *
 * Require this file; it loads hub_admin_map.php once via static cache.
 * All functions are pure: no DB queries, no side effects.
 */

if (!function_exists('hub_admin_map')) {
    function hub_admin_map(): array
    {
        static $map = null;
        if ($map === null) {
            $map = require __DIR__ . '/hub_admin_map.php';
        }
        return $map;
    }
}

if (!function_exists('hub_admin_pages_for')) {
    /**
     * Returns which admin page keys serve the given area_key.
     * ['primary' => [...], 'secondary' => [...]]
     */
    function hub_admin_pages_for(string $area_key): array
    {
        $entry = hub_admin_map()[$area_key] ?? null;
        if (!$entry) return ['primary' => [], 'secondary' => []];
        return ['primary' => $entry['primary'], 'secondary' => $entry['secondary']];
    }
}

if (!function_exists('hub_admin_hubs_for_page')) {
    /**
     * Inverse lookup: returns which hub area_keys a given admin page serves.
     * ['primary' => [...area_keys], 'secondary' => [...area_keys]]
     */
    function hub_admin_hubs_for_page(string $page_key): array
    {
        $primary   = [];
        $secondary = [];
        foreach (hub_admin_map() as $area_key => $entry) {
            if (in_array($page_key, $entry['primary'], true)) {
                $primary[] = $area_key;
            } elseif (in_array($page_key, $entry['secondary'], true)) {
                $secondary[] = $area_key;
            }
        }
        return ['primary' => $primary, 'secondary' => $secondary];
    }
}

if (!function_exists('hub_admin_label')) {
    /** Full human label for an area_key. */
    function hub_admin_label(string $area_key): string
    {
        return hub_admin_map()[$area_key]['label'] ?? $area_key;
    }
}

if (!function_exists('hub_admin_short_label')) {
    /** Short label for an area_key — used in sidebar chip display. */
    function hub_admin_short_label(string $area_key): string
    {
        return hub_admin_map()[$area_key]['short'] ?? $area_key;
    }
}

if (!function_exists('hub_admin_page_label')) {
    /** Human-readable label for an admin page key. */
    function hub_admin_page_label(string $page_key): string
    {
        static $labels = [
            'dashboard'             => 'Dashboard',
            'partner_registry'      => 'Members',
            'members_personal'      => 'Personal Members',
            'businesses'            => 'Businesses',
            'payments'              => 'Payments',
            'approvals'             => 'Approvals',
            'kids'                  => 'Kids Tokens',
            'classes'               => 'COG$ Classes',
            'settings'              => 'Settings',
            'wallet_messages'       => 'Wallet Notices',
            'announcements'         => 'Announcements',
            'proposals'             => 'Proposals',
            'binding_polls'         => 'Binding Polls',
            'stewardship_responses' => 'Stewardship Responses',
            'email_templates'       => 'Email Templates',
            'email_access'          => 'Email Access',
            'governance'            => 'Governance',
            'operations'            => 'Member Operations',
            'zones'                 => 'Geographic Zones',
            'evidence_reviews'      => 'Evidence Reviews',
            'exceptions'            => 'Exceptions',
            'audit'                 => 'Audit Log',
            'audit_access'          => 'Audit Access',
            'asx_holdings'          => 'ASX Holdings',
            'asx_purchases'         => 'ASX Purchases',
            'rwa_assets'            => 'Real-World Assets',
            'rwa_valuations'        => 'RWA Valuations',
            'asset_backing'         => 'Asset Collateral',
            'accounting'            => 'Accounting',
            'expenses'              => 'Expenses',
            'trust_income'          => 'Trust Income',
            'stb_distributions'     => 'Sub-Trust B Distributions',
            'grants'                => 'Community Grants',
            'infrastructure'        => 'Blockchain Infrastructure',
            'reconciliation_agent'  => 'AI Reconciliation',
            'doc_downloads'         => 'Document Downloads',
            'monitor'               => 'Site Monitor',
            'errors'                => 'Error Log',
            'hub_queries'           => 'Hub Queries',
            'reconciliation'        => 'Legacy Reconciliation',
            'mint_queue'            => 'Token Mint Queue',
            'mint_batches'          => 'Token Mint Batches',
            'legacy_dependencies'   => 'Bridge Status',
            'wallet_activity'       => 'Wallet Activity',
            'beta_exchanges'        => 'Beta Exchanges',
        ];
        return $labels[$page_key] ?? $page_key;
    }
}
