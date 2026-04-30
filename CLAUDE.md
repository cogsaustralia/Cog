# COG$ of Australia Foundation — Claude Code Project Context

This file is read by Claude Code at the start of every session. It carries standing rules and the verified ground-truth pointers Claude Code needs to work safely in this repository. It is intentionally short. It does **not** mirror the full schema or document the entire codebase — both are subject to change. When you need detail, query the source: read the file, query the schema, run `git log` on the path. This file's job is to make sure you know **how** to do that without breaking anything.

Last revised by Claude on 30 April 2026 against repo HEAD `c908173` and SQL dump `cogsaust_TRUST__65_.sql`. See "When this file needs updating" at the end.

---

## 1. Permissions

Claude Code has standing permission to run all bash commands in this repository without per-command approval — git, grep, sed, cat, find, head, tail, wc, ls, mv, cp, python3, php, mysql, curl, and any other shell utility used for development. Do not prompt on individual bash invocations. Pressing approval thirty times per session is not the workflow.

---

## 2. Standing rules — non-negotiable

**Ground truth before any action.** Run `git status && git log --oneline -5` at session start and before any edit. Never assume a file matches a previous session. Read it first.

**Diff review gate.** Run `git diff --cached <file> | cat` and STOP. Do not commit until Thomas types **"approved"** or **"proceed"**. One review gate per logical change. Never combine unrelated changes into one commit.

**Deploy order.** SQL → PHP → HTML/JS. Never deploy PHP that depends on a column the SQL migration has not yet applied. Same rule reversed for rollbacks.

**Stage explicitly.** `git add <path1> <path2>...` Never `git add .` or `git add -A`. Never add files outside the announced scope.

**Push sequence.** `git commit -m "..."` then `git pull --rebase origin main` then `git push origin main`. The rebase before push catches concurrent commits without a merge bubble.

**Editing tools.** Use `python3 /dev/stdin` heredoc with `content.replace(old, new, 1)` for multi-line PHP/JS/HTML substitutions. Never `sed -i` for multi-line patches — it mishandles PHP `$` variables, JS template literals, and HTML entities. Single-line `sed` is acceptable for trivial value replacement.

**File creation.** Use `cat > <path> <<'EOF' ... EOF` heredoc for new files. The `Write` tool occasionally produces content variations — heredoc is verbatim. For files that already exist, use `python3` str_replace, never `Write`.

**Lint after edit.** Always run `php -l <file>` after a PHP edit. After every HTML edit run the structure check below. Each tag count should be 1 unless documented otherwise (e.g. `thank-you/index.html` has 2 `</style>` blocks).

```bash
for tag in '</style>' '</head>' '<body' '</body>'; do
  printf "%s=%d  " "$tag" "$(grep -c "$tag" <file>)"
done
```

**Scope discipline.** No "while I'm here" cleanups. No unsolicited refactors. No comment changes that weren't requested.

**Diff display.** When showing a `git diff`, always pipe through `| cat`. Print every line. Never summarise, abbreviate, or collapse with `…`. Reviewers need literal text.

**Live data.** All members in the database are real Australians. There are no test members. There is no test data. `members.id = 1` is Thomas Cunliffe, `id = 2` is Max Graham, and so on — these are founding partners who have accepted the JVPA, paid, and are part of the live cohort. Never truncate, modify, or delete member rows. Never run destructive queries against the live database.

**No legal document edits.** Do not modify, draft, or alter the JVPA, Trust Declaration, Sub-Trust Deeds, or any document under `/docs/`. These are executed instruments. Editorial work on legal text happens in Claude (chat) sessions, not Claude Code.

---

## 3. Stack

- Backend: PHP 8.4 / MariaDB 10.6 / Apache (cPanel shared hosting on Serversaurus)
- Frontend: vanilla JS, static HTML, no build step, no bundler
- Repo: `cogsaustralia/Cog` on GitHub. GitHub Actions auto-deploys `main` branch to live server on push
- Live server: `/home4/cogsaust/public_html` (mapped to repo root)
- Live DB: `cogsaust_TRUST` — Thomas accesses via `tunnel` then `livedb` aliases (`~/.bash_profile`)
- Local mirror DB: `mysql -u root -pCogs2026!! cogs_mirror`
- Read-only auditor account: `cogsaust_auditor` (SELECT, SHOW VIEW only)

`.htaccess` blocks direct access to `/_app`, `/_private`, `/database`, `/inc`. Only `/_app/api/...` is publicly reachable inside `/_app`. Everything else falls through to `index.html` (SPA-style fallback).

---

## 4. Where things live

```
/                          public root (mirrors public_html)
  index.html               homepage — first-visit redirects to /intro/
  intro/                   5-card cold-visitor onboarding
  join/                    member registration form
  thank-you/               post-registration page (Stripe primary, PayID secondary)
  wallets/
    member.html            personal Independence Vault Wallet
    business.html          business Independence Vault Wallet
  hubs/                    member hubs (12 total — see project knowledge for list)
  partners/                partner-facing landing
  faq/, vision/, terms/, privacy/, skeptic/, gold-cogs/, landholders/   static content
  docs/                    public PDFs (JVPA, Trust Declaration, Sub-Trust Deeds, B&W Paper)
  _app/api/                API layer (blocked except /api/)
    index.php              router — dispatches by ?route= param
    helpers.php            getAuthPrincipal, requireAuth, trust_cols, OTP, rate limiting, validation
    config/
      bootstrap.php        env loading, constants, CORS
      database.php         getDB() — PDO connection
    routes/<name>.php      route handlers
    services/<Name>.php    domain services
    integrations/
      mailer.php           SMTP — escape COG\$ in double-quoted strings
    migrations/            dated migration files (informational — applied via phpMyAdmin)
    stripe-webhook.php     Stripe webhook (standalone, NOT through router)
  admin/                   admin panel
    includes/              shared admin includes
    monitor.php            system + conversion funnel dashboard
    foundation_day.php     Foundation Day readiness + inaugural poll
  sql/                     repo-tracked SQL migrations applied via phpMyAdmin
  assets/                  CSS, JS, images
  CLAUDE.md                this file
```

To find a route handler: `grep -l "route.*<name>" _app/api/routes/*.php`. To find a service consumer: `grep -rln "ServiceName::method" _app/`. Do not hardcode counts of routes or services in this file — they change.

---

## 5. Critical patterns

**Authentication.** Members authenticate via password + **email magic-link** 2FA. The 6-digit OTP/SMS path is now used only for admin login, not member login. The file `_app/api/auth.php` does not exist — it is a tombstone. All auth logic is in `_app/api/routes/auth.php`. Magic-link tokens flow through `one_time_tokens` and `member_otp_challenges`.

**Database access.** Always via `getDB()` from `_app/api/config/database.php`. Returns a singleton PDO connection.

**Column-guard pattern.** Registration and similar wide-INSERT routes use `trust_cols($db, 'table_name')` to discover which columns exist at runtime, then filter the data array to only those columns. Missing columns are silently skipped — **always verify the column exists before relying on it being persisted**. This is how the snft-reserve and bnft-reserve routes survive schema drift.

**Two-table member pattern.** `members` is the canonical record (linked to auth, holds structured KYC fields). `snft_memberships` is the reservation/token record with class-specific token counts and reservation values. Both keyed on `member_number` (16-digit format `6082XXXXXXXXXXXX`). They are written together; neither is sufficient on its own.

**`COG$` escaping.** In PHP double-quoted strings, `$` introduces a variable. The literal text `COG$` must be written as `COG\$` in double-quoted strings, or use single quotes. Common bug site: `mailer.php`. Email subject lines have been broken by this in the past.

**Stripe webhook.** `_app/api/stripe-webhook.php` is standalone — it does not go through the router. Verifies signature via manual HMAC-SHA256 (no SDK). Idempotency via `stripe_processed_events`. Matches payments to members via `client_reference_id` (set to `member_number` from the thank-you page). Sends 200 to Stripe immediately via `stripe_finish_response()` then continues processing — Stripe's retry decision is based on status code, not body.

**Stage 1 conversion funnel.** Anonymous visit + funnel event logger at `_app/api/routes/track.php`. Pixel beacon at `?route=track/visit`, event POST at `?route=track/event`. Privacy posture: SHA-256 hashed IP, 120-char truncated UA, anonymous session cookie (90d). Mirrors the older `jvpa_pdf_clicks` pattern. Admin dashboard at `admin/monitor.php` reads via `?route=admin/visit-funnel`.

**Mobile number format.** Stored in `04xx` format throughout. Auth path normalises `+614xx` to `04xx` automatically. `members.phone` and `snft_memberships.mobile` may carry the same value under different column names in different contexts.

**Address pipeline.** Live, not future. `members` table holds structured address columns: `street_address`, `suburb`, `state_code`, `postcode`, plus Geoscape G-NAF fields `gnaf_pid`, `zone_id`, `address_lat`, `address_lng`, `address_evidence_hash`, `address_verified_at`. `_app/api/services/GnafAddressAgent.php` and `ParcelLandholderAgent.php` drive verification.

---

## 6. Foundation entities and ABNs

The Foundation comprises the CJVM Hybrid Trust with three Sub-Trusts. Each ABN belongs to a specific entity — do not interchange them.

| Entity | ABN | Notes |
|---|---|---|
| **Sub-Trust A** (trading trust) | **91 341 497 529** | Public-facing Foundation ABN for member registrations and partner contributions |
| **Sub-Trust B** (internal) | none | No ABN — internal transactions only, does not trade as a business |
| **Sub-Trust C** (charity) | **61 734 327 831** | Exclusive to Sub-Trust C, ACNC charity registration in progress, DGR endorsement contemplated under ITAA 1997 Div.30 |

When writing or updating any code, email template, or document that surfaces an ABN to a Member or to the public, **default to Sub-Trust A's ABN (`91 341 497 529`)** unless the context is explicitly charity-side (Sub-Trust C grants, donations, or ACNC reporting).

There is a known bug in `email_templates.unitholder_certificate` (row id 13) which cites `61 734 327 831` where it should cite `91 341 497 529`. Flag if encountered; do not fix without explicit instruction.

---

## 7. Two distinct UI surfaces

**Mainspring Management Hub** (`/hubs/mainspring/`) — JV operational and financial management. Governance, holdings, community-wide views.

**Independence Financial Vault** (`/wallets/member.html`) — personal digital financial management. Personal holdings, profile, identity, P2P transfers.

The two are not interchangeable. Never label vault features as Mainspring or vice versa. Navigation copy must reflect the distinction.

---

## 8. Real-world resource doctrine

A constitutional principle of the Foundation, not a marketing line: **in-ground minerals retain real economic value as appreciating stewardship assets without extraction.** Whenever ASX-listed shares are mentioned in code comments, copy, or admin tooling, also reference the RWA value of in-ground resources. Correct framing: shares give the community a legal voice; in-ground minerals carry real measurable value recognisable WITHOUT extraction — before extraction becomes default, potentially in lieu of extraction. Apply across all tools and comms.

---

## 9. Foundation Day timing

- **Foundation Day:** Thursday 14 May 2026, 17:00 AEST. First community vote.
- **Expansion Day:** approximately 12–24 months after Foundation Day. Triggers AFSL/MIS considerations.
- **Today's date** is the source of truth for D-counts. Campaign launched D-15 (29 Apr 2026); D-0 is Foundation Day itself.
- The Foundation Day inaugural poll is created via `admin/foundation_day.php`. Do not create polls or change Foundation Day flags from any other entry point.

---

## 10. Local environment shortcuts

Thomas runs:

- `tunnel` — opens SSH tunnel `~/.ssh/serversaurus` → `shorty.serversaurus.com.au:22`, forwards `3307 → live MariaDB`
- `livedb` — `mysql -h 127.0.0.1 -P 3307 -u cogsaust -p cogsaust_TRUST` (Thomas pastes results back into chat)
- Local mirror: `mysql -u root -pCogs2026!! cogs_mirror`

Claude Code cannot SSH directly. Server-side files outside the public_html repo (e.g. `cogs-monitor.sh`, `monitor.html`, alert configs at `/home4/cogsaust/`) require Thomas to upload manually.

---

## 11. Handover patterns

- **Claude (chat) → Claude Code.** Claude (chat) writes the comprehensive prompt with all SQL/PHP/JS embedded inline as text. Claude Code creates and edits in the repo, runs ground-truth checks, stages, shows the diff, and stops for review. No zip handovers.
- **Claude Code → Thomas.** Stop at the diff. Do not commit until approved.
- **OpenClaw** is a separate operational tool for scheduled reminders and Telegram nudges. Do not invoke OpenClaw from Claude Code or vice versa. Do not assume OpenClaw is healthy — it is being stabilised separately.

---

## 12. When this file needs updating

This file should be revised when any of the following changes:

1. **Standing rules change.** New deploy-order constraints, new editing tooling, new prohibited patterns.
2. **Stack change.** PHP version, MariaDB version, hosting provider, deploy mechanism.
3. **Authentication model change.** Magic-link replaced or supplemented; admin 2FA replaced; session model changed.
4. **A pattern in §5 changes.** New widely-used pattern emerges (a third member table, a new column-guard pattern, a new escape requirement); old pattern is deprecated.
5. **A Foundation entity or ABN changes.** Sub-Trust C charity registration is approved (ABN may change or new TFN attaches); Expansion Day occurs; new Sub-Trust is added.
6. **The Mainspring/Vault distinction in §7 evolves.** New surfaces emerge.
7. **Foundation Day or Expansion Day moves.**
8. **The repo gains a permanent new top-level folder** that materially changes navigation (e.g. `/sovereign-node/`, `/landholder-portal/`).

This file does **not** need to be revised when:

- Routes are added to `_app/api/routes/`. Use `ls _app/api/routes/` to enumerate.
- Tables are added to the schema. Use `SHOW TABLES` against `cogsaust_TRUST` or read the latest dump.
- Migrations are added. They live in `_app/api/migrations/` and `sql/`.
- Members are added. Member counts are dynamic and are not recorded here.
- Token classes are added or repriced. Read `token_classes` table.
- Hubs are added. Read `ls hubs/`.
- Stage 2 conversion improvements ship. The pattern in §5 covers them.

A revision should always preserve §1, §2, §6, §7, §8, §11, and §12 unless explicitly instructed otherwise. These are the constitutional sections — they protect the safety properties of the project. Sections 3–5, 9, 10 are operational and may change as the project evolves.

When in doubt about whether a change requires a CLAUDE.md update, default to **not** updating. A misleading CLAUDE.md is worse than a slightly stale one. Stale parts surface during ground-truth checks; misleading parts cause wrong decisions.

---

*End of file. Read this once per session, then act on it.*
