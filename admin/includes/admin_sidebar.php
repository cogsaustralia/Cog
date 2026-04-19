<?php
declare(strict_types=1);

if (!function_exists('admin_sidebar_detect_active')) {
    function admin_sidebar_detect_active(): string {
        $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        $section = (string)($_GET['section'] ?? '');
        $focus = (string)($_GET['focus'] ?? '');
        $type = (string)($_GET['type'] ?? '');

        if ($script === 'dashboard.php') return 'dashboard';
        if ($script === 'landing.php') return 'landing';
        if ($script === 'payments.php') return 'payments';
        if ($script === 'kids.php') return 'kids';
        if ($script === 'approvals.php') return 'approvals';
        if ($script === 'execution.php') return 'execution';
        if ($script === 'asset_backing.php') return 'asset_backing';
        if ($script === 'asx_holdings.php') return 'asx_holdings';
        if ($script === 'asx_purchases.php') return 'asx_purchases';
        if ($script === 'rwa_assets.php') return 'rwa_assets';
        if ($script === 'rwa_valuations.php') return 'rwa_valuations';
        if ($script === 'governance.php') return 'governance';
        if ($script === 'operations.php') return 'operations';
        if ($script === 'foundation_day.php') return 'foundation_day';
        if ($script === 'infrastructure.php') return 'infrastructure';
        if ($script === 'zones.php') return 'zones';
        if ($script === 'classes.php') return 'classes';
        if ($script === 'messages.php') {
            if (in_array($section, ['wallet_messages','announcements','proposals','binding_polls','stewardship_responses','email_templates','email_access'], true)) {
                return $section;
            }
            return 'communications';
        }
        if ($script === 'members.php') {
            if ($type === 'personal') return 'members_personal';
            if ($type === 'business') return 'businesses';
            if ($focus === 'wallet_activity') return 'wallet_activity';
            if ($focus === 'beta_exchanges') return 'beta_exchanges';
            return 'partner_registry';
        }
        if ($script === 'businesses.php') return 'businesses';
        if ($script === 'evidence_reviews.php') return 'evidence_reviews';
        if ($script === 'mint_queue.php') return 'mint_queue';
        if ($script === 'mint_batches.php') return 'mint_batches';
        if ($script === 'chain_handoff.php') return 'chain_handoff';
        if ($script === 'audit.php') return 'audit';
        if ($script === 'audit_access.php') return 'audit_access';
        if ($script === 'doc-downloads.php') return 'doc_downloads';
        if ($script === 'reconciliation.php') return 'reconciliation';
        if ($script === 'reconciliation_agent.php') return 'reconciliation_agent';
        if ($script === 'accounting.php') return 'accounting';
        if ($script === 'ledger.php') return 'accounting';
        if ($script === 'expenses.php') return 'expenses';
        if ($script === 'trust_income.php') return 'trust_income';
        if ($script === 'stb_distributions.php') return 'stb_distributions';
        if ($script === 'grants.php') return 'grants';
        if ($script === 'session-check.php') return 'session_check';
        if ($script === 'legacy-dependencies.php') return 'legacy_dependencies';
        if ($script === 'operator_security.php') return 'operator_security';
        if ($script === 'settings.php') return 'settings';
        if ($script === 'exceptions.php') return 'exceptions';
        if ($script === 'email_access.php') return 'email_access';
        if ($script === 'admin_kyc.php') return 'admin_kyc';
        return 'dashboard';
    }
}

if (!function_exists('admin_sidebar_link')) {
    function admin_sidebar_link(string $href, string $label, string $key, string $active): string {
        $isActive = $active === $key ? 'active' : '';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $isActive . '">' .
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}

if (!function_exists('admin_sidebar_styles_once')) {
    function admin_sidebar_styles_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        echo '<link rel="stylesheet" href="./assets/admin.min.css">';
        echo '<style>
:root{--sidebar-w:200px;--sidebar-collapsed:52px}
.admin-shell,.shell{display:grid;grid-template-columns:var(--sidebar-w) minmax(0,1fr);min-height:100vh}
.sidebar{background:linear-gradient(180deg,var(--bg),var(--panel));border-right:1px solid var(--line);padding:10px 8px;min-width:0;overflow:hidden;position:relative;transition:width .2s ease,padding .2s ease}
.sidebar .brand{display:flex;gap:8px;align-items:center;margin:0 0 14px;padding:4px 38px 14px 4px;border-bottom:1px solid var(--line)}
.sidebar .brand img{width:32px;height:32px;border-radius:50%;flex-shrink:0}
.sidebar .brand strong{display:block;font-size:12px;line-height:1.2;color:var(--gold);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar .brand span{color:var(--muted);font-size:10px}
.sidebar .side-section{margin-bottom:4px}
.sidebar .side-toggle{display:flex;align-items:center;justify-content:space-between;padding:6px 9px;margin:0 0 2px;border:1px solid var(--line);border-radius:9px;background:rgba(255,255,255,.02);cursor:pointer;user-select:none;transition:background .15s,border-color .15s}
.sidebar .side-toggle:hover{background:rgba(255,255,255,.04);border-color:var(--line2)}
.sidebar .side-toggle .side-label{font-size:10px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin:0;white-space:normal;overflow:hidden;line-height:1.3}
.sidebar .side-toggle .side-chev{font-size:9px;color:var(--dim);transition:transform .2s;flex-shrink:0}
.sidebar .side-section.open .side-toggle{border-color:var(--goldb);background:rgba(212,178,92,.04)}
.sidebar .side-section.open .side-toggle .side-label{color:var(--gold)}
.sidebar .side-section.open .side-chev{transform:rotate(180deg)}
.sidebar .nav{display:none;padding:2px 0 6px 6px}
.sidebar .side-section.open .nav{display:grid;gap:2px}
.sidebar .nav a{display:block;text-decoration:none;color:var(--sub);padding:6px 9px;border:1px solid transparent;border-radius:8px;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:background .12s,color .12s}
.sidebar .nav a:hover{background:rgba(255,255,255,.04);color:var(--text);border-color:var(--line)}
.sidebar .nav a.active{background:rgba(212,178,92,.1);color:var(--gold);border-color:rgba(212,178,92,.25);font-weight:700}
.sidebar .card{background:rgba(255,255,255,.02);border:1px solid var(--line);border-radius:12px}
.sidebar-toggle{position:absolute;top:8px;right:8px;width:26px;height:26px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);color:var(--text);cursor:pointer;font-size:14px;line-height:26px;text-align:center}
.admin-shell.is-collapsed,.shell.is-collapsed{grid-template-columns:var(--sidebar-collapsed) minmax(0,1fr)}
.sidebar.is-collapsed{padding-inline:6px}
.sidebar.is-collapsed .brand div,.sidebar.is-collapsed .side-section,.sidebar.is-collapsed .card{display:none}
.sidebar.is-collapsed .brand{justify-content:center;margin-top:20px;border-bottom:none}
.sidebar.is-collapsed .brand img{display:block}
@media(max-width:900px){.admin-shell,.shell{grid-template-columns:1fr}.sidebar{border-right:none;border-bottom:1px solid var(--line);max-height:350px;overflow-y:auto}.sidebar-toggle{display:none}}
@media(min-width:701px){.sidebar{height:100vh;overflow-y:auto;position:sticky;top:0}}
</style>
<script>
(function(){
  function boot(){
    var shell=document.querySelector(".admin-shell") || document.querySelector(".shell");
    var sidebar=document.querySelector(".sidebar");
    var btn=document.querySelector(".sidebar-toggle");
    if(!shell||!sidebar||!btn) return;
    var key="cogs_admin_sidebar_v2";
    if(localStorage.getItem(key) !== "0"){ shell.classList.add("is-collapsed"); sidebar.classList.add("is-collapsed"); }
    btn.addEventListener("click", function(){
      shell.classList.toggle("is-collapsed");
      sidebar.classList.toggle("is-collapsed");
      localStorage.setItem(key, sidebar.classList.contains("is-collapsed") ? "1" : "0");
    });
    // Section expand/collapse
    sidebar.querySelectorAll(".side-toggle").forEach(function(t){
      t.addEventListener("click", function(){
        var sec = t.closest(".side-section");
        if(sec) sec.classList.toggle("open");
        saveSections();
      });
    });
    // All sections start collapsed — only re-open ones user has explicitly expanded
    var saved = {};
    try{ saved = JSON.parse(localStorage.getItem("cogs_admin_sections_v2")||"{}"); }catch(e){}
    sidebar.querySelectorAll(".side-section").forEach(function(sec){
      var id = sec.dataset.sec;
      if(saved[id]) sec.classList.add("open");
    });
    function saveSections(){
      var state = {};
      sidebar.querySelectorAll(".side-section").forEach(function(s){
        if(s.classList.contains("open")) state[s.dataset.sec] = 1;
      });
      try{ localStorage.setItem("cogs_admin_sections_v2", JSON.stringify(state)); }catch(e){}
    }
  }
  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded", boot);}else{boot();}
})();
</script>';
    }
}

if (!function_exists('admin_sidebar_render')) {
    function admin_sidebar_render(string $active = ''): void
    {
        if ($active === '') $active = admin_sidebar_detect_active();
        admin_sidebar_styles_once();

        $groups = [
            'Operations' => [
                ['key' => 'dashboard',         'label' => '◈  Dashboard',                 'href' => './dashboard.php'],
                ['key' => 'partner_registry',  'label' => '👥  Partners',                 'href' => './members.php'],
                ['key' => 'businesses',        'label' => '🏢  Businesses',               'href' => './businesses.php'],
                ['key' => 'payments',          'label' => '💳  Payments',                  'href' => './payments.php'],
                ['key' => 'approvals',         'label' => '✅  Approvals',                 'href' => './approvals.php'],
                ['key' => 'kids',              'label' => '👶  Kids Tokens',               'href' => './kids.php'],
                ['key' => 'execution',         'label' => '⛓  Token Execution',           'href' => './execution.php'],
                ['key' => 'classes',           'label' => '🪙  COG$ Classes',              'href' => './classes.php'],
                ['key' => 'settings',          'label' => '⚙  Settings',                  'href' => './settings.php'],
            ],
            'Communications' => [
                ['key' => 'wallet_messages',       'label' => '✉  Wallet Notices',        'href' => './messages.php?section=wallet_messages'],
                ['key' => 'announcements',         'label' => '📢  Announcements',         'href' => './messages.php?section=announcements'],
                ['key' => 'proposals',             'label' => '📝  Proposals',             'href' => './messages.php?section=proposals'],
                ['key' => 'binding_polls',         'label' => '⚖  Binding Polls',         'href' => './messages.php?section=binding_polls'],
                ['key' => 'stewardship_responses', 'label' => '◈  Stewardship Responses', 'href' => './messages.php?section=stewardship_responses'],
                ['key' => 'email_templates',       'label' => '📄  Email Templates',       'href' => './messages.php?section=email_templates'],
            ],
            'Governance & Compliance' => [
                ['key' => 'governance',        'label' => '🗳  Governance',                'href' => './governance.php'],
                ['key' => 'operations',        'label' => '🤝  Partner Operations',        'href' => './operations.php'],
                ['key' => 'foundation_day',    'label' => '🎉  Foundation Day',            'href' => './foundation_day.php'],
                ['key' => 'zones',             'label' => '📍  Geographic Zones',          'href' => './zones.php'],
                ['key' => 'evidence_reviews',  'label' => '📋  Evidence Reviews',          'href' => './evidence_reviews.php'],
                ['key' => 'admin_kyc',         'label' => '🪪  KYC Review',               'href' => './admin_kyc.php'],
                ['key' => 'exceptions',        'label' => '⚠  Exceptions',                'href' => './exceptions.php'],
                ['key' => 'audit',             'label' => '📜  Audit Log',                 'href' => './audit.php'],
                ['key' => 'audit_access',      'label' => '🔐  Audit Access',              'href' => './audit_access.php'],
            ],
            'Investments & Assets' => [
                ['key' => 'asx_holdings',       'label' => '📈  ASX Holdings',             'href' => './asx_holdings.php'],
                ['key' => 'asx_purchases',      'label' => '🧾  ASX Purchases',            'href' => './asx_purchases.php'],
                ['key' => 'rwa_assets',         'label' => '🪨  Real-World Assets',        'href' => './rwa_assets.php'],
                ['key' => 'rwa_valuations',     'label' => '💠  RWA Valuations',           'href' => './rwa_valuations.php'],
                ['key' => 'asset_backing',      'label' => '🧷  Asset Collateral',         'href' => './asset_backing.php'],
            ],
            'Trust Accounting' => [
                ['key' => 'accounting',        'label' => '📊  Accounting',               'href' => './accounting.php'],
                ['key' => 'expenses',          'label' => '🧾  Expenses',                  'href' => './expenses.php'],
                ['key' => 'trust_income',      'label' => '💰  Trust Income',              'href' => './trust_income.php'],
                ['key' => 'stb_distributions', 'label' => '📤  Sub-Trust B Distributions', 'href' => './stb_distributions.php'],
                ['key' => 'grants',            'label' => '🌿  Community Grants',          'href' => './grants.php'],
            ],
            'System & Administration' => [
                ['key' => 'infrastructure',    'label' => '🛰  Blockchain Infrastructure', 'href' => './infrastructure.php'],
                ['key' => 'reconciliation_agent', 'label' => '🤖  AI Reconciliation',     'href' => './reconciliation_agent.php'],
                ['key' => 'doc_downloads',     'label' => '📥  Document Downloads',        'href' => './doc-downloads.php'],
                ['key' => 'email_access',      'label' => '📮  Email Access',              'href' => './email_access.php'],
                ['key' => 'operator_security', 'label' => '🔐  Admin Security',            'href' => './operator_security.php'],
            ],
            'Bridge / Diagnostics' => [
                ['key' => 'reconciliation',     'label' => '🔍  Legacy Reconciliation',    'href' => './reconciliation.php'],
                ['key' => 'mint_queue',         'label' => '⛏  Token Mint Queue',         'href' => './mint_queue.php'],
                ['key' => 'mint_batches',       'label' => '📦  Token Mint Batches',       'href' => './mint_batches.php'],
                ['key' => 'chain_handoff',      'label' => '🔗  Blockchain Handoff',       'href' => './chain_handoff.php'],
                ['key' => 'session_check',      'label' => '🔐  Session Check',            'href' => './session-check.php'],
                ['key' => 'legacy_dependencies','label' => '🧩  Bridge Status',            'href' => './legacy-dependencies.php'],
            ],
        ];
        ?>
        <aside class="sidebar">
          <button type="button" class="sidebar-toggle" title="Collapse navigation">≡</button>
          <div class="brand">
            <img src="./assets/logo_webcir.png" alt="COG$ Australia" onerror="this.style.display='none'">
            <div><strong>COG$ of AUSTRALIA FOUNDATION</strong><span>Authoritative control plane</span></div>
          </div>
          <?php foreach ($groups as $groupLabel => $links):
            $secId = strtolower(str_replace([' ', '&', '$'], ['_','',''], $groupLabel));
            $hasActive = false;
            foreach ($links as $link) { if ($active === $link['key']) { $hasActive = true; break; } }
          ?>
            <div class="side-section<?= $hasActive ? ' open' : '' ?>" data-sec="<?= htmlspecialchars($secId, ENT_QUOTES, 'UTF-8') ?>">
              <div class="side-toggle">
                <p class="side-label"><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></p>
                <span class="side-chev">▼</span>
              </div>
              <div class="nav">
                <?php foreach ($links as $link): echo admin_sidebar_link($link['href'], $link['label'], $link['key'], $active); endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="card" style="padding:12px;margin-top:12px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
              <div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#9fb0c1">Using Admin</div>
              <?php if (function_exists('ops_admin_help_button')): ?>
                <?= ops_admin_help_button('Admin navigation', 'Operations pages are the live operator route for day-to-day tasks. Governance & Compliance pages cover legal records, evidence, and decision trails. Trust Accounting pages are for recording and reviewing all financial flows. Bridge / Diagnostics pages are for transitional checks and are not used for primary operations.') ?>
              <?php endif; ?>
            </div>
            <p style="margin:0 0 10px;color:#9fb0c1;font-size:11.5px;line-height:1.55">Start on Dashboard for orientation, then move through Payments, Approvals, and Execution for live operations. Use diagnostics only when you are checking bridge state or investigating a fault.</p>
            <div class="nav" style="display:grid;gap:6px;padding:0">
              <?= admin_sidebar_link('./dashboard.php', 'Dashboard guide', 'dashboard', $active) ?>
              <?= admin_sidebar_link('./landing.php', 'Admin guide & workflow', 'landing', $active) ?>
              <?= admin_sidebar_link('./legacy-dependencies.php', 'Bridge status', 'legacy_dependencies', $active) ?>
            </div>
          </div>
        </aside>
        <?php
    }
}
?>
