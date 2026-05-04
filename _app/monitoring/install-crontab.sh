#!/bin/bash
# =============================================================================
# install-crontab.sh — Install canonical crontab from repo
# =============================================================================
#
# Replaces the live crontab with exactly what is in crontab.txt.
# Run this after any change to crontab.txt has been pushed and deployed.
#
# Usage (from repo root on the server, or via cPanel Terminal):
#   bash _app/monitoring/install-crontab.sh
#
# Or via SSH from local:
#   ssh -i ~/.ssh/serversaurus cogsaust@shorty.serversaurus.com.au \
#     'cd /home4/cogsaust/public_html && bash _app/monitoring/install-crontab.sh'
#
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CRONTAB_FILE="$SCRIPT_DIR/crontab.txt"
LOG_DIR="/home4/cogsaust/logs"

# ── Verify source file exists ─────────────────────────────────────────────────
if [[ ! -f "$CRONTAB_FILE" ]]; then
  echo "ERROR: crontab.txt not found at $CRONTAB_FILE"
  exit 1
fi

# ── Ensure log directory exists ───────────────────────────────────────────────
mkdir -p "$LOG_DIR"
echo "Log directory: $LOG_DIR"

# ── Show what will be installed ───────────────────────────────────────────────
echo ""
echo "Installing crontab from: $CRONTAB_FILE"
echo "----------------------------------------------------------------------"
grep -v '^#' "$CRONTAB_FILE" | grep -v '^$' | while IFS= read -r line; do
  echo "  $line"
done
echo "----------------------------------------------------------------------"
echo ""

# ── Backup current crontab ────────────────────────────────────────────────────
BACKUP_FILE="$LOG_DIR/crontab-backup-$(date +%Y%m%d-%H%M%S).txt"
if crontab -l > "$BACKUP_FILE" 2>/dev/null; then
  echo "Current crontab backed up to: $BACKUP_FILE"
else
  echo "No existing crontab to back up."
  touch "$BACKUP_FILE"
fi

# ── Install ───────────────────────────────────────────────────────────────────
crontab "$CRONTAB_FILE"
echo ""
echo "Crontab installed. Verifying..."
echo ""

# ── Verify ────────────────────────────────────────────────────────────────────
echo "Live crontab now:"
echo "----------------------------------------------------------------------"
crontab -l
echo "----------------------------------------------------------------------"
echo ""
echo "Done. $(grep -v '^#' "$CRONTAB_FILE" | grep -v '^$' | wc -l) job(s) active."
