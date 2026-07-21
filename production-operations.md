# SureSign Production Operations Handbook

Living operational reference: how to deploy, restart, roll back, back up, and
recover SureSign in production. For *why* the deployment is shaped the way it
is (Compose vs. Stack mode, Redis decisions, readiness checks, remaining
Stack-mode blockers), see [`production-readiness-audit.md`](production-readiness-audit.md) —
this document does not repeat that reasoning, only points to it.

Production today: a single Hetzner CX33 VPS, managed via Dokploy, running
`docker-compose.prod.yml` in Dokploy's Compose mode (not Stack — see the
audit). Containers: `suresign_backend`, `suresign_frontend`, `suresign_mysql`,
`suresign_redis`, `suresign_queue`, `suresign_scheduler`, `suresign_nginx`,
plus independent `suresign_docs`/`suresign_marketing`. Traffic path:
Cloudflare → Dokploy's Traefik → `nginx` → `frontend`/`backend`.

---

## Day 0 — Disaster Recovery

*The VPS is gone. Burned down, deleted, whatever — you're starting from a new
Hetzner box and need SureSign live again.* This is the sequence, and the
honest list of what it depends on.

### What you need before you start

1. **This git repository** — the code, `docker-compose.prod.yml`, and every
   Dockerfile are here. Nothing to reconstruct.
2. **The production `.env` values** — `APP_KEY`, `DB_PASSWORD`,
   `MYSQL_ROOT_PASSWORD`, `AWS_*` (if used), `ANTHROPIC_API_KEY` (if AI is
   enabled), Brevo API key, any org-specific values entered through the admin
   settings UI rather than env (branding is in the database, so it comes back
   with the DB restore below). **These live in Dokploy's environment panel,
   not in this repo — if Dokploy itself is also gone, this is the one thing
   that cannot be reconstructed from a backup and must be recovered from
   wherever these were originally recorded (password manager, secrets
   store).** This is the single most likely point of failure in a real Day 0
   — flagged here deliberately rather than assumed away.
3. **The most recent database backup** (`suresign-db-*.sql.gz`) and **storage
   backup** (`suresign-storage-*.tar.gz`) from `ops/backup.sh` — see
   [Backup & Restore](#backup--restore) below. If these only ever lived on
   the VPS that just burned down, there is no Day 0 recovery — this is why
   the backup section insists on an off-host copy.

### Sequence

1. **Provision the new server.** Hetzner Cloud → new CX-series VPS (CX33 or
   larger), same region. Install Docker + Docker Compose. Install Dokploy
   (`curl -sSL https://dokploy.com/install.sh | sh`, per Dokploy's own docs —
   this repo doesn't script that step).
2. **Point DNS at the new server's IP** (Cloudflare) — `app.suresigncontracts.app`,
   `docs.suresigncontracts.app`, and the marketing domain once that cutover
   has happened. Expect propagation delay; this is the step with the least
   control over timing.
3. **Clone this repository** onto the new server (or configure Dokploy to
   pull it, matching how the current server is set up — a Git-connected
   Compose service, branch `main`, path `./docker-compose.prod.yml`, per the
   General tab settings visible on the existing Dokploy service).
4. **Recreate the Dokploy Compose service** pointing at this repo, and enter
   every environment variable from step 2 above into Dokploy's environment
   panel. Do not deploy yet.
5. **Deploy once** so the containers (and the empty `mysql_data`/
   `redis_data`/`backend_storage` volumes) exist. `backend` will come up
   without a working schema — that's expected, `/readyz` will report
   `503` until the next step.
6. **Restore data** using `ops/restore.sh <db-dump> <storage-tar>` — see
   [Backup & Restore](#backup--restore). This restores the database and the
   uploaded/generated files volume.
7. **Run migrations**: `docker exec suresign_backend php artisan migrate --force`
   — brings the restored schema up to whatever this version of the repo
   expects (only matters if the backup predates the code you just deployed).
8. **Verify**: `curl https://app.suresigncontracts.app/api/up` and `/readyz`
   both return 200; log in as an existing user; open a project with known
   documents and confirm a preview/download works (proves the storage
   restore actually worked, not just the DB restore).
9. **Re-point Cloudflare/Traefik SSL** if needed — Dokploy provisions
   Let's Encrypt certificates automatically once DNS resolves to the new
   server; this is not something this repo configures, and there is nothing
   to restore here (a fresh certificate is issued, not recovered).

### What's genuinely untested about this sequence

This sequence has been reasoned through against the actual repo and verified
piece-by-piece (the backup/restore scripts were run against the real local
containers — see [Backup & Restore](#backup--restore)), but **the full
sequence end-to-end, on an actual second server, has not been run**. The
known gap is step 4 (recreating the Dokploy service from scratch) — this
repo can't verify Dokploy's own UI behaves as described without access to
Dokploy itself. Flagged as pending verification, not silently assumed correct.

---

## Day 2 — Operations

### Deploy (routine, no schema change)

Via Dokploy's dashboard: **Deploy** button on the `production-suresign`
service. This runs `docker compose up -d --build` against
`docker-compose.prod.yml` — in the current Compose mode, this recreates the
whole stack (a downtime window; see the audit's Stack-mode section for why).

### Deploy (with a migration)

1. Deploy the code as above.
2. Once `backend` is healthy again: `docker exec suresign_backend php artisan migrate --force`.
3. Confirm via `/readyz` and a quick manual check of the affected feature.

Never rely on the old automatic-migration-on-boot behaviour — it was
deliberately removed (see the audit, Section 3.1) precisely because it's
unsafe under any future multi-replica setup. Migrations are now always this
explicit step.

### Rollback

Dokploy keeps prior deployments (**Deployments** tab → redeploy a previous
build). Because migrations are decoupled from boot, rolling back the *code*
does not automatically roll back the *schema* — if the deploy you're rolling
back from included a migration, you need to also manually reverse it
(`php artisan migrate:rollback` — only if the migration's `down()` is safe to
run; check the migration file first) or restore the pre-migration database
backup instead of rolling back code alone. This is exactly why
[the expand/contract migration principle](production-readiness-audit.md)
matters: a well-written migration's `down()` is safe to run; a same-deploy
schema-and-code coupling that dropped a column is not safely reversible by
rollback alone.

### Failed deployment

If a deploy fails (`backend` never reports healthy): check
`docker logs suresign_backend` (see [Logging](#logging) — this now actually
shows Laravel's own errors, not just "container exited"). Common causes:
a bad migration, a missing env var, or a build failure in the Dockerfile
itself (check Dokploy's build log, not the container log, for that case).
Do not repeatedly redeploy hoping it resolves — read the log first.

### Server restart

`docker compose -f docker-compose.prod.yml restart` restarts everything in
place (containers, not volumes — no data loss). Each service's own
healthcheck governs how long Dokploy/Docker waits before considering it
healthy again; `backend`'s `/readyz` will correctly report `503` until MySQL
is reachable, so don't be alarmed by a brief unhealthy window on a full
restart.

### Queue restart

`docker restart suresign_queue`. `stop_grace_period: 490s` (see the audit)
means Docker waits up to ~8 minutes for the current job to finish gracefully
before killing it — a restart is not instant by design, since the
alternative is silently abandoning an in-flight AI analysis or document
generation job. If you need it to stop *now* regardless: `docker kill
suresign_queue` — accept that whatever job was running is lost, and check
`failed_jobs` afterward (see [Queue Operations](#queue-operations)).

### Scheduler restart

`docker restart suresign_scheduler`. Low risk — `schedule:work` dispatches
commands rather than running long work itself, hence its own shorter 30s
grace period. A missed tick during restart just means that hour's scheduled
commands (`suresign:send-deadline-reminders`, `calendar:sync`) run on the
next tick instead; nothing is silently skipped since both are `->hourly()`
checks against real-world state, not fire-once triggers.

### Redis restart

`docker restart suresign_redis`. Safe: Redis here holds only cache/rate-limit/
scheduler-lock data (see the audit's Redis decision table) — nothing
authoritative. A restart just means every cache is cold and every rate limit
counter resets to zero; no data loss in the sense that matters (MySQL is the
source of truth for everything real).

### MySQL restart

`docker restart suresign_mysql`. `mysql_data` is a named Docker volume, so
data survives. Every other service (`backend`/`queue`/`scheduler`) will
briefly fail its own healthcheck while MySQL is down and recover once it's
back — no manual restart of those needed afterward, `restart: unless-stopped`
plus their own healthchecks handle it.

### Disk full recovery

Check with `df -h` on the host. The most likely culprits on this stack:

- **Docker image/build cache** — `docker system df` to see the breakdown,
  `docker image prune -a` to remove unused images (safe; only removes images
  not currently referenced by a running container).
- **`storage/logs/laravel.log`** growing unbounded inside the `backend`
  container's writable layer (not the `backend_storage` volume, so this is
  container-disk, not volume-disk) — now that logs also go to `stderr` (see
  [Logging](#logging)), consider dropping the `single` file channel if this
  becomes a recurring problem; for now, `docker exec suresign_backend sh -c
  '> storage/logs/laravel.log'` truncates it without restarting anything.
- **`mysql_data`** growing from real data growth — legitimate growth needs a
  bigger volume/server, not cleanup; don't delete anything inside it.

Never delete from `mysql_data` or `backend_storage` directly to free space —
if either is genuinely too large, that's a capacity decision (bigger disk),
not a cleanup one.

### Container rebuild

`docker compose -f docker-compose.prod.yml build --no-cache <service>` then
redeploy. Use when a Dockerfile or dependency change isn't being picked up by
a normal deploy (stale build cache) — rare, but the escape hatch when it
happens.

### Worker recovery (queue stuck / not processing)

1. `docker logs suresign_queue --tail 100` — is the process even running?
   The healthcheck (`pgrep -f 'artisan queue:work'`) will already be failing
   if not, and Docker will have restarted the container per
   `restart: unless-stopped`.
2. `docker exec suresign_queue php artisan queue:failed` — lists jobs that
   exhausted retries (both AI jobs have `tries = 1`, so any failure lands
   here immediately, not after retries).
3. `docker exec suresign_queue php artisan queue:retry <id>` to retry a
   specific failed job, or `queue:retry all`. Never `queue:flush` on a
   whim — that deletes the failed-job records, losing the diagnostic trail;
   only do it after actually reading why they failed.

### Document generation recovery

PDF/Excel generation (`DocumentGenerationService`, `ExcelGenerationService`,
`DocxToPdfService`) runs **synchronously inside the HTTP request**, not via
the queue — there is no queued job to retry here. If it's failing:

1. `docker logs suresign_backend | grep -i "libreoffice\|conversion failed"` —
   `DocxToPdfService` logs `Log::error` on any non-zero LibreOffice exit code,
   including the specific exit code and LibreOffice's own stderr.
2. Check LibreOffice itself is present and working:
   `docker exec suresign_backend soffice --version`.
3. `DocxToPdfService`'s conversion has a hard 60-second timeout
   (`CONVERSION_TIMEOUT_SECONDS`) that kills a hung LibreOffice process
   automatically — a genuinely stuck conversion self-resolves within 60s and
   surfaces as a normal error response to the user, not a hung request.
   If users report generation "hanging" for longer than that, the request
   itself (nginx/Traefik timeout) is the more likely cause, not LibreOffice.

### AI job recovery

Both AI jobs (`AnalyseContractWithAiJob`, `AnalyseTradePackageWithAiJob`) have
`tries = 1` — a failure goes straight to `failed_jobs`, never silently
retries and re-bills Claude. Recovery is the same as
[Worker recovery](#worker-recovery-queue-stuck--not-processing) above:
`queue:failed`, inspect, `queue:retry <id>` if the underlying cause (e.g. a
transient Anthropic API error) has passed. `AnalyseContractWithAiJob` logs
the specific failure via `Log::error('AnalyseContractWithAiJob failed', [...])`
with context — check that before retrying blindly.

### Rate limit troubleshooting

Rate limiting now runs through Redis (see the audit). To inspect or clear a
specific throttle key: `docker exec suresign_redis redis-cli --scan
--pattern '*throttle*'` to find keys, `redis-cli del <key>` to clear one.
Never `redis-cli FLUSHALL` / `FLUSHDB` to "fix" a rate limit — that also
clears the application cache and scheduler locks for everything else,
system-wide, not just the one throttle you're troubleshooting.

### Application Monitoring troubleshooting

Full feature write-up:
[internal-docs/super-admin/application-monitoring.md](internal-docs/super-admin/application-monitoring.md).
Quick checks:

- **"Presence unavailable" / no online users showing, but users are clearly
  active**: this means Redis, not the platform, is the problem. Confirm
  Redis is up first (`docker exec suresign_redis redis-cli ping`) before
  assuming a bug — the monitoring page is designed to degrade to
  "unavailable" rather than fail the request, so this is expected behaviour
  during a Redis outage, not a defect.
- **Inspect presence keys directly**: `docker exec suresign_redis redis-cli
  zrange monitoring:presence:index 0 -1 withscores` lists currently-tracked
  user ids and their last-activity unix timestamps;
  `redis-cli hgetall monitoring:presence:data` shows the denormalized
  per-user payload (name/email/role/org/module — never tokens or IPs).
- **Inspect daily module aggregates**: `SELECT * FROM module_usage_daily
  WHERE usage_date = CURDATE() ORDER BY total_visits DESC;` — one row per
  organization/module/day; there is no platform-wide row (see the doc for
  why), so sum across organizations for a platform total.
- **Confirm a monitoring failure isn't affecting the main application**:
  `TrackApplicationUsage` and every Monitoring service method are
  try/catch-wrapped and log via `Log::warning`, never let an exception
  propagate — `docker logs suresign_backend | grep -i "UserPresenceService\|ModuleUsageService\|ApplicationMonitoringService"`
  shows monitoring-specific failures distinctly from real request errors. If
  ordinary pages are failing at the same time, the cause is not this
  feature — monitoring failures are logged and swallowed, not surfaced as
  user-facing errors.

---

## Logging

| Source | Where it goes | Visible via `docker logs`? |
|---|---|---|
| Laravel (`backend`/`queue`/`scheduler`) | `stderr` + `storage/logs/laravel.log` (as of this phase — previously file-only, see the audit) | Yes, now |
| nginx | stdout/stderr (official image default) | Yes |
| MySQL | stdout (official image default) | Yes |
| Redis | stdout (official image default) | Yes |
| Auth/account-status failures | `activity_logs` table (`AuditLog::create`), not a log file | Query the DB, not `docker logs` |
| Failed queue jobs | `failed_jobs` table, not a log file | `php artisan queue:failed`, not `docker logs` |

**Why the Laravel fix matters**: `storage/logs/laravel.log` lives inside each
container's own writable layer, not on the `backend_storage` volume (that
only covers `storage/app`) — file-only logs were invisible to `docker logs`
and gone the moment a container was replaced, which is every single deploy.
Adding `stderr` to the log stack (this phase) means `docker logs
suresign_backend` (or `suresign_queue`/`suresign_scheduler`) now shows real
Laravel errors, and whatever log retention Dokploy/the host's Docker log
driver keeps applies to them.

**No duplicate-logging concern**: auth failures and failed jobs already go
to the database, not the log file — that's correct and was not changed;
piling those into the log file too would just be the same information
twice, once durable/queryable and once not.

---

## Monitoring

Not installed as a platform (out of scope for this phase) — this is the
checklist of what to watch and where the data already exists, for whenever
monitoring is set up.

| What | Where to get it today |
|---|---|
| Container health | `docker ps` (Up/healthy column), or Dokploy's own dashboard |
| Memory / CPU per container | `docker stats`, or Dokploy's Monitoring tab (see the audit — this is also the source for the resource-limit numbers Stack-mode needs) |
| Disk | `df -h` on the host; `docker system df` for Docker's own usage |
| MySQL health | `suresign_mysql`'s own healthcheck (`mysqladmin ping`) already gates `depends_on` for every dependent service |
| Redis health | `suresign_redis`'s own healthcheck (`redis-cli ping`) |
| Queue depth / failed jobs, AI analysis status, document activity, module usage, DAU/WAU/MAU | Super Admin → Application Monitoring (`/admin/application-monitoring`) — see [internal-docs/super-admin/application-monitoring.md](internal-docs/super-admin/application-monitoring.md). This is application-level monitoring (who's using SureSign, what's slow/stuck inside it), not infrastructure monitoring — it does not replace anything else in this table |
| Scheduler running | `suresign_scheduler`'s own healthcheck (`pgrep -f 'artisan schedule:work'`) |
| Application readiness | `GET /readyz` (backend), `GET /login` (frontend) — both already used as Docker healthchecks, both externally curl-able too |
| Storage usage | `docker exec suresign_backend du -sh storage/app` (the `backend_storage` volume) |
| Certificate expiry | Not tracked in-repo — Dokploy/Traefik auto-renews Let's Encrypt certs; verify renewal is actually succeeding via Dokploy's domain settings, not assumed |
| Domain expiry | Not tracked anywhere — a registrar-level reminder, outside this codebase entirely; flagged as a real gap, not a false "monitored" claim |
| Backup age | Not tracked anywhere yet — see [Backup & Restore](#backup--restore); the single biggest monitoring gap of this list |

## Operational Health Verification

`ops/healthcheck.sh` — a fast, repeatable, one-command answer to "is
SureSign operationally healthy right now?" Not a monitoring platform, not a
replacement for Docker's own healthchecks, not Prometheus/Grafana — a
point-in-time verification tool.

### Purpose and when to run it

Run it after: a production deployment, a rollback, a server reboot,
infrastructure maintenance, a Docker/host update, or incident recovery — any
time you've just changed something and want a single answer instead of
manually re-checking each service from the runbooks above. Completes in a
few seconds under normal conditions (well under the one-minute target).

```
./ops/healthcheck.sh
```

Run it from the repo root, on the host that runs `docker compose` — not
inside a container, and not from a machine that only has network access to
the public site (it uses `docker exec`/`docker compose ps`/`docker volume`
directly, since backend and frontend are deliberately not reachable on any
host-published port — see the audit).

### What it checks

Docker service health (every service in `docker-compose.prod.yml`, read
from the file itself — see the script's own header comment for why nothing
here is a second hard-coded inventory), backend readiness (`/readyz`) and
frontend reachability (both via `docker exec`, matching how they're actually
reachable), MySQL and Redis connectivity, storage existence/writability and
the public storage symlink, LibreOffice availability (same `soffice`/
`libreoffice` resolution order `DocxToPdfService` itself uses), all three
persistent volumes, failed queue job count (reported, never cleared or
retried), disk usage against configurable thresholds, whether
`storage/logs` exists and whether the *running* container's `LOG_STACK`
actually includes `stderr` (not just what the compose file says — it reads
the live container's own environment), AI provider key presence (never the
value), every environment variable the compose file references with no
default (derived from `docker compose config`'s own "variable is not set"
warnings — never a second "required vars" list to fall out of sync),
backup/restore script presence, SSL certificate expiry (opt-in only — see
Limitations below), and whether this document still mentions every
currently-deployed service.

### Interpreting the output

Each line is one of:

- `✓` **PASS** — genuinely verified, not assumed.
- `⚠` **WARNING** — worth knowing, not an outage. Includes: a non-core
  service down (`docs`/`marketing` — independent static sites, not part of
  SureSign's own availability), failed jobs present, disk usage above the
  warning threshold, or anything a check couldn't verify because something
  upstream (e.g. the backend container) was already down — reported once
  against the thing that's actually broken, not repeated as a fresh failure
  against everything downstream of it.
- `✗` **FAILURE** — a core service (backend, frontend, mysql, redis, queue,
  scheduler, nginx) down or unhealthy, MySQL/Redis unreachable, storage
  broken, LibreOffice missing, a required env var unset, or a missing
  volume.
- `ℹ` **INFO** — neither pass nor problem; context only (e.g. SSL check
  skipped, no local backup directory found).

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Healthy — no warnings, no failures |
| `1` | Healthy with warnings — nothing critical, but worth a look |
| `2` | Unhealthy — at least one critical check failed |

Meant to be scriptable: `./ops/healthcheck.sh || echo "needs attention"`, or
gate a deploy pipeline on it once one exists.

### Example output

```
==================================================
SureSign Production Health Check
==================================================

Docker
  ✓ mysql running (health: healthy)
  ✓ backend running (health: healthy)
  ✓ frontend running (health: healthy)
  ⚠ marketing: not running
  ✓ nginx running (health: healthy)
  ✓ redis running (health: healthy)
  ⚠ docs: not running
  ✓ queue running (health: healthy)
  ✓ scheduler running (health: healthy)

Application
  ✓ Backend ready (/readyz)
  ✓ Frontend reachable (/login)

Infrastructure
  ✓ MySQL reachable
  ✓ Redis reachable
  ✓ Storage (storage/app) exists and is writable
  ✓ Public storage symlink exists
  ✓ LibreOffice available (soffice — LibreOffice 25.2.3.2 520(Build:2))

Persistent Volumes
  ✓ Volume 'backend_storage' exists (suresign_backend_storage)
  ✓ Volume 'mysql_data' exists (suresign_mysql_data)
  ✓ Volume 'redis_data' exists (suresign_redis_data)

Operations
  ✓ Failed jobs: 0
  ✓ Disk usage: 4% on /, 962673708KB free
  ✓ storage/logs exists
  ⚠ LOG_STACK is 'single', not including stderr — 'docker logs' will not show Laravel errors
  ℹ No AI provider API key set via environment (AI is configured per-organisation in the database)
  ✓ All environment variables referenced by docker-compose.prod.yml are set
  ✓ Backup script present and executable (ops/backup.sh)
  ✓ Restore script present and executable (ops/restore.sh)
  ℹ No local backup directory (backups) — backups may be stored off-host only
  ℹ SSL/domain expiry: skipped (set PRODUCTION_DOMAIN to check)
  ✓ production-operations.md mentions every service defined in docker-compose.prod.yml

Health Summary
Passed:   24
Warnings: 3
Failed:   0

Overall Status
HEALTHY (with warnings)
```

This is real output, captured against the actual local stack while building
the script — including a genuine finding it caught: the running container
was still using the pre-fix `LOG_STACK=single`, because it hadn't been
redeployed since that fix landed in `docker-compose.prod.yml`. The script
correctly reported what's *actually live*, not what the file says — exactly
the distinction an operational verification tool needs to make.

### Operational limitations

- **SSL/domain expiry is opt-in and best-effort.** TLS terminates at
  Cloudflare/Dokploy's Traefik, entirely outside any SureSign container —
  this can only be checked externally, against the real public domain, so
  it's skipped unless you set `PRODUCTION_DOMAIN` explicitly (a hard-coded
  production hostname isn't something this script assumes on your behalf).
  Domain *registration* expiry isn't checked at all — that's a registrar-level
  concern with no local signal to check against.
- **Queue depth has no check** (only the failed-job count) — would need a
  live `SELECT COUNT(*) FROM jobs`, which this script doesn't run since an
  arbitrary point-in-time queue depth isn't itself meaningful without a
  baseline to compare against; noted as a future enhancement, not silently
  skipped.
- **The "does the deployment match `production-operations.md`" check is
  narrow by design** — it confirms every service is *mentioned*, nothing
  about documentation quality or completeness. That's deliberate: the goal
  is operational confidence, not scoring documentation.
- **Requires `docker exec` access to every container it checks** — if the
  operator running it doesn't have Docker permissions, every application-
  level check reports as unable-to-verify (a warning/failure, never a false
  pass).

## Operational Diagnostics

`ops/diagnostics.sh` — complements `healthcheck.sh` rather than replacing
it. Different question, different shape of answer.

### When to run it, and how it differs from healthcheck.sh

| | `healthcheck.sh` | `diagnostics.sh` |
|---|---|---|
| Question | "Is SureSign healthy?" | "If not, why?" |
| Speed | A few seconds, PASS/WARN/FAIL | A little longer; collects evidence across 14 areas |
| Use it | After every deploy/restart/change, routinely | Specifically when `healthcheck.sh` returns WARNING or UNHEALTHY |
| Output | Console only | Console **and** a saved report file |
| Changes anything? | No | No — strictly read-only, always (see below) |

Run it exactly the same way: `./ops/diagnostics.sh`, from the repo root, on
the host running `docker compose`.

### What it's guaranteed not to do

Never modifies production data, never restarts/stops/recreates a container,
never retries or clears a queue job, never clears a cache, never prints a
secret value. If a finding needs one of those actions to fix, that's a
deliberate operator decision guided by the relevant runbook above — this
script surfaces the evidence, it doesn't act on it.

### What it collects

Docker/Compose versions and running containers; per-service container detail
(status, health, restart count, mount points, network, environment variable
*count* only — see below); backend config (PHP/Laravel version, `APP_ENV`,
`LOG_STACK`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, storage
symlink) and pending-migration status; MySQL version/size/table sizes; Redis
version/memory/client count/key count; queue worker process status and
recent failed jobs; scheduler process status; storage permissions and
largest directories; LibreOffice availability (same resolution order as
`DocxToPdfService`); AI provider key presence (never the value); Docker
network/DNS reachability from backend to MySQL and Redis; a one-shot
resource snapshot (`docker stats`); the last 40 lines of backend/queue/
scheduler/nginx logs plus a filtered LibreOffice/AI-failure excerpt;
container restart counts and creation timestamps.

**Environment variables are reported as a count, never as names or values**
— backend, queue, and scheduler all share the same ~60-variable env block
(`docker-compose.prod.yml`'s `*backend_env` anchor), so a full name listing
three times over would be noise, not evidence; the handful of variables that
actually matter for diagnosis are already reported individually in the
Application section.

### Interpreting findings

Every finding in the final "Potential Issues Detected" list is phrased as a
lead, deliberately never a certainty — "possible cause", not "the cause".
The script only reports what its own checks actually found; it does not
speculate beyond that. "No obvious issue detected" means nothing in this
script's specific checks stood out, not that nothing is wrong — a symptom
outside what it collects needs different evidence.

### Where reports are stored

Every run also writes a plain-text copy (ANSI colour codes stripped, safe to
paste into an incident ticket) to `ops/reports/diagnostics-<UTC
timestamp>.txt`. Not committed to git (`ops/reports/*.txt` is gitignored) —
attach the specific file to whatever incident record it's relevant to
instead.

## Queue Operations

- **Worker restart**: see [Worker recovery](#worker-recovery-queue-stuck--not-processing) above.
- **Retry behaviour**: both AI jobs use `tries = 1` deliberately (re-running
  a partially-completed AI analysis risks double-billing Claude for the same
  document) — a failure is final until a human retries it via
  `queue:retry`, never automatic.
- **Timeouts**: `AnalyseContractWithAiJob`'s 480s job timeout is deliberately
  kept under the `database` queue connection's 600s `retry_after`
  (`config/queue.php`) — this relationship must be preserved if either value
  is ever changed; changing one without the other reintroduces the
  double-billing risk the current values were chosen to prevent.
- **Deployment interaction**: `stop_grace_period: 490s` (the audit) means a
  deploy or restart will not kill an in-flight job before it can finish, up
  to that limit.
- **Email jobs**: `EmailNotificationService` calls Brevo's API directly, not
  via a queued job — a Brevo failure surfaces synchronously wherever it's
  called from, not as a queue failure. Nothing to recover here via the queue.

## Scheduler Operations

Two scheduled commands, both `->hourly()->withoutOverlapping()->runInBackground()`
(`routes/console.php`): `suresign:send-deadline-reminders` and `calendar:sync`.

- **Duplicates / locks**: `withoutOverlapping()`'s mutex now lives in Redis
  (since the cache-store change — see the audit), a real atomic lock rather
  than the previous database-cache approximation.
- **Single-instance assumption**: exactly one `scheduler` container is
  expected to exist, ever. `withoutOverlapping()` prevents a second *tick*
  from overlapping a slow-running previous tick on the *same* instance; it
  does not prevent two independent scheduler replicas from both ticking
  simultaneously. This is why the audit explicitly excludes `scheduler` from
  any future Stack-mode replica count above 1 — don't change that without
  redesigning the locking, not just bumping `replicas`.
- **Restart behaviour**: see [Scheduler restart](#scheduler-restart) above —
  low-risk, a missed tick is caught by the next hourly tick.

---

## Backup & Restore

### What's backed up, and how

`ops/backup.sh [destination-dir]` (run on the host, not inside a container):

1. `mysqldump` of the `suresign` database via `docker exec suresign_mysql`
   (no credentials touch this host's shell history — read from the
   container's own env at execution time, same pattern as the existing
   MySQL healthcheck), gzipped.
2. A tar of the `suresign_backend_storage` Docker volume (uploaded files,
   generated documents, branding assets) via a throwaway read-only mount —
   never touches the running `backend`/`queue`/`scheduler` containers, safe
   to run at any time.

**Verified working**: run against the actual local `suresign_mysql`/
`suresign_backend_storage` during this phase — produced a valid 89-table
dump (confirmed by inspecting the dump's own "Dump completed" trailer) and a
valid tar. Not yet run against the production server specifically, since
this session has no access to it.

**What this does *not* cover, and why that's acceptable**: application
images, `docker-compose.prod.yml`, and code are all already in this git
repository — backing them up separately would be redundant. Environment
variables (`.env` equivalent, held in Dokploy's environment panel) are
**not** captured by this script and are not in git (correctly — they're
secrets) — see [Day 0](#day-0--disaster-recovery)'s explicit callout that
recovering these depends on wherever they were originally recorded.

### What's still missing (be honest about this)

- **No automated schedule.** `ops/backup.sh` is a manual script today. It
  should be run on a cron schedule (daily, at minimum) — not yet wired up.
- **No off-host copy.** A backup that stays on the same VPS it backs up is
  not a real backup against "the VPS burned down" — it needs to land
  somewhere else (S3 — `AWS_*` config already exists in `docker-compose.prod.yml`
  though currently unused for this purpose — or even just `scp` to a
  different machine on a schedule). Not implemented.
- **No retention policy.** Old backups are never pruned by this script.
- **Dokploy itself has built-in "Backups" and "Volume Backups" tabs** (visible
  in the service's own settings) that may already provide some or all of
  this natively. Whether they're actually configured was not verified in
  this phase — no browser access. **Check that before assuming
  `ops/backup.sh` is the only backup path**; if Dokploy's own backup feature
  is already configured and working, that may already cover the off-host-copy
  and scheduling gaps above better than a new cron job would.

### RPO / RTO (honest estimates, not measured)

- **RPO** (how much data could be lost): equal to however long since the
  last successful, verified-off-host backup. With no schedule yet, that's
  currently "however long since someone last ran the script by hand" —
  effectively unbounded until the automation gap above is closed.
- **RTO** (how long to recover): the [Day 0](#day-0--disaster-recovery)
  sequence's slowest steps are DNS propagation (minutes to hours, outside
  anyone's control) and re-entering environment variables into a new Dokploy
  service by hand (assuming they're available at all — see the callout
  above). A rough, unverified estimate: 1-2 hours for a calm, prepared
  recovery with all secrets on hand; open-ended if secrets need to be
  recovered from elsewhere first.

### Restore

`ops/restore.sh <db-dump.sql.gz> <storage-tar.gz>` — destructive, requires
typing `yes` to confirm, reverses the backup exactly. See
[Day 0](#day-0--disaster-recovery) for where this fits in a full recovery,
or run it directly on the existing server to roll back to an earlier backup
after a bad deploy that also corrupted data (rare — most bad deploys don't
need a data rollback, only a code one; reach for `restore.sh` only when the
*data* itself is wrong, not just the code).

---

## Known Limitations

- No automated backup schedule or off-host copy yet (see above).
- No monitoring platform installed — the checklist above exists, nothing
  collects it automatically yet.
- Certificate and domain expiry are not tracked anywhere in this repo.
- The Day 0 sequence has not been run end-to-end on a real second server.
- Queue depth has no dashboard (only failed-job count is easily checked).
- Stack-mode conversion remains blocked on real memory metrics and the
  Dokploy service recreation itself — see the audit for the full list; not
  repeated here.

## Future Improvements

- Automate `ops/backup.sh` on a daily cron, with an off-host copy (S3, given
  `AWS_*` config already exists in the compose file) and a retention policy.
- Verify and document Dokploy's native Backups/Volume Backups feature instead
  of maintaining a parallel manual script, if it turns out to already cover
  this.
- A lightweight uptime/health check hitting `/readyz` externally (even a
  free-tier third-party pinger) would close the "is production actually up"
  gap without installing a platform.
- Run the Day 0 sequence for real, once, against a throwaway second VPS, to
  convert "reasoned through" into "verified."
