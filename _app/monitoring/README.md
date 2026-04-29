# Monitoring Files

## Active deployment locations

- **monitor.php** lives at `admin/monitor.php` in this repo. The deploy
  workflow does `git reset --hard` on the entire `public_html/` tree on
  the server, so `admin/monitor.php` is what gets served at
  `cogsaustralia.org/admin/monitor.php`.

- **cogs-monitor.sh** lives at `/home4/cogsaust/cogs-monitor.sh` on the
  production server (one level above `public_html/`). The deploy workflow
  does NOT touch it. Updates require manual scp:

      scp -i ~/.ssh/serversaurus _app/monitoring/cogs-monitor.sh \
          cogsaust@shorty.serversaurus.com.au:/home4/cogsaust/
      ssh -i ~/.ssh/serversaurus cogsaust@shorty.serversaurus.com.au \
          'chmod +x /home4/cogsaust/cogs-monitor.sh'

## Data files (never committed, never in web root)

- `/home4/cogsaust/cogs-alert.json` — written when alert thresholds tripped
- `/home4/cogsaust/cogs-alert-history.json` — rolling alert history
- `/home4/cogsaust/cogs-metrics.json` — current metrics, written every cron run
- `/home4/cogsaust/cogs-monitor.log` — text log, every cron run
- `/home4/cogsaust/cron-monitor.log` — cron stderr/stdout

The dashboard reads these via the AJAX endpoint
`admin/monitor.php?ajax=monitor-data` which requires admin auth.

## Cron

`*/5 * * * * /home4/cogsaust/cogs-monitor.sh >> /home4/cogsaust/cron-monitor.log 2>&1`
