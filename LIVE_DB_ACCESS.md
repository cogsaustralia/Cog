# COG$ Live Database Access — SSH Tunnel Setup

## Overview
Thomas has a live SSH tunnel from his iMac to the Serversaurus MariaDB server.
Claude asks Thomas to run SQL queries and paste the results back.
Always verify live data before writing PHP or deploying SQL changes.

---

## Quick Start (every session)

Open a terminal on Thomas's iMac and run:

```bash
tunnel    # starts the SSH tunnel (kills any existing one first)
livedb    # connects to live MariaDB
```

Both are aliases defined in `~/.bash_profile`.

---

## Alias Definitions

```bash
alias tunnel="lsof -ti:3307 | xargs kill -9 2>/dev/null; ssh -i ~/.ssh/serversaurus -L 3307:localhost:3306 cogsaust@shorty.serversaurus.com.au -N &"
alias livedb="mysql -h 127.0.0.1 -P 3307 -u cogsaust -p cogsaust_TRUST"
```

---

## Connection Details

| Item | Value |
|------|-------|
| SSH key | `~/.ssh/serversaurus` |
| SSH host | `shorty.serversaurus.com.au` port `22` |
| Local tunnel port | `3307` |
| DB host (via tunnel) | `127.0.0.1:3307` |
| DB name | `cogsaust_TRUST` |
| DB user | `cogsaust` |
| DB password | Thomas's cPanel MySQL password |

---

## Manual Setup (if aliases not loaded)

```bash
# Kill any existing tunnel on port 3307
lsof -ti:3307 | xargs kill -9 2>/dev/null

# Start tunnel
ssh -i ~/.ssh/serversaurus -L 3307:localhost:3306 cogsaust@shorty.serversaurus.com.au -N &

# Connect to live DB
mysql -h 127.0.0.1 -P 3307 -u cogsaust -p cogsaust_TRUST
```

---

## Local Mirror Database

A local mirror is also available on Thomas's iMac for safe testing.

```bash
mysql -u root -p cogs_mirror
# password: Cogs2026!!
```

**Refresh mirror from latest dump:**
```bash
mysql -u root -p -e "DROP DATABASE cogs_mirror; CREATE DATABASE cogs_mirror CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sed 's/DEFINER=`[^`]*`@`[^`]*`//g' ~/Downloads/cogsaust_TRUST__XX_.sql | mysql -u root -p cogs_mirror
```
Replace `XX` with the latest dump version number.

---

## Workflow for Backend Changes

1. **Thomas starts tunnel** — `tunnel` then `livedb`
2. **Claude writes a verification query** — Thomas runs it, pastes result
3. **Claude confirms data state** — then writes the SQL patch or PHP change
4. **Deploy SQL first** — Thomas runs in phpMyAdmin or via tunnel
5. **Deploy PHP** — push to GitHub, Actions auto-deploys
6. **Verify** — Claude writes a follow-up query, Thomas confirms

---

## Keeping the Tunnel Alive

The tunnel runs in the background until:
- The Mac is restarted
- The terminal window is closed
- It is manually killed: `lsof -ti:3307 | xargs kill -9`

If the tunnel drops mid-session just run `tunnel` again.
