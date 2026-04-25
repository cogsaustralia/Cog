<?php
declare(strict_types=1);
/**
 * hub_admin_map.php — Hub → Admin Page Map
 *
 * Returns the canonical mapping of the 9 management hub area_keys to the
 * admin page-keys that serve them.  Keys match admin_sidebar_detect_active()
 * return values exactly.
 *
 * Trustee-designated pages are excluded by absence:
 *   execution, execution_records, founding_documents,
 *   generate_trustee_token, generate_declaration_token,
 *   generate_sub_trust_a_token, generate_sub_trust_b_token,
 *   generate_sub_trust_c_token, foundation_day, chain_handoff,
 *   admin_kyc, operator_security, session_check.
 *
 * @return array<string, array{label:string, short:string, primary:list<string>, secondary:list<string>}>
 */
return [
    'operations_oversight' => [
        'label'         => 'Day-to-Day Operations',
        'short'         => 'Ops',
        'sub_committee' => 'STA',
        'primary'       => ['dashboard', 'approvals', 'operations', 'exceptions', 'monitor', 'errors'],
        'secondary'     => [],
    ],
    'governance_polls' => [
        'label'         => 'Research & Acquisitions',
        'short'         => 'Research',
        'sub_committee' => 'STB',
        'primary'       => ['governance', 'proposals', 'binding_polls', 'asx_purchases', 'rwa_valuations'],
        'secondary'     => ['businesses'],
    ],
    'esg_proxy_voting' => [
        'label'         => 'ESG & Proxy Voting',
        'short'         => 'ESG',
        'sub_committee' => 'STB',
        'primary'       => ['asx_holdings', 'binding_polls', 'governance'],
        'secondary'     => ['asset_backing'],
    ],
    'first_nations' => [
        'label'         => 'First Nations Joint Venture',
        'short'         => 'First Nations',
        'sub_committee' => 'STC',
        'primary'       => ['zones', 'grants', 'evidence_reviews'],
        'secondary'     => ['stb_distributions'],
    ],
    'community_projects' => [
        'label'         => 'Community Projects',
        'short'         => 'Community',
        'sub_committee' => 'STC',
        'primary'       => ['grants', 'trust_income', 'announcements', 'stewardship_responses'],
        'secondary'     => [],
    ],
    'technology_blockchain' => [
        'label'         => 'Technology & Blockchain',
        'short'         => 'Tech',
        'sub_committee' => 'STA',
        'primary'       => ['infrastructure', 'reconciliation_agent', 'reconciliation', 'mint_queue', 'mint_batches', 'legacy_dependencies', 'hub_queries'],
        'secondary'     => ['doc_downloads'],
    ],
    'financial_oversight' => [
        'label'         => 'Financial Oversight',
        'short'         => 'Finance',
        'sub_committee' => 'STA',
        'primary'       => ['accounting', 'expenses', 'trust_income', 'payments', 'stb_distributions', 'audit', 'audit_access', 'classes'],
        'secondary'     => ['wallet_activity', 'beta_exchanges'],
    ],
    'place_based_decisions' => [
        'label'         => 'Place-Based Decisions',
        'short'         => 'Place',
        'sub_committee' => 'STC',
        'primary'       => ['zones', 'rwa_assets'],
        'secondary'     => ['asset_backing'],
    ],
    'education_outreach' => [
        'label'         => 'Education & Outreach',
        'short'         => 'Education',
        'sub_committee' => 'STB',
        'primary'       => ['partner_registry', 'members_personal', 'wallet_messages', 'announcements', 'email_templates', 'kids', 'email_access'],
        'secondary'     => ['settings'],
    ],
];
