<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';

// Define monitoring data directory (2 levels up from public_html/admin/)
define('COGS_ALERT_DIR', dirname(__DIR__, 2));

// Handle AJAX requests for monitoring data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'monitor-data') {
    ops_require_admin();
    
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    
    $response = [
        'current_alert' => null,
        'alert_history' => [],
        'current_metrics' => null
    ];
    
    // Read current alert file
    $alertFile = COGS_ALERT_DIR . '/cogs-alert.json';
    if (file_exists($alertFile)) {
        $content = file_get_contents($alertFile);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $response['current_alert'] = $decoded;
            }
        }
    }
    
    // Read alert history file
    $historyFile = COGS_ALERT_DIR . '/cogs-alert-history.json';
    if (file_exists($historyFile)) {
        $content = file_get_contents($historyFile);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $response['alert_history'] = $decoded;
            }
        }
    }
    
    // Read current metrics file
    $metricsFile = COGS_ALERT_DIR . '/cogs-metrics.json';
    if (file_exists($metricsFile)) {
        $content = file_get_contents($metricsFile);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $response['current_metrics'] = $decoded;
            }
        }
    }
    
    echo json_encode($response);
    exit;
}

ops_require_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COGS Monitoring Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 40px; }
        .header h1 { font-size: 2.5em; margin-bottom: 10px; }
        .status-card { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border: 1px solid #334155; border-radius: 12px; padding: 30px; text-align: center; margin-bottom: 30px; }
        .status-indicator { width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 15px; }
        .status-healthy { background: #10b981; box-shadow: 0 0 20px rgba(16, 185, 129, 0.5); }
        .status-alert { background: #ef4444; box-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
        .status-text { font-size: 1.3em; margin: 15px 0; }
        .last-check { font-size: 0.9em; color: #94a3b8; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .card { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .card h2 { font-size: 1.2em; margin-bottom: 15px; color: #cbd5e1; }
        .metric { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155; }
        .metric:last-child { border-bottom: none; }
        .metric-label { color: #94a3b8; }
        .metric-value { font-weight: 600; }
        .alerts-list { max-height: 400px; overflow-y: auto; }
        .alert-item { background: #1e293b; border-left: 3px solid #ef4444; padding: 12px; margin-bottom: 10px; border-radius: 6px; }
        .alert-time { font-size: 0.85em; color: #94a3b8; }
        .alert-title { font-weight: 600; color: #f1f5f9; }
        .recommendation { background: #1e40af; border: 1px solid #3b82f6; border-radius: 8px; padding: 20px; text-align: center; margin-top: 30px; }
        .recommendation h3 { margin-bottom: 10px; font-size: 1.1em; }
        .recommendation-yes { background: #065f46; border-color: #10b981; color: #d1fae5; }
        .recommendation-no { background: #1e293b; border-color: #64748b; }
        .footer { text-align: center; margin-top: 40px; color: #64748b; font-size: 0.9em; }

        /* ── JVPA Funnel panel ─────────────────────────────── */
        .funnel-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .funnel-stat { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 14px; text-align: center; }
        .funnel-stat .fs-val { font-size: 1.8em; font-weight: 700; }
        .funnel-stat .fs-lbl { font-size: 0.78em; color: #94a3b8; margin-top: 4px; }
        .funnel-warn { color: #f59e0b; }
        .funnel-ok   { color: #10b981; }
        .funnel-dim  { color: #64748b; }
        .click-table { width: 100%; border-collapse: collapse; font-size: 0.82em; }
        .click-table th { text-align: left; color: #64748b; padding: 6px 8px; border-bottom: 1px solid #334155; }
        .click-table td { padding: 6px 8px; border-bottom: 1px solid #1e293b; color: #cbd5e1; }
        .click-table tr:last-child td { border-bottom: none; }
        .not-ready-note { color: #64748b; font-size: 0.85em; text-align: center; padding: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 COGS Monitoring Dashboard</h1>
            <p>Real-time system health and performance metrics</p>
        </div>

        <div class="status-card">
            <div class="status-indicator" id="statusIndicator"></div>
            <div class="status-text" id="statusText">Loading...</div>
            <div class="last-check" id="lastCheck">Checking system health...</div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>📊 Current Metrics</h2>
                <div class="metric">
                    <span class="metric-label">Requests/sec</span>
                    <span class="metric-value" id="reqPerSec">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Error Rate</span>
                    <span class="metric-value" id="errorRate">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">HTTP Errors</span>
                    <span class="metric-value" id="httpErrors">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">PHP Errors</span>
                    <span class="metric-value" id="phpErrors">—</span>
                </div>
            </div>

            <div class="card">
                <h2>📈 Trend Analysis</h2>
                <div class="metric">
                    <span class="metric-label">Peak Traffic</span>
                    <span class="metric-value" id="peakTraffic">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Avg Error Rate (7d)</span>
                    <span class="metric-value" id="avgErrorRate">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Alerts (7d)</span>
                    <span class="metric-value" id="alertCount">—</span>
                </div>
                <div class="metric">
                    <span class="metric-label">System Uptime</span>
                    <span class="metric-value" id="uptime">—</span>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>🚨 Recent Alerts (Last 7 Days)</h2>
            <div class="alerts-list" id="alertsList">
                <p style="color: #64748b; text-align: center;">Loading alerts...</p>
            </div>
        </div>


        <div class="card" style="margin-bottom:20px;">
            <h2>📋 JVPA Download Funnel <span style="font-size:0.7em;color:#64748b;font-weight:400;">(last 7 days)</span></h2>
            <div id="funnelLoading" style="color:#64748b;font-size:0.85em;padding:16px 0;">Loading funnel data…</div>
            <div id="funnelContent" style="display:none;">
                <div class="funnel-grid">
                    <div class="funnel-stat">
                        <div class="fs-val" id="fClicks7d">—</div>
                        <div class="fs-lbl">JVPA clicks (7d)</div>
                    </div>
                    <div class="funnel-stat">
                        <div class="fs-val" id="fSubmissions7d">—</div>
                        <div class="fs-lbl">Submissions (7d)</div>
                    </div>
                    <div class="funnel-stat">
                        <div class="fs-val funnel-warn" id="fDropOff7d">—</div>
                        <div class="fs-lbl">Drop-off (approx.)</div>
                    </div>
                </div>
                <div style="display:flex;gap:16px;margin-bottom:16px;font-size:0.83em;color:#94a3b8;">
                    <span>All-time clicks: <strong id="fClicksTotal" style="color:#e2e8f0;">—</strong></span>
                    <span>·</span>
                    <span>Unique sessions (7d): <strong id="fUnique7d" style="color:#e2e8f0;">—</strong></span>
                    <span>·</span>
                    <span>Clicks (30d): <strong id="fClicks30d" style="color:#e2e8f0;">—</strong></span>
                </div>
                <div id="funnelTableWrap">
                    <table class="click-table">
                        <thead><tr>
                            <th>#</th><th>Session</th><th>Context</th><th>Referrer Code</th><th>Clicked At</th>
                        </tr></thead>
                        <tbody id="funnelTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div id="funnelNotReady" style="display:none;" class="not-ready-note">
                ⚠️ DB table not ready — run <code>jvpa_clicks_migration.sql</code> in phpMyAdmin first.
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <h2>🎯 Conversion Funnels <span style="font-size:0.7em;color:#64748b;font-weight:400;">(last 7 days)</span></h2>
            <div id="cfLoading" style="color:#64748b;font-size:0.85em;padding:16px 0;">Loading conversion funnel…</div>
            <div id="cfContent" style="display:none;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfLeads" style="font-size:1.6rem;font-weight:700;color:#f0d18a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Emails captured (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfPaid" style="font-size:1.6rem;font-weight:700;color:#52b87a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Paid members (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfLandedKpi" style="font-size:1.6rem;font-weight:700;color:#e2e8f0;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Total sessions (7d)</div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                    <div>
                        <h3 style="font-size:0.78em;color:#f0d18a;font-weight:700;margin:0 0 10px 0;text-transform:uppercase;letter-spacing:0.07em;">🧲 Cold path — /seat/</h3>
                        <div id="cfColdStages" style="display:flex;flex-direction:column;gap:5px;"></div>
                    </div>
                    <div>
                        <h3 style="font-size:0.78em;color:#7dd3fc;font-weight:700;margin:0 0 10px 0;text-transform:uppercase;letter-spacing:0.07em;">🤝 Warm path — invited</h3>
                        <div id="cfWarmStages" style="display:flex;flex-direction:column;gap:5px;"></div>
                    </div>
                </div>
                <h3 style="font-size:0.82em;color:#94a3b8;font-weight:500;margin:0 0 8px 0;text-transform:uppercase;letter-spacing:0.05em;">Pages by source (7d)</h3>
                <div style="overflow-x:auto;margin-bottom:20px;">
                    <table class="click-table" id="cfSourceMatrix">
                        <thead><tr id="cfMatrixHead"></tr></thead>
                        <tbody id="cfMatrixBody"></tbody>
                    </table>
                </div>
                <h3 style="font-size:0.82em;color:#94a3b8;font-weight:500;margin:0 0 8px 0;text-transform:uppercase;letter-spacing:0.05em;">Last 25 visits</h3>
                <table class="click-table">
                    <thead><tr><th>#</th><th>Session</th><th>Page</th><th>Source</th><th>Code</th><th>Device</th><th>Visited</th></tr></thead>
                    <tbody id="cfRecentBody"></tbody>
                </table>
            </div>
            <div id="cfNotReady" style="display:none;" class="not-ready-note">
                ⚠️ DB tables not ready — run <code>page_visits_migration_v1.sql</code> in phpMyAdmin first.
            </div>
        </div>

        <div class="recommendation" id="recommendationBox">
            <h3>🔄 Rebuild Recommendation</h3>
            <p id="recommendationText">Analyzing trends...</p>
        </div>

        <div class="footer">
            <p>Last updated: <span id="updateTime">—</span> | Powered by COGS Monitoring</p>
        </div>
    </div>

    <script>

        async function loadFunnel() {
            try {
                // Admin credentials: reuse whatever auth the monitor page uses
                // The jvpa-funnel endpoint requires admin role — call via API
                const res = await fetch('/_app/api/index.php?route=admin/jvpa-funnel', {
                    credentials: 'include',
                    cache: 'no-store'
                });
                if (!res.ok) {
                    document.getElementById('funnelLoading').textContent = 'Funnel data unavailable (not logged in as admin).';
                    return;
                }
                const json = await res.json();
                const d = json.data || json;

                document.getElementById('funnelLoading').style.display = 'none';

                if (!d.table_ready) {
                    document.getElementById('funnelNotReady').style.display = 'block';
                    return;
                }

                document.getElementById('funnelContent').style.display = 'block';
                document.getElementById('fClicks7d').textContent       = d.clicks_7d ?? '—';
                document.getElementById('fSubmissions7d').textContent   = d.submissions_7d ?? '—';
                document.getElementById('fClicksTotal').textContent     = d.clicks_total ?? '—';
                document.getElementById('fUnique7d').textContent        = d.unique_sessions_7d ?? '—';
                document.getElementById('fClicks30d').textContent       = d.clicks_30d ?? '—';

                const dropOff = d.drop_off_7d ?? 0;
                const dropEl  = document.getElementById('fDropOff7d');
                dropEl.textContent = dropOff;
                dropEl.className   = 'fs-val ' + (dropOff > 0 ? 'funnel-warn' : 'funnel-ok');

                const tbody  = document.getElementById('funnelTableBody');
                const clicks = d.recent_clicks || [];
                if (clicks.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="color:#64748b;text-align:center;">No clicks recorded yet</td></tr>';
                } else {
                    tbody.innerHTML = clicks.map((c, i) => `
                        <tr>
                            <td style="color:#64748b;">${i + 1}</td>
                            <td style="font-family:monospace;">${c.session_token || '—'}</td>
                            <td>${c.page_context || 'join'}</td>
                            <td>${c.referrer_code || '<span style="color:#475569">—</span>'}</td>
                            <td style="color:#94a3b8;">${new Date(c.clicked_at).toLocaleString('en-AU')}</td>
                        </tr>
                    `).join('');
                }
            } catch (e) {
                document.getElementById('funnelLoading').textContent = 'Funnel data unavailable.';
            }
        }

        async function loadConversionFunnel() {
            try {
                const res = await fetch('/_app/api/index.php?route=admin/visit-funnel', {
                    credentials: 'include', cache: 'no-store'
                });
                if (!res.ok) {
                    document.getElementById('cfLoading').textContent = 'Conversion funnel unavailable (not logged in as admin).';
                    return;
                }
                const json = await res.json();
                const d = json.data || json;
                document.getElementById('cfLoading').style.display = 'none';
                if (!d.tables_ready) {
                    document.getElementById('cfNotReady').style.display = 'block';
                    return;
                }
                document.getElementById('cfContent').style.display = 'block';

                document.getElementById('cfLeads').textContent     = d.leads_captures ?? '—';
                document.getElementById('cfPaid').textContent      = (d.warm_funnel||[]).find(s=>s.stage==='Paid')?.sessions ?? '—';
                document.getElementById('cfLandedKpi').textContent = d.visits_total ?? '—';

                function renderFunnel(containerId, stages, colour) {
                    const el   = document.getElementById(containerId);
                    const peak = Math.max(1, ...stages.map(s=>s.sessions||0));
                    el.innerHTML = stages.map((s,i) => {
                        const pct  = Math.max(2, Math.round(((s.sessions||0)/peak)*100));
                        const prev = i>0 ? (stages[i-1].sessions||0) : null;
                        let drop = '';
                        if (prev !== null && prev > 0) {
                            const dp = Math.round(((prev-(s.sessions||0))/prev)*100);
                            drop = dp > 0 ? `<span style="color:#f59e0b;font-size:0.75em;margin-left:6px;">-${dp}%</span>` : '';
                        }
                        return `<div style="display:flex;align-items:center;gap:8px;font-size:0.82em;">
                            <div style="flex:0 0 120px;color:#cbd5e1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${s.stage}${drop}</div>
                            <div style="flex:1;background:#1e293b;border-radius:3px;height:14px;position:relative;overflow:hidden;">
                                <div style="position:absolute;inset:0 auto 0 0;width:${pct}%;background:${colour};border-radius:3px;"></div>
                            </div>
                            <div style="flex:0 0 36px;text-align:right;font-weight:600;color:#e2e8f0;">${s.sessions||0}</div>
                        </div>`;
                    }).join('');
                }

                renderFunnel('cfColdStages', d.cold_funnel||[], 'linear-gradient(90deg,#f0d18a,#c9973d)');
                renderFunnel('cfWarmStages', d.warm_funnel||[], 'linear-gradient(90deg,#38bdf8,#10b981)');

                const spp   = d.source_per_path||[];
                const pages = [...new Set(spp.map(r=>r.path))].sort();
                const srcs  = [...new Set(spp.map(r=>r.src))].sort();
                const icons = {fb:'🟦 fb',yt:'🔴 yt',ig:'📸 ig',tw:'🐦 tw',li:'💼 li',email:'📧 email',sms:'💬 sms',direct:'🔗 direct',qr:'📷 qr',other:'🔘 other'};

                document.getElementById('cfMatrixHead').innerHTML =
                    '<th>Page</th>' + srcs.map(s=>`<th style="text-align:right;">${icons[s]||s}</th>`).join('') + '<th style="text-align:right;">Total</th>';

                const lk = {};
                spp.forEach(r=>{ lk[r.path+'|'+r.src]=r.n; });

                document.getElementById('cfMatrixBody').innerHTML = pages.map(pg => {
                    const tot   = srcs.reduce((a,s)=>a+(parseInt(lk[pg+'|'+s])||0),0);
                    const cells = srcs.map(s=>{
                        const v=parseInt(lk[pg+'|'+s])||0;
                        return `<td style="text-align:right;color:${v>0?'#e2e8f0':'#334155'};">${v||'—'}</td>`;
                    }).join('');
                    return `<tr><td style="color:#94a3b8;">${pg}</td>${cells}<td style="text-align:right;font-weight:600;color:#f0d18a;">${tot}</td></tr>`;
                }).join('');

                const recent = d.recent_visits||[];
                document.getElementById('cfRecentBody').innerHTML = recent.length===0
                    ? '<tr><td colspan="7" style="color:#64748b;text-align:center;">No visits recorded yet</td></tr>'
                    : recent.map((r,i)=>{
                        const si = icons[r.ref_source]||(r.ref_source||'<span style="color:#475569">direct</span>');
                        return `<tr>
                            <td style="color:#64748b;">${i+1}</td>
                            <td style="font-family:monospace;font-size:0.82em;">${r.session_token||'—'}</td>
                            <td>${r.path||'—'}</td>
                            <td>${si}</td>
                            <td>${r.partner_code||'<span style="color:#475569">—</span>'}</td>
                            <td>${+r.is_mobile?'📱':'🖥️'}</td>
                            <td style="color:#94a3b8;font-size:0.82em;">${new Date(r.visited_at).toLocaleString('en-AU')}</td>
                        </tr>`;
                    }).join('');

            } catch(e) {
                document.getElementById('cfLoading').textContent = 'Conversion funnel unavailable.';
            }
        }

        async function loadDashboard() {
            try {
                let alerts = [];
                let currentAlert = null;
                
                // Try to load from local cogs-alert.json (written by cron job)
                try {
                    const response = await fetch('monitor.php?ajax=monitor-data', { 
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    if (response.ok) {
                        const apiData = await response.json();
                        
                        // Store apiData globally for updateMetrics to access
                        window.lastApiData = apiData;
                        
                        if (apiData.current_alert) {
                            const data = apiData.current_alert;
                        // Staleness check: ignore if alert data is older than 30 minutes
                        const alertAge = (new Date() - new Date(data.timestamp)) / 1000 / 60;
                        if (alertAge <= 30) {
                            console.log('Loaded current alert from cogs-alert.json (age: ' + Math.round(alertAge) + ' min)');
                            currentAlert = data;
                            alerts.push({
                                timestamp: data.timestamp,
                                reason_text: data.reason_text,
                                metrics: data.metrics
                            });
                        } else {
                            console.log('cogs-alert.json is stale (' + Math.round(alertAge) + ' min old) — ignoring as current alert');
                            // Still add to alerts list for history, but not as "current"
                            alerts.push({
                                timestamp: data.timestamp,
                                reason_text: data.reason_text,
                                metrics: data.metrics
                            });
                        }
                    }
                        
                        // Process alert history from same response
                        if (apiData.alert_history && apiData.alert_history.alerts) {
                            alerts = alerts.concat(apiData.alert_history.alerts);
                            console.log(`Loaded ${apiData.alert_history.alerts.length} alerts from history`);
                        }
                    }
                } catch (e) {
                    console.log('cogs-alert.json not available (expected if no current alert)');
                }
                
                // Filter to last 7 days
                const now = new Date();
                const sevenDaysAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                const recentAlerts = alerts.filter(a => new Date(a.timestamp) > sevenDaysAgo);
                
                // Deduplicate by reason_text
                const seen = new Set();
                const deduped = [];
                const counts = {};
                
                for (const alert of recentAlerts) {
                    const titleKey = alert.reason_text || 'Unknown Error';
                    if (!seen.has(titleKey)) {
                        seen.add(titleKey);
                        deduped.push(alert);
                        counts[titleKey] = 1;
                    } else {
                        counts[titleKey]++;
                    }
                }
                
                // Sort by timestamp, newest first
                deduped.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
                
                updateStatus(deduped, counts, currentAlert);
                updateMetrics(deduped, currentAlert);
                updateAlerts(deduped, counts);
                updateRecommendation();
                
                document.getElementById('updateTime').textContent = new Date().toLocaleTimeString();
                loadFunnel();
                loadConversionFunnel();
            } catch (error) {
                console.error('Fatal error loading dashboard:', error);
                document.getElementById('statusText').textContent = '❌ Error loading data';
            }
        }
        
        function updateStatus(alerts, counts, currentAlert) {
            const now = new Date();
            const oneHourAgo = new Date(now.getTime() - 60 * 60 * 1000);
            
            const hasRecentAlert = alerts.some(a => new Date(a.timestamp) > oneHourAgo);
            const indicator = document.getElementById('statusIndicator');
            const text = document.getElementById('statusText');
            
            if (currentAlert && hasRecentAlert) {
                indicator.className = 'status-indicator status-alert';
                text.textContent = '🚨 ALERT ACTIVE (Last Hour)';
            } else if (alerts.length > 0) {
                indicator.className = 'status-indicator status-alert';
                text.textContent = `⚠️ ${alerts.length} Issues (Last 7d)`;
            } else {
                indicator.className = 'status-indicator status-healthy';
                text.textContent = '✅ HEALTHY';
            }
            
            document.getElementById('lastCheck').textContent = `Last check: ${now.toLocaleTimeString()} | ${alerts.length} unique issues`;
        }
        
        function updateMetrics(alerts, currentAlert) {
            // Prefer current_metrics from the metrics file (always fresh)
            const apiData = window.lastApiData; // Store apiData globally for access here
            if (apiData && apiData.current_metrics && apiData.current_metrics.metrics) {
                const metrics = apiData.current_metrics.metrics;
                document.getElementById('reqPerSec').textContent = metrics.requests_per_sec ?? '—';
                document.getElementById('errorRate').textContent = (metrics.error_rate_percent || 0) + '%';
                document.getElementById('httpErrors').textContent = metrics.http_errors_in_window || 0;
                document.getElementById('phpErrors').textContent = metrics.php_errors_recent || 0;
            }
            // Fallback to currentAlert metrics if available
            else if (currentAlert && currentAlert.metrics) {
                document.getElementById('reqPerSec').textContent = currentAlert.metrics.requests_per_sec ?? '—';
                document.getElementById('errorRate').textContent = (currentAlert.metrics.error_rate_percent || 0) + '%';
                document.getElementById('httpErrors').textContent = currentAlert.metrics.http_errors_in_window || 0;
                document.getElementById('phpErrors').textContent = currentAlert.metrics.php_errors_recent || 0;
            } else {
                // Count error types from alert titles
                let httpErrors = 0, phpErrors = 0;
                for (const alert of alerts) {
                    const title = (alert.reason_text || '').toLowerCase();
                    if (title.includes('http') || title.includes('502') || title.includes('500')) httpErrors++;
                    if (title.includes('php') || title.includes('fatal') || title.includes('exception')) phpErrors++;
                }
                document.getElementById('reqPerSec').textContent = '—';
                document.getElementById('errorRate').textContent = alerts.length + ' issues (7d)';
                document.getElementById('httpErrors').textContent = httpErrors;
                document.getElementById('phpErrors').textContent = phpErrors;
            }
        }
        
        function updateAlerts(alerts, counts) {
            const list = document.getElementById('alertsList');
            
            if (alerts.length === 0) {
                list.innerHTML = '<p style="color: #64748b; text-align: center;">✓ No errors in the past 7 days</p>';
                return;
            }
            
            list.innerHTML = alerts.slice(0, 15).map(alert => {
                const titleKey = alert.reason_text || 'Unknown Error';
                const repeatCount = counts[titleKey] || 1;
                const daysOld = Math.floor((new Date() - new Date(alert.timestamp)) / (24 * 60 * 60 * 1000));
                
                return `
                    <div class="alert-item">
                        <div class="alert-time">${new Date(alert.timestamp).toLocaleString()} (${daysOld}d ago)${repeatCount > 1 ? ` — Repeat: ${repeatCount} instances` : ''}</div>
                        <div class="alert-title">${titleKey}</div>
                    </div>
                `;
            }).join('');
        }
        
        function updateRecommendation() {
            const box = document.getElementById('recommendationBox');
            box.className = 'recommendation recommendation-no';
            box.innerHTML = `
                <h3>🔄 Rebuild Recommendation</h3>
                <p>System healthy - monitoring active. No rebuild required.</p>
            `;
        }
        
        loadDashboard();
        setInterval(loadDashboard, 300000); // Refresh every 5 minutes
    </script>
</body>
</html>
