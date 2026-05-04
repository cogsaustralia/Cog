# COG$ Monitoring

## Files in this directory

| File | Purpose |
|---|---|
| `cogs-monitor.sh` | Server health monitor — Apache error rate, PHP errors, request volume |
| `crontab.txt` | **Canonical crontab — single source of truth for all cron jobs** |
| `install-crontab.sh` | Installs `crontab.txt` to the live server in one command |
| `audit-2026-04-29.md` | Historical audit record |

---

## Crontab management

All cron jobs are defined in `_app/monitoring/crontab.txt` and committed to the
repo. This file is the single source of truth. No cron jobs are added via cPanel
directly — every job is visible here and version-controlled.

### To install or update cron jobs

After any change to `crontab.txt` is pushed and deployed to the server:

```bash
# Via SSH from local machine:
ssh -i ~/.ssh/serversaurus cogsaust@shorty.serversaurus.com.au \
  'cd /home4/cogsaust/public_html && bash _app/monitoring/install-crontab.sh'

# Or via cPanel Terminal directly on the server:
cd /home4/cogsaust/public_html && bash _app/monitoring/install-crontab.sh
```

The script:
- Backs up the current crontab to `/home4/cogsaust/logs/crontab-backup-TIMESTAMP.txt`
- Installs `crontab.txt` as the live crontab (full replace)
- Prints the installed jobs for confirmation

### To add or change a cron job

1. Edit `_app/monitoring/crontab.txt`
2. Commit and push to main
3. Pull on server (GitHub Actions deploys to `public_html/` on push to main)
4. Run `install-crontab.sh`

---

## Active cron jobs

See `crontab.txt` for the full schedule with comments. Summary:

| Schedule | Script | Purpose |
|---|---|---|
| `*/5 * * * *` | `cogs-monitor.sh` | Server health — writes alert JSON for dashboard |
| `*/5 * * * *` | `cron-email.php` | Email queue processor |
| `0 8 * * 5` | `cron-hub-digest.php` | Hub weekly digest (Fri 18:00 AEST) |
| `5 * * * *` | `cron-error-digest.php --mode=hourly` | Hourly new-error alert email |
| `0 21 * * *` | `cron-error-digest.php --mode=daily` | Daily error digest (07:00 AEST) |

---

## Deployment locations

- `cogs-monitor.sh` lives at `/home4/cogsaust/cogs-monitor.sh` (one level above `public_html`).
  It is NOT auto-deployed by GitHub Actions. Update via scp:

  ```bash
  scp -i ~/.ssh/serversaurus _app/monitoring/cogs-monitor.sh \
      cogsaust@shorty.serversaurus.com.au:/home4/cogsaust/
  ssh -i ~/.ssh/serversaurus cogsaust@shorty.serversaurus.com.au \
      'chmod +x /home4/cogsaust/cogs-monitor.sh'
  ```

- All PHP cron scripts live inside `public_html/_app/api/` and are auto-deployed
  by GitHub Actions on push to main.

- `crontab.txt` and `install-crontab.sh` live inside `public_html/_app/monitoring/`
  and are auto-deployed by GitHub Actions on push to main.

---

## Data files (never committed, never in web root)

- `/home4/cogsaust/cogs-alert.json` — current alert state (written by cogs-monitor.sh)
- `/home4/cogsaust/cogs-alert-history.json` — rolling alert history
- `/home4/cogsaust/cogs-metrics.json` — current metrics snapshot
- `/home4/cogsaust/cogs-monitor.log` — monitor run log
- `/home4/cogsaust/cron-monitor.log` — cron stdout/stderr for monitor
- `/home4/cogsaust/logs/email-cron.log` — email queue cron log
- `/home4/cogsaust/logs/hub-digest.log` — hub digest cron log
- `/home4/cogsaust/logs/error-digest.log` — error digest cron log
- `/home4/cogsaust/logs/crontab-backup-*.txt` — crontab backups (written by install script)
