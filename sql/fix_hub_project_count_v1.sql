-- =============================================================================
-- COG$ of Australia Foundation — Fix active_project_count in hub summary view
--
-- PROBLEM: v_hub_mainspring_summary counts only status IN ('proposed','active')
-- which are legacy statuses. All CRC projects use the lifecycle status schema:
--   draft, open_for_input, deliberation, vote, accountability, archived
-- Result: Projects stat strip and Mainspring tiles show 0 for all hubs.
--
-- FIX: Recreate the view to count all non-archived projects regardless of
-- which status schema they use.
--
-- Run via phpMyAdmin against cogsaust_TRUST.
-- =============================================================================

CREATE OR REPLACE ALGORITHM=UNDEFINED
  DEFINER=`cogsaust`@`localhost`
  SQL SECURITY INVOKER
VIEW `v_hub_mainspring_summary` AS
SELECT
  ak.area_key,

  -- Member count (unchanged)
  (SELECT COUNT(0) FROM v_hub_roster r
    WHERE r.area_key = ak.area_key)
  AS member_count,

  -- Thread count (unchanged)
  (SELECT COUNT(0) FROM partner_op_threads t
    WHERE t.area_key = ak.area_key
      AND t.status <> 'archived')
  AS thread_count,

  -- Active project count — now counts ALL non-archived projects
  -- regardless of status schema (legacy: proposed/active;
  -- lifecycle: draft/open_for_input/deliberation/vote/accountability)
  (SELECT COUNT(0) FROM hub_projects p
    WHERE p.area_key = ak.area_key
      AND p.status <> 'archived')
  AS active_project_count,

  -- Last activity (unchanged)
  (SELECT MAX(a.occurred_at) FROM v_hub_activity a
    WHERE a.area_key = ak.area_key)
  AS last_activity_at

FROM (
  SELECT 'operations_oversight'   AS area_key UNION ALL
  SELECT 'governance_polls'                   UNION ALL
  SELECT 'esg_proxy_voting'                   UNION ALL
  SELECT 'first_nations'                      UNION ALL
  SELECT 'community_projects'                 UNION ALL
  SELECT 'technology_blockchain'              UNION ALL
  SELECT 'financial_oversight'               UNION ALL
  SELECT 'place_based_decisions'             UNION ALL
  SELECT 'education_outreach'
) AS ak;

-- =============================================================================
-- Verification — run after the view is recreated:
-- =============================================================================
-- SELECT area_key, member_count, thread_count, active_project_count
--   FROM v_hub_mainspring_summary
--  ORDER BY area_key;
--
-- Expected: community_projects.active_project_count = 4
-- (Corridors Communications, Corridors Energy: Electricity,
--  Corridors Energy: LPG, Corridors Transport and Freight)
-- All other hubs should be 0 unless they have their own owned projects.

-- End of fix_hub_project_count_v1.sql
