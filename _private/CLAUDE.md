# COGS of Australia Foundation — Claude Code Project Context

*Every instruction in this file serves the Foundation Statement and the
Eight Governing Principles held in _private/FOUNDATION.md.
When in doubt about any action, return to those documents first.*

*Caretaker Trustee: Thomas Boyd Cunliffe*
*Project Coordinator: Claude (chat)*
*Last revised: 4 May 2026 against repo HEAD 3fbb011*

---

## 0. The Foundation

COGS — the Community Owned Governance System — is a permanent, legally
entrenched, community joint venture built to give communities direct,
enforceable governance rights over the natural wealth of this country.
One person, one vote. Not one dollar, one vote.

The full Foundation Statement and Eight Governing Principles are in
_private/FOUNDATION.md. Read them. Every decision in this repo serves them.

---

## 1. Permissions

Claude Code has standing permission to run all bash commands in this
repository without per-command approval — git, grep, sed, cat, find,
head, tail, wc, ls, mv, cp, python3, php, mysql, curl, and any other
shell utility used for development. Do not prompt on individual bash
invocations.

---

## 2. Standing Rules — Non-Negotiable

**Ground truth before any action — MANDATORY SYNC CHECK FIRST.**
Run these in order. Abort the entire session if step 1 fails.

Step 1 — Verify local is in sync with origin/main:
  git fetch origin main --quiet
  LOCAL=$(git rev-parse HEAD)
  REMOTE=$(git rev-parse origin/main)
  if [ "$LOCAL" != "$REMOTE" ]; then
    echo "ABORT: local repo is behind origin/main. Run: git pull --rebase origin main first."
    exit 1
  fi
  echo "SYNC OK: $(git log --oneline -1)"

Step 2 — Confirm state:
  git status && git log --oneline -5

Step 3 — Read ground truth:
  _skills/PROJECT_STATE.md
PROJECT_STATE.md defines the active campaign, active documents, and open dependencies.
Read it at session start and before any edit. Never assume a file matches a
previous session. Read it first.

If local is behind origin/main: stop. Do not proceed. Tell Thomas to pull first.

**Diff review gate.**
Run: git diff --cached <file> | cat
STOP. Do not commit until Thomas types "approved" or "proceed".
One review gate per logical change.
Never combine unrelated changes into one commit.

**Deploy order.**
SQL then PHP then HTML/JS.
Never deploy PHP that depends on a column the SQL migration has not applied.

**Stage explicitly.**
git add <path1> <path2>...
Never git add . or git add -A.
Never add files outside the announced scope.

**Push sequence.**
git commit -m "..." then git pull --rebase origin main then git push origin main.

**Editing tools.**
Use python3 /dev/stdin heredoc with content.replace(old, new, 1) for
multi-line PHP/JS/HTML substitutions.
Never sed -i for multi-line patches.
Single-line sed is acceptable for trivial value replacement.

**File creation.**
Use Python open().write() for all new files. Never heredoc for file creation.

**Lint after edit.**
Always run php -l <file> after a PHP edit.
After every HTML edit run the structure check:
for tag in '</style>' '</head>' '<body' '</body>'; do
  printf "%s=%d  " "$tag" "$(grep -c "$tag" <file>)"
done

**Scope discipline.**
No while-I-am-here cleanups.
No unsolicited refactors.
No comment changes that were not requested.

**Diff display.**
Always pipe through | cat.
Print every line. Never summarise or collapse.

**Live data.**
All members in the database are real Australians. There is no test data.
members.id = 1 is Thomas Cunliffe. id = 2 is Max Graham.
Never truncate, modify, or delete member rows.
Never run destructive queries against the live database.

**No legal document edits.**
Do not modify, draft, or alter the JVPA, Trust Declaration, Sub-Trust Deeds,
or any document under /docs/. These are executed instruments.

---

## 3. Stack

- Backend: PHP 8.4 / MariaDB 10.6 / Apache (cPanel, Serversaurus)
- Frontend: vanilla JS, static HTML, no build step, no bundler
- Repo: cogsaustralia/Cog on GitHub
- GitHub Actions auto-deploys main branch to live server on push
- Live server: /home4/cogsaust/public_html (mapped to repo root)
- Live DB: cogsaust_TRUST
- Local mirror: mysql -u root -pCogs2026!! cogs_mirror

.htaccess blocks direct access to /_app, /_private, /database, /inc.
Only /_app/api/... is publicly reachable inside /_app.

---

## 4. Where Things Live

/
  index.html               homepage
  seat/                    LEAD MAGNET — cold door A
    index.html             email capture — Get a Seat at the Table
    inside/index.html      guide delivery + phone capture
  welcome/                 LEAD MAGNET — cold door B
    index.html             voice submission + join CTA
  intro/                   5-card cold-visitor onboarding
  join/                    member registration form
  thank-you/               post-registration page
  wallets/
    member.html            Independence Vault — personal member dashboard
    business.html          business dashboard
  hubs/                    member hubs
  about/four-spokes/       four-spokes page
  faq/ vision/ terms/ privacy/ skeptic/ gold-cogs/ landholders/
  docs/                    public PDFs — executed legal instruments
  _app/api/                API layer
    index.php              router
    helpers.php            auth, validation, rate limiting
    config/
      bootstrap.php        env, constants, CORS
      database.php         getDB() PDO singleton
    routes/<name>.php      route handlers
    services/<Name>.php    domain services
    integrations/
      mailer.php           SMTP
    migrations/            dated migration files
    stripe-webhook.php     Stripe webhook — standalone, not through router
  admin/
    monitor.php            system + conversion funnel dashboard
    foundation_day.php     Foundation Day readiness + inaugural poll
  sql/                     repo-tracked SQL migrations
  assets/                  CSS, JS, images
  _private/
    CLAUDE.md              this file
    FOUNDATION.md          Foundation Statement + Eight Governing Principles
    CAMPAIGN.md            Stage 4 campaign operations -- active until 14 May 2026
  _skills/               operational skill and reference files -- read by Claude (chat) and CCC
    PROJECT_STATE.md       ground truth: campaign state, active docs, open dependencies
    compliance-list.md     section 2 banned-framing list
    cogs-compliance-check.md  OpenClaw compliance check skill
    cogs-queue-monitor.md     OpenClaw lead captures monitor skill
    cogs-fday-countdown.md    OpenClaw Foundation Day countdown skill
  _archive/              superseded files -- reference only, never operational

---

## 5. Critical Patterns

**Authentication.**
Members authenticate via password + email magic-link 2FA.
OTP/SMS path used only for admin login.
All auth logic is in _app/api/routes/auth.php.

**Database access.**
Always via getDB() from _app/api/config/database.php.
Returns a singleton PDO connection.

**Column-guard pattern.**
Wide-INSERT routes use trust_cols($db, 'table_name') to discover columns
at runtime, then filter to only those columns.
Always verify a column exists before relying on it being persisted.

**Two-table member pattern.**
members is the canonical record.
snft_memberships is the reservation and token record.
Both keyed on member_number (16-digit format 6082XXXXXXXXXXXX).
Written together. Neither is sufficient on its own.

**Lead capture pattern.**
Cold visitors who give their email on /seat/ are written to lead_captures
table via _app/api/routes/lead-capture.php.
Fires a confirmation email on first capture.
ON DUPLICATE KEY UPDATE for safe re-submission.
Phone capture happens on /seat/inside/.
Every lead in lead_captures is a real person who has expressed interest.
Treat with the same care as member rows.

**COG$ escaping.**
In PHP double-quoted strings write COG\$. Common bug site: mailer.php.

**Stripe webhook.**
_app/api/stripe-webhook.php is standalone — not through the router.
Verifies signature via HMAC-SHA256.
Idempotency via stripe_processed_events.
Matches payments to members via client_reference_id.
Sends 200 immediately then continues processing.

**Conversion funnel.**
Anonymous visit logger at _app/api/routes/track.php.
Admin dashboard at admin/monitor.php.

**Mobile number format.**
Stored in 04xx format. Auth normalises +614xx to 04xx.

**Address pipeline.**
members table holds structured address columns including Geoscape G-NAF fields.
GnafAddressAgent.php and ParcelLandholderAgent.php drive verification.

**Known production bug.**
email_templates row id 13 (unitholder certificate) cites ABN 61 734 327 831
(Sub-Trust C) where it should cite 91 341 497 529 (Sub-Trust A).
Flag if encountered. Do not fix without explicit instruction from Thomas.
Decision pending before Foundation Day.

---

## 6. Foundation Entities and ABNs

| Entity         | ABN            | Notes                              |
|----------------|----------------|------------------------------------|
| Sub-Trust A    | 91 341 497 529 | Public-facing ABN, member regs     |
| Sub-Trust B    | none           | Internal transactions only         |
| Sub-Trust C    | 61 734 327 831 | Charity — ACNC registration active |

Default to Sub-Trust A ABN in all member-facing code unless context is
explicitly charity-side (Sub-Trust C grants, donations, ACNC reporting).

---

## 7. Two Distinct UI Surfaces

**Mainspring Management Hub** (/hubs/mainspring/)
JV operational and financial management.
Governance, holdings, community-wide views.

**Independence Vault** (/wallets/member.html)
Personal member dashboard.
Personal holdings, profile, identity, transfers.

Never label vault features as Mainspring or vice versa.

---

## 8. Real-World Resource Doctrine

Constitutional principle, not a marketing line.
In-ground minerals retain real economic value as appreciating stewardship
assets without extraction.
Whenever ASX-listed shares are mentioned in code, copy, or admin tooling,
also reference the RWA value of in-ground resources.
Shares give the community a legal voice.
In-ground minerals carry real measurable value without extraction.
Apply across all tools and communications.

---

## 9. Current Project Stage — Stage 4: Public Launch and Conversion

Today: Monday 4 May 2026.
Foundation Day: Thursday 14 May 2026, 5:00pm AEST.
Days remaining: 10.

The platform is built. The legal structure is executed.
The lead magnet funnel is live. The campaign launched today.

Priority work between now and Foundation Day:
1. Campaign content — one post per day, local-first, personal voice, phone video
2. Lead conversion — every email and phone lead followed up personally by Thomas
3. Personal engagement — every comment, DM, and share replied to personally
4. Foundation Day readiness — poll live, livestream prepared, script selected
   morning of 14 May based on federal budget outcome

Cold-path doors:
/seat/    — email capture, lead magnet, primary campaign destination
/welcome/ — voice submission, emotional and local posts

Never send cold traffic directly to /join/.

Full campaign operations: _private/CAMPAIGN.md

Expansion Day: approximately 12 to 24 months after Foundation Day.
Triggers AFSL and MIS considerations.

---

## 10. Content Standards — Non-Negotiable

Apply to every piece of user-facing text in this repository, every stage.

**K-6 plain English.**
Write at the reading level of a confident twelve-year-old.
Define every technical term in the same sentence it is first used.

**Zero AI tells.**
Banned in all user-facing copy:
em-dashes (--), "I understand" repeated, "for the avoidance of doubt",
passive constructions, not-X-not-Y-not-Z tricolon patterns,
"straightforward", "genuinely", "honestly".

**No financial promotion language.**
Every post, email, and page mentioning shares or dividends closes with:
A community membership invitation. Not financial advice.

**No co-branding.**
No party, candidate, journalist, podcast, or commentator named or implied
as endorsing COGS.

**Public-figure discipline.**
Every claim naming a public figure or quoting a number is tagged to its
public source on the face of the document.

**COGS in public-facing copy.**
The public name is COGS. COG$ is acceptable in internal documents and code.

---

## 11. Local Environment

tunnel  — SSH tunnel via ~/.ssh/serversaurus to shorty.serversaurus.com.au:22,
          forwards 3307 to live MariaDB
livedb  — mysql -h 127.0.0.1 -P 3307 -u cogsaust -p cogsaust_TRUST
mirror  — mysql -u root -pCogs2026!! cogs_mirror

Claude Code cannot SSH directly.
Server-side files outside public_html require manual upload by Thomas.

---

## 12. Handover Patterns

**Project Coordinator (Claude chat) to Claude Code.**
The Coordinator drafts all CCC prompts with SQL, PHP, JS, HTML embedded inline.
The Coordinator does not edit files directly.
Claude Code creates and edits in the repo, runs ground-truth checks,
stages, shows the diff, and stops for Thomas's review.

**Claude Code to Thomas.**
Stop at the diff. Do not commit until Thomas types "approved" or "proceed".

**Thomas to Project Coordinator.**
Thomas directs all work through the Coordinator in Claude chat.
The Coordinator maintains project state, drafts CCC prompts, and keeps
campaign and technical work aligned with the Foundation Statement and
Governing Principles.

**OpenClaw.**
Separate tool for scheduled reminders and Telegram nudges.
Do not invoke from Claude Code. Do not assume it is healthy.

---

## 13. When This File Needs Updating

Update when:
1. Project moves between stages (Stage 4 to Foundation Day to Stage 6)
2. Standing rules change
3. Stack changes
4. Authentication model changes
5. A pattern in section 5 changes materially
6. A Foundation entity or ABN changes
7. The Mainspring/Vault distinction evolves
8. Foundation Day or Expansion Day moves
9. The repo gains a permanent new top-level folder
10. A skill in _skills/ changes its trigger, endpoint, or alert thresholds

Do not update when routes, tables, migrations, members, token classes,
or hubs are added. Discover those dynamically with ls, SHOW TABLES, git log.

A misleading CLAUDE.md is worse than a slightly stale one.
When in doubt, do not update.

---

*End of file. Read once per session. Then act on it.*
*Caretaker Trustee: Thomas Boyd Cunliffe*
*Project Coordinator: Claude*
*Drake Village, Wahlubal Country, Bundjalung Nation*
