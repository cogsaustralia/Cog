# Repo Sync Watch — Install Guide

Prevents CCC or Thomas working on a stale local repo.
Fires a macOS notification within 5 minutes of any push to origin/main
that has not been pulled to the local iMac clone.

## Files

- `check-repo-sync.sh` — shell script that does the fetch/compare/notify
- `org.cogsaustralia.repo-sync-check.plist` — LaunchAgent that runs it every 5 min
- This file — install instructions

## Install (run once on the iMac)

**1. Copy the script to the repo (already done if you can read this).**

**2. Make it executable:**
```bash
chmod +x ~/cogs-repo-local/_app/monitoring/check-repo-sync.sh
```

**3. Copy the plist to LaunchAgents:**
```bash
cp ~/cogs-repo-local/_app/monitoring/org.cogsaustralia.repo-sync-check.plist \
   ~/Library/LaunchAgents/
```

**4. Load the LaunchAgent:**
```bash
launchctl load ~/Library/LaunchAgents/org.cogsaustralia.repo-sync-check.plist
```

**5. Test it immediately:**
```bash
bash ~/cogs-repo-local/_app/monitoring/check-repo-sync.sh
```
If in sync: no output (silent is correct).
If behind: you will see a macOS notification banner and a log entry.

**6. Verify it is running:**
```bash
launchctl list | grep cogsaustralia
```
You should see `org.cogsaustralia.repo-sync-check` listed.

## Log files

- `~/Library/Logs/cogs-repo-sync.log` — drift events and notifications
- `~/Library/Logs/cogs-repo-sync-error.log` — script errors

## What the notification looks like

Title: **COGS Repo Out of Sync**
Subtitle: Run: git pull --rebase origin main
Body: N commit(s) behind. Pull before working. Files: [changed files]
Sound: Basso (distinctive alert tone)

## To unload (if needed)

```bash
launchctl unload ~/Library/LaunchAgents/org.cogsaustralia.repo-sync-check.plist
```

## To update after repo changes

The script lives in the repo. After any `git pull`, the LaunchAgent
automatically uses the updated version — no reload needed.

## After installing

Every CCC session prompt now includes a mandatory sync check at Step 1.
CCC will abort the session if local is behind origin/main.
The LaunchAgent catches drift within 5 minutes.
Together these make it impossible to work on stale code without a visible warning.
