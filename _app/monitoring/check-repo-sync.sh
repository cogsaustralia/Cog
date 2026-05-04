#!/bin/bash
# =============================================================================
# check-repo-sync.sh — Alert Thomas when local repo is behind origin/main
# =============================================================================
#
# Runs every 5 minutes via LaunchAgent.
# Fetches origin silently, compares local HEAD to origin/main.
# If behind: fires a macOS notification + writes to drift log.
# If in sync: silent.
#
# Install: see _app/monitoring/README.md
# LaunchAgent plist: org.cogsaustralia.repo-sync-check.plist
# Log: ~/Library/Logs/cogs-repo-sync.log
#
# =============================================================================

set -euo pipefail

REPO_DIR="$HOME/cogs-repo-local"
LOG_FILE="$HOME/Library/Logs/cogs-repo-sync.log"
TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Verify repo exists
if [[ ! -d "$REPO_DIR/.git" ]]; then
    echo "[$TIMESTAMP] ERROR: $REPO_DIR is not a git repo" >> "$LOG_FILE"
    exit 1
fi

cd "$REPO_DIR"

# Fetch origin silently — no output, no interaction
git fetch origin main --quiet 2>/dev/null || {
    echo "[$TIMESTAMP] WARN: git fetch failed (network issue?)" >> "$LOG_FILE"
    exit 0  # fail open — don't spam notifications on network drops
}

LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/main 2>/dev/null)

if [[ "$LOCAL" = "$REMOTE" ]]; then
    # In sync — silent. Uncomment next line for verbose logging if needed:
    # echo "[$TIMESTAMP] OK: in sync at ${LOCAL:0:7}" >> "$LOG_FILE"
    exit 0
fi

# Count how many commits behind
BEHIND=$(git rev-list --count HEAD..origin/main 2>/dev/null || echo "?")

# Identify what changed
CHANGED_FILES=$(git diff --name-only HEAD origin/main 2>/dev/null | head -5 | tr '\n' ' ')

MSG="Local repo is $BEHIND commit(s) behind origin/main. Run: cd ~/cogs-repo-local && git pull --rebase origin main"

echo "[$TIMESTAMP] DRIFT: behind by $BEHIND commit(s). Local=${LOCAL:0:7} Remote=${REMOTE:0:7} Files: $CHANGED_FILES" >> "$LOG_FILE"

# macOS notification
osascript -e "display notification \"$BEHIND commit(s) behind. Pull before working. Files: $CHANGED_FILES\" with title \"COGS Repo Out of Sync\" subtitle \"Run: git pull --rebase origin main\" sound name \"Basso\"" 2>/dev/null || true

# Also echo to stdout (captured by launchd if StandardOutPath set)
echo "[$TIMESTAMP] NOTIFIED: $MSG"
