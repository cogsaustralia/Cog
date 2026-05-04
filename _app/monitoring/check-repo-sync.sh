#!/bin/bash
# =============================================================================
# check-repo-sync.sh — Keep local repo in sync with origin/main
# =============================================================================
#
# Runs every 5 minutes via LaunchAgent.
# Fetches origin silently, compares local HEAD to origin/main.
#
# If behind AND working tree is clean:
#   Auto-pulls (git pull --rebase origin main), fires success notification.
#
# If behind AND working tree is dirty (uncommitted changes):
#   Notifies only — never auto-pulls over uncommitted work.
#
# If in sync: completely silent.
#
# Install: see _app/monitoring/INSTALL-REPO-SYNC-WATCH.md
# LaunchAgent plist: org.cogsaustralia.repo-sync-check.plist
# Log: ~/Library/Logs/cogs-repo-sync.log
#
# =============================================================================

set -uo pipefail

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

# Fetch origin silently — fail open on network issues
git fetch origin main --quiet 2>/dev/null || {
    echo "[$TIMESTAMP] WARN: git fetch failed (network issue?)" >> "$LOG_FILE"
    exit 0
}

LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/main 2>/dev/null)

# In sync — silent
if [[ "$LOCAL" = "$REMOTE" ]]; then
    exit 0
fi

# Behind — count commits and identify changed files
BEHIND=$(git rev-list --count HEAD..origin/main 2>/dev/null || echo "?")
CHANGED_FILES=$(git diff --name-only HEAD origin/main 2>/dev/null | head -5 | tr '\n' ' ')

# Check if working tree is clean (no uncommitted changes)
DIRTY=$(git status --porcelain 2>/dev/null)

if [[ -z "$DIRTY" ]]; then
    # Clean — auto-pull
    if git pull --rebase origin main --quiet 2>/dev/null; then
        NEW=$(git rev-parse HEAD 2>/dev/null)
        echo "[$TIMESTAMP] AUTO-PULLED: $BEHIND commit(s). ${LOCAL:0:7} -> ${NEW:0:7}. Files: $CHANGED_FILES" >> "$LOG_FILE"
        osascript -e "display notification \"Pulled $BEHIND commit(s) automatically. Files: $CHANGED_FILES\" with title \"COGS Repo Synced\" subtitle \"Local is now up to date\" sound name \"Glass\"" 2>/dev/null || true
        echo "[$TIMESTAMP] AUTO-PULL SUCCESS: now at $(git log --oneline -1)"
    else
        # Pull failed even with clean tree — notify to handle manually
        echo "[$TIMESTAMP] AUTO-PULL FAILED: behind by $BEHIND commit(s). Manual pull required." >> "$LOG_FILE"
        osascript -e "display notification \"Auto-pull failed. Run: git pull --rebase origin main\" with title \"COGS Repo Sync Failed\" subtitle \"Manual pull required\" sound name \"Basso\"" 2>/dev/null || true
    fi
else
    # Dirty working tree — notify only, never auto-pull over uncommitted work
    DIRTY_FILES=$(git status --porcelain 2>/dev/null | head -3 | awk '{print $2}' | tr '\n' ' ')
    echo "[$TIMESTAMP] DRIFT+DIRTY: behind by $BEHIND commit(s), dirty tree. Uncommitted: $DIRTY_FILES Remote files: $CHANGED_FILES" >> "$LOG_FILE"
    osascript -e "display notification \"$BEHIND commit(s) behind. Uncommitted changes prevent auto-pull. Commit or stash, then pull.\" with title \"COGS Repo Out of Sync\" subtitle \"Manual action required\" sound name \"Basso\"" 2>/dev/null || true
    echo "[$TIMESTAMP] NOTIFIED: dirty tree, cannot auto-pull"
fi
