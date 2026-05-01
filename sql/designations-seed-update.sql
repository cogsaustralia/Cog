-- =============================================================================
-- COG$ of Australia Foundation
-- ESG Improvement Objectives — full seed update (replaces NEEDS DOCX placeholders)
-- Also seeds: lobbyist_response_register and reform_history_episodes
-- Source: Dual_Poor_ESG_Target_Acquisition_Strategy_v1_1.docx (Part 7, Part 11)
--         Foundation_Day_Script_FairSayRelay_v1_1.docx (§5.3 reform history)
-- Target DB: cogsaust_TRUST
-- Run via phpMyAdmin after confirming designation IDs:
--   SELECT id, asx_code FROM poor_esg_target_designations ORDER BY id;
--   (Santos = 1, Origin = 2)
-- =============================================================================

SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. ESG improvement objectives — Santos (designation_id = 1)
--    Replaces all 5 placeholder rows
-- ---------------------------------------------------------------------------
UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: independent annual reporting on Munupi clan and Tiwi Islanders engagement standards under any Barossa litigation outcome; independent annual reporting on the Additional Research Program required by the Narrabri Gas Project Aboriginal Cultural Heritage Management Plan as a condition of NNTT [2022] NNTTA 74; transparent disclosure of Santos\'s Registered Aboriginal Party selection methodology in NSW; and reporting on engagement with Yandruwandha Yawarrawarrka cultural-heritage standards in the Cooper Basin operating area.',
    `target_agm_year` = 2026,
    `display_order` = 1
WHERE `designation_id` = 1 AND `objective_category` = 'first_nations';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: annual disclosure of Santos\'s Australian effective tax rate, Petroleum Resource Rent Tax position, and franking-account balance, in a format consistent with the ATO Tax Transparency framework; commitment to maintain franking-account balances sufficient to fully frank ordinary dividends from Australian-sourced earnings within a reasonable time horizon; and transparent reporting on related-party transactions, debt-financing structure, and transfer-pricing arrangements relevant to Australian tax minimisation.',
    `target_agm_year` = 2026,
    `display_order` = 2
WHERE `designation_id` = 1 AND `objective_category` = 'taxation';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: annual say-on-climate vote with binding effect; Scope 1, 2 and 3 emissions disclosure aligned with the most demanding internationally recognised standard at the time of vote; capital-allocation reporting separating decarbonisation expenditure from greenwashing-adjacent activities; and Moomba Carbon Capture and Storage project independent verification.',
    `target_agm_year` = 2026,
    `display_order` = 3
WHERE `designation_id` = 1 AND `objective_category` = 'emissions';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Santos has no direct retail energy customers — retail pricing objective not applicable to STO designation. This category is reserved for Origin Energy (ORG) designation only.',
    `target_agm_year` = NULL,
    `display_order` = 4
WHERE `designation_id` = 1 AND `objective_category` = 'retail_pricing';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: annual director-by-director vote on re-election; explicit linkage between executive remuneration and the ESG improvement objectives published in the Foundation\'s Dual Poor ESG Target Acquisition Strategy; and disclosure of all post-employment lobbying or government-relations engagements undertaken by former executives and directors.',
    `target_agm_year` = 2026,
    `display_order` = 5
WHERE `designation_id` = 1 AND `objective_category` = 'director_accountability';

-- ---------------------------------------------------------------------------
-- 2. ESG improvement objectives — Origin (designation_id = 2)
--    Replaces all 5 placeholder rows
-- ---------------------------------------------------------------------------
UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: annual reporting on engagement with Wakka Wakka, Iman, Mandandanji, and adjacent Queensland Aboriginal nations affected by APLNG operations; transparent reporting on cultural-heritage management consistent with Queensland\'s Aboriginal Cultural Heritage Act 2003; and engagement with Awabakal, Worimi, Wonnarua, and adjacent NSW Aboriginal nations affected by Eraring transition.',
    `target_agm_year` = 2026,
    `display_order` = 1
WHERE `designation_id` = 2 AND `objective_category` = 'first_nations';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: annual disclosure of Origin\'s Australian effective tax rate and APLNG equity-share tax contribution; transparent disclosure of transfer-pricing arrangements between Origin Energy Limited and APLNG Joint Venture participants; and franking-account reporting consistent with ATO Tax Transparency framework.',
    `target_agm_year` = 2026,
    `display_order` = 2
WHERE `designation_id` = 2 AND `objective_category` = 'taxation';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: published timetable for Eraring closure and renewable-replacement capacity commissioning; capital-allocation transparency between fossil-extension projects and renewable-investment projects; and APLNG governance transparency including Origin\'s published positions on APLNG capital expenditure decisions.',
    `target_agm_year` = 2026,
    `display_order` = 3
WHERE `designation_id` = 2 AND `objective_category` = 'emissions';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'Direct member-aggregated proxy votes toward: independent annual disclosure of Origin\'s residential and small-business effective margin in each market; quarterly hardship-customer support reporting including disconnection statistics and concession application rates; transparent disclosure of the relationship between APLNG export volumes and east-coast wholesale gas prices, including any operational or financial flexibility Origin retains over that relationship; and cap on residential gas and electricity standing-offer prices relative to default market offer benchmarks.',
    `target_agm_year` = 2026,
    `display_order` = 4
WHERE `designation_id` = 2 AND `objective_category` = 'retail_pricing';

UPDATE `esg_improvement_objectives`
SET `objective_text` =
  'As Santos, plus: disclosure of any APLNG-board representation conflicts; and disclosure of any Origin director\'s prior or concurrent gas-industry consulting, lobbying, or board engagements that may bear on APLNG capital expenditure decisions or east-coast gas pricing positions.',
    `target_agm_year` = 2026,
    `display_order` = 5
WHERE `designation_id` = 2 AND `objective_category` = 'director_accountability';

-- ---------------------------------------------------------------------------
-- 3. Reform history episodes — replace all 6 placeholder rows
--    Source: Strategy Part 3 / Script §5.3
-- ---------------------------------------------------------------------------
UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Australian Industry Development Corporation (AIDC)',
  `prime_minister_or_premier` = 'John Gorton',
  `party` = 'Liberal',
  `what_was_attempted` = 'Liberal PM John Gorton and Country Party leader John McEwen establish a national investment fund to take equity stakes in Australian resource development, alongside — not against — private extraction. Gorton\'s January 1970 Cabinet submission used a single bauxite shipment to demonstrate that exporting unprocessed minerals robbed Australia of multiples of the value retained in-country.',
  `how_defeated` = 'Gorton was deposed by his own Liberal Party in a tied confidence vote on 10 March 1971, partly precipitated by his offshore minerals legislation. McMahon scaled the AIDC back. Over subsequent decades the AIDC drifted away from its founding purpose and was privatised by the Hawke and Howard governments.',
  `display_order` = 1
WHERE `episode_year` = 1970;

UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Petroleum and Minerals Authority (PMA)',
  `prime_minister_or_premier` = 'Gough Whitlam',
  `party` = 'Labor',
  `what_was_attempted` = 'Labor PM Gough Whitlam, with Rex Connor as Minister for Minerals and Energy, establishes a government-owned and controlled minerals and resources company with power to explore, lend, take equity stakes, and develop petroleum and mineral resources. Bob Hawke would later describe this as one of the most consequential resource-nationalist initiatives in Australian history.',
  `how_defeated` = 'Bill blocked twice in the Senate. Passed by unprecedented joint sitting of both Houses on 7 August 1974. Struck down by the High Court (Barwick CJ and four Liberal-appointed judges) on a procedural Section 57 timing point. The Whitlam government was dismissed by the Governor-General on 11 November 1975. Loans Affair, Senate supply blockade, US State Department documented intervention.',
  `display_order` = 2
WHERE `episode_year` = 1973;

UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Petroleum Resource Rent Tax (PRRT)',
  `prime_minister_or_premier` = 'Bob Hawke',
  `party` = 'Labor',
  `what_was_attempted` = 'Labor PM Bob Hawke and Treasurer Paul Keating introduce a 40 per cent profits-based tax on offshore petroleum projects, to capture the community\'s share of resource rents from offshore oil and gas.',
  `how_defeated` = 'Industry-funded multi-decade campaign of deductions accumulation. Compounding deductions grew faster than offshore revenue for years. Treasury confirmed in evidence to the Senate that no new offshore gas project has paid meaningful PRRT to date. The instrument remains on the books; the rents do not arrive.',
  `display_order` = 3
WHERE `episode_year` = 1987;

UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Resource Super Profits Tax (RSPT)',
  `prime_minister_or_premier` = 'Kevin Rudd',
  `party` = 'Labor',
  `what_was_attempted` = 'Labor PM Kevin Rudd proposes a 40 per cent profits-based tax on mining super-profits, structured to target windfall returns above $50 million while preserving small-mining-operation viability.',
  `how_defeated` = '$100 million-plus mining-industry advertising and political-pressure campaign within weeks of the announcement. Rudd was deposed by his own Labor Party on 24 June 2010. Successor Julia Gillard weakened the tax to a Minerals Resource Rent Tax that raised approximately $300 million instead of the projected $50 billion. The Abbott government repealed even that residual.',
  `display_order` = 4
WHERE `episode_year` = 2010;

UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Queensland Coal Royalty Tier Increase',
  `prime_minister_or_premier` = 'Steven Miles',
  `party` = 'Qld Labor',
  `what_was_attempted` = 'Queensland Labor Premier Steven Miles introduces progressive coal-royalty tiers, using the receipts to deliver electricity-bill rebates and 50-cent public-transport fares to Queenslanders.',
  `how_defeated` = 'Industry-funded media campaign focused on a youth crime wave — notwithstanding declining youth crime statistics under the same government — shifted electoral framing. Miles\'s Labor government lost the October 2024 Queensland state election. Mining industry groups publicly congratulated the incoming LNP government on the change of administration.',
  `display_order` = 5
WHERE `episode_year` = 2022;

UPDATE `reform_history_episodes`
SET
  `episode_label` = 'Senate Inquiry — 25% Gas Export Tax Proposal',
  `prime_minister_or_premier` = 'Senate Standing Committee',
  `party` = 'Cross-bench',
  `what_was_attempted` = 'Senate Standing Committees on Economics inquiry, public hearing 21 April 2026. Australia Institute and Punters Politics testimony advocating a 25 per cent gas export tax replacing or supplementing the failed PRRT. Federal Budget 13 May 2026 will indicate the political reception.',
  `how_defeated` = 'Outcome pending as at May 2026. Industry advertising response launched within days of the Senate hearing. The same defensive machinery that defeated each of the prior five instruments is operational.',
  `display_order` = 6
WHERE `episode_year` = 2026;

-- ---------------------------------------------------------------------------
-- 4. Lobbyist response register — update NEEDS DOCX placeholders
--    Source: Strategy Part 11 (12 general) + FNAC Part 9 §24 (5 FN-specific)
-- ---------------------------------------------------------------------------

-- General attack vectors — update by display_order (structure already seeded correctly)
-- Rows 1-12 already have correct attack text and countermeasures from the initial seed
-- Only the FN-specific rows (13-17) had placeholder countermeasure text

UPDATE `lobbyist_response_register`
SET `structural_countermeasure_text` =
  'The FNAC framework requires written consultation with the nominating LALC before any designation is confirmed under clause 30.3. Governance partner status is conferred by deed, not by press release. The Foundation\'s First Nations governance partners hold binding FNAC nomination rights, FPIC veto authority, and Elder cryptographic key custody over Sovereign Node infrastructure. This is structural governance, not tokenistic engagement.'
WHERE `display_order` = 13 AND `is_first_nations_specific` = 1;

UPDATE `lobbyist_response_register`
SET `structural_countermeasure_text` =
  'The FNAC is constituted under the JVPA as a designation review body — not a land rights body, not a cultural authority, and not a replacement for LALC governance. The FNAC\'s role is bounded: it reviews Poor ESG Target designations, endorses Sovereign Node infrastructure, and exercises binding authority over Sub-Trust C grant-making. It does not duplicate or replace any function of the land council system.'
WHERE `display_order` = 14 AND `is_first_nations_specific` = 1;

UPDATE `lobbyist_response_register`
SET `structural_countermeasure_text` =
  'Clause 30.3 of the JVPA, entrenched under clause 35(r), requires FNAC review before any acquisition mandate is issued. Consultation is a constitutional precondition, not an afterthought. The seven-milestone FNAC pathway is published publicly at cogsaustralia.org/governance/fnac-review/ and updated within 5 business days of each milestone. No milestone can be skipped.'
WHERE `display_order` = 15 AND `is_first_nations_specific` = 1;

UPDATE `lobbyist_response_register`
SET `structural_countermeasure_text` =
  'Sub-Trust C is constituted exclusively for First Nations benefit, with 30% minimum priority from all distributions entrenched as clause 1.5(af). Governance partner status confers real decision-making power: FNAC nomination rights, FPIC veto, binding authority over board appointments and Affected Zone declarations. Automatic zero-cost COG$ token issuance applies to all LALCs and PBCs under clause 35(aa).'
WHERE `display_order` = 16 AND `is_first_nations_specific` = 1;

UPDATE `lobbyist_response_register`
SET `structural_countermeasure_text` =
  'The in-ground resource thesis — that in-ground minerals retain real, measurable economic value as appreciating stewardship assets, recognisable without extraction and potentially in lieu of extraction entirely — is developed in partnership with First Nations governance partners, not in substitution for their authority or custodianship. The Foundation acknowledges that in-ground minerals carry real value belonging to the community; First Nations custodianship is the governance layer over that value, not a claim the Foundation makes on its own behalf.'
WHERE `display_order` = 17 AND `is_first_nations_specific` = 1;

COMMIT;

-- ---------------------------------------------------------------------------
-- VERIFICATION QUERIES — run after COMMIT
-- ---------------------------------------------------------------------------
-- SELECT designation_id, objective_category, LEFT(objective_text,80) as preview
--   FROM esg_improvement_objectives ORDER BY designation_id, display_order;
-- SELECT episode_year, episode_label, LEFT(what_was_attempted,60) as preview
--   FROM reform_history_episodes ORDER BY display_order;
-- SELECT display_order, attack_category, LEFT(structural_countermeasure_text,80) as preview
--   FROM lobbyist_response_register WHERE is_first_nations_specific=1 ORDER BY display_order;
-- ---------------------------------------------------------------------------
