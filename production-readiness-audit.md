# Production Readiness Audit & Decisions (Phase 1)

Status: Phase 1 complete. SureSign remains V1.0.0. No live production infrastructure
was changed, no production redeploy was performed, and no destructive database or
Docker operation was run. All changes below are local repository changes that take
effect on the next deliberate deploy.

Scope: zero-downtime deployment readiness (Dokploy Compose mode vs. Stack mode) and
a Redis adoption review. No application module (Dashboard, Projects, Commercial,
Documents, Reports, Site Admin, Project Workspaces) was touched.

## 1. Request path (confirmed from the repo, not assumed)

```
Cloudflare -> Dokploy Traefik -> nginx (suresign_nginx, published 8080:80)
                                    -> frontend:3000 (catch-all)
                                    -> backend:8000  (/api/, /storage/)
```

`docs` and `marketing` are separate, independent services with their own published
ports (8082, 3002), routed directly by Traefik to their own domains, bypassing the
`nginx` container entirely. `backend` and `frontend` were previously also published
directly to the host (8000, 3001) even though nothing outside the `suresign` Docker
network used those ports; nginx already reaches both by service name over that
internal network regardless of host publishing. Those two port mappings have been
removed (see Section 3).

## 2. Current Dokploy mode and Swarm status

- The `production-suresign` Compose service in Dokploy runs in plain **Compose**
  mode (confirmed via the Deploy Settings badge), not **Stack** mode.
- Docker Swarm is already active on the `suresign-prod` server as a single-node
  Swarm (1/1 active node, leader). This is Dokploy's own default; it was not set up
  for this project specifically.
- In plain Compose mode, Dokploy runs `docker compose up -d --build` and ignores any
  `deploy:` block entirely. Every redeploy currently stops and recreates the whole
  stack, causing a real downtime window (whole stack, not per-service).
- Stack mode (`docker stack deploy`) would honor `deploy:` directives (replicas,
  `update_config`, `start-first` ordering) and give genuine rolling, zero-downtime
  updates for the services configured that way. Compose Type can only be set when a
  Dokploy service is first created, not toggled on an existing one.

## 3. Fixes implemented this phase

### 3.1 Startup no longer runs migrations or seeding automatically

**Finding:** `backend/docker/entrypoint.sh` ran `php artisan migrate --force` and
`php artisan db:seed --force` on every single container start (not just first boot).
This was already reseeding the database on every restart, and would have become a
genuine race condition under a future multi-replica rolling deployment: two backend
containers starting during a `start-first` overlap could both run `migrate --force`
against the same schema at the same time.

**Fix:** `entrypoint.sh` no longer runs `migrate` or `db:seed` in its default
(serve) path. A new explicit `migrate` subcommand runs the migration only, meant to
be triggered deliberately, once per release:

```
docker compose -f docker-compose.prod.yml run --rm backend migrate
# or, against an already-running container:
docker exec suresign_backend php artisan migrate --force
```

`db:seed` is no longer run automatically at all. `DatabaseSeeder` is idempotent
(`firstOrCreate`/fill-only-empty), so this was not destructive, but it was
unnecessary write load on every restart and is a deliberate, occasional operator
action (initial setup, deliberate role/permission changes), not a routine deploy
step.

Local development needed the old convenience preserved: `docker-compose.dev.yml`
now overrides the backend `command` to `["dev"]`, and `entrypoint.sh` has a `dev`
branch that keeps the old always-migrate-and-seed behaviour, since a single-container
local stack has no concurrent-replica concern.

**Operational consequence:** `queue` and `scheduler` no longer wait on migrations by
proxy through `backend`'s healthcheck (that healthcheck now proves "backend can
reach MySQL", not "schema is up to date", since migration is decoupled). Run the
`migrate` step before or as the first step of any deploy that includes a schema
change, not after.

### 3.2 Queue worker graceful shutdown

**Finding:** the `queue` service had no `stop_grace_period`. Docker's default is
10 seconds (SIGTERM, then SIGKILL). `AnalyseContractWithAiJob` has a 480 second
timeout and `tries = 1` (no retry). `queue:work` already handles SIGTERM gracefully
(`pcntl` is installed in `backend/Dockerfile`) by finishing the current job before
exiting, but only if actually given the time. Without a longer grace period, every
deploy or restart hard-kills whatever job happens to be running, mid-AI-analysis or
mid-document-generation, with no retry.

**Fix:** added `stop_grace_period: 490s` to `queue` (480s longest job timeout plus a
small margin) and `stop_grace_period: 30s` to `scheduler` (dispatches jobs rather
than running long work itself, so needs far less).

### 3.3 Unnecessary host port publishing removed

Removed `ports: ["8000:8000"]` from `backend` and `ports: ["3001:3000"]` from
`frontend`. Neither port was used by anything outside the `suresign` Docker
network; nginx already reaches both containers by service name
(`backend:8000`, `frontend:3000`) regardless of host publishing.
`bootstrap/app.php` already had a comment anticipating this exact gap
("that direct port should still be closed off... as a separate hardening step").
`nginx`, `docs`, and `marketing` keep their published ports unchanged, since Traefik
routes to them directly.

### 3.4 Health check improved: liveness vs. readiness

**Finding:** the backend's only health check was Laravel's built-in `/up`
(registered via `bootstrap/app.php`'s `health:` param), which only proves the PHP
process booted. It never touches the database. This was previously an acceptable
proxy because `entrypoint.sh` ran `migrate --force` synchronously before `artisan
serve` started listening, so a healthy `/up` implicitly meant the database was
reachable at boot. That implicit guarantee no longer holds now that migration is
decoupled from startup (Section 3.1).

**Fix:** added `GET /readyz` (`backend/routes/web.php`), a minimal readiness probe
that does one `DB::connection()->getPdo()` check and returns 200/`{"status":"ready"}`
or 503/`{"status":"not ready"}` (no internal detail leaked on failure). The backend
Docker health check now targets `/readyz` instead of `/up`. This is exactly the
"alive" vs. "ready to serve real traffic" distinction a future `start-first` rolling
update needs: a new container should not receive routed traffic until it can reach
MySQL, since every real request needs it.

The frontend's existing health check (`GET /login`, an unauthenticated, always
server-rendered page) was audited and found already adequate: it proves the Next.js
server is actually responding with rendered content, not just alive. No change made.

### 3.5 Redis cache adoption

See Section 4 for the full per-capability decision table. Net change:
`CACHE_STORE` moved from `database` to `redis` in `docker-compose.prod.yml`. Because
Laravel's default `RateLimiter` and the scheduler's `withoutOverlapping()` lock both
read `config('cache.default')`, this single change also moves rate limiting and the
scheduler mutex onto Redis, without any separate driver change.

**Correction (2026-07-20):** this section's recommendation was applied without first
installing the `phpredis` PHP extension in `backend/Dockerfile` — `REDIS_CLIENT=phpredis`
requires the actual C extension, which does not ship with the base PHP image. The gap
caused a full production outage (`Class "Redis" not found` on every request, since the
rate-limiter middleware above touches the cache store on every API call). The extension
is now installed in `backend/Dockerfile`. Anyone repeating this recommendation on a
different image/base must confirm the extension is present (`php -m | grep redis`) and
verify with a real HTTP request through the cache — not just `redis-cli ping` against
the container — before flipping `CACHE_STORE` to `redis`.

### 3.6 Forward-looking Swarm `deploy:` blocks (inert today)

Added `deploy:` blocks to `backend` and `frontend` (`replicas: 1`,
`update_config.order: start-first`, `failure_action: rollback`, a matching
`rollback_config`, `restart_policy`). Dokploy's plain Compose mode ignores `deploy:`
entirely, so this has no live effect until a future Stack-mode conversion. No memory
limit or reservation was set: no real production memory metrics have been captured
yet, and guessing a number would be worse than leaving it unset. Set those from
Dokploy's Monitoring tab data before relying on this in an actual Stack deployment.

No `deploy:` block was added to `mysql`, `redis`, `queue`, `scheduler`, `docs`, or
`marketing`. `mysql`/`redis` are stateful and must stay single-instance regardless of
deploy mode. `queue`/`scheduler` must stay single-replica (a second scheduler replica
would double-run scheduled commands; `withoutOverlapping()` protects against overlap
within one replica, not against two independent replicas both ticking). `docs`/
`marketing` are low-traffic and not part of the zero-downtime concern.

## 4. Redis decision table

Redis was already provisioned, health-checked (`redis-cli ping`), given a durable
named volume (`redis_data`), and reachable only over the internal `suresign` Docker
network (no host-published port). It had **zero application usage** anywhere in
`app/` before this phase (`grep -rn "Redis::" app/` returned nothing).

| Capability        | Before this phase | Decision                                  | Why |
|--------------------|-------------------|--------------------------------------------|-----|
| Cache              | `database`        | **Enabled now** (`CACHE_STORE=redis`)      | Already-isolated key namespace (`redis.cache` connection, separate DB index), cache data was never authoritative (a Redis outage degrades to cache misses, not data loss), and this offloads high-frequency ephemeral writes from MySQL. |
| Rate limiting      | `database` (via default cache store) | **Enabled now**, as a side effect of the cache change | Laravel's `RateLimiter::for` and `throttle:*` middleware read `config('cache.default')`; no separate driver exists to change. Login/password/AI-analysis throttling is exactly the small-write, high-frequency pattern Redis suits. |
| Scheduler locks    | `database` (via `withoutOverlapping()`'s default cache mutex) | **Enabled now**, same side effect | Only one scheduler replica exists and will continue to; the lock itself is now backed by a store built for it. |
| Sessions           | `file` (container-local; misaligned with `.env.example`'s `database`) | **Fixed to `database`, not Redis** | Server-side sessions are not materially used: auth is Sanctum bearer tokens (`auth:sanctum` in `routes/api.php`, `createToken` in `AuthController`), and no `EnsureFrontendRequestsAreStateful` middleware is registered anywhere. `file` was still a real rolling-deployment blocker (container-local state) regardless of usage, so it was corrected to the already-shared, already-migrated `sessions` table at zero added infrastructure cost. Moving unused sessions onto Redis would add a dependency for no real benefit. |
| Queues             | `database`        | **Deferred to a separate controlled phase** | `config/queue.php`'s `database` connection has a deliberately tuned `retry_after=600s` against `AnalyseContractWithAiJob`'s 480s timeout (documented in-file: prevents a still-running job being re-reserved and double-billing Claude). The `redis` queue connection has its own, different default `retry_after=90s`. Switching drivers without re-tuning that relationship, and without separately reviewing every other queued job's retry/timeout/idempotency behaviour, is exactly the kind of change the audit's own safety rules call out as unsafe to make casually. The database queue already gives correct shared-state behaviour across any future replica; Redis here would be a throughput optimisation, not a correctness fix. |

Redis remains non-authoritative for all SureSign business data; MySQL is the sole
source of truth. Redis is not exposed on any host port and has no authentication
configured (`REDIS_PASSWORD=null`), which is an acceptable risk only because it is
unreachable from outside the Docker network; adding `--requirepass` is a reasonable
future hardening step but was not made in this phase to keep the change set to what
was asked for.

## 5. Remaining blockers to a live Stack-mode conversion

None of these were fixed in this phase; they are the honest remaining list.

1. **No real production memory metrics.** The `deploy:` blocks added have no
   resource limits because none have been measured. Pull idle/normal/peak memory
   from Dokploy's Monitoring tab before setting limits, and before trusting that the
   CX33's 8GB is enough headroom for a `start-first` overlap of `backend` (the
   heaviest container, given LibreOffice).
2. **The Compose Type badge cannot be changed on the existing Dokploy service.**
   Moving to Stack mode means recreating the Dokploy service against the same repo
   and compose file, not flipping a setting. That recreation itself is the
   remaining risky step, and is out of scope for this phase.
3. **`nginx` should not be included in the initial rolling set.** It is not
   rebuilt as part of a normal app deploy (its image is the pinned upstream
   `nginx:1.31-alpine`; only its bind-mounted config or the pin itself would
   trigger a rebuild), so it is not a meaningful source of the downtime this audit
   was scoped to fix. Recommendation stands: **Not recommended** for the first
   rolling deployment; revisit only if nginx's own config starts changing as part
   of routine deploys.
4. **Log capture — resolved in the Production Operations phase (2026-07-20).**
   `LOG_STACK` changed from `single` to `stderr,single`; `docker logs
   suresign_backend`/`suresign_queue`/`suresign_scheduler` now show real
   Laravel errors, not just container lifecycle events. See
   `production-operations.md`'s Logging section for the full picture across
   every service, and Section 10 below for what else that phase added
   (`ops/backup.sh`/`ops/restore.sh`, verified against the real local
   containers) — not repeated here, per that document's own scope.

## 6. Decision summary

- **Zero-downtime deployment:** Ready after listed blockers are fixed (Section 5).
  Not attempted live in this phase.
- **Backend rolling deployment:** Safe after changes (this phase's fixes: decoupled
  migrations, `/readyz`, no unnecessary published port).
- **Frontend rolling deployment:** Safe (already had an adequate readiness check;
  removed unnecessary published port).
- **Nginx rolling deployment:** Not recommended for the first rolling deployment.
- **MySQL / Redis:** Single-instance, no `deploy:` changes; correct as-is.
- **Redis cache:** Enable now. Done.
- **Redis rate limiting:** Enable now (side effect of the cache change). Done.
- **Redis scheduler locks:** Enable now (side effect of the cache change). Done.
- **Redis sessions:** Defer; server-side sessions are not materially used.
  Container-local state risk fixed separately via `database`, not Redis.
- **Redis queues:** Defer to a separate, isolated phase (retry/timeout re-tuning
  required first).

## 7. Files changed

- `backend/docker/entrypoint.sh`: removed automatic `migrate`/`db:seed` from the
  default path; added `migrate` and `dev` subcommands.
- `backend/routes/web.php`: added `GET /readyz`.
- `backend/tests/Feature/ReadinessProbeTest.php`: new, covers the 200/503 paths.
- `docker-compose.prod.yml`: `SESSION_DRIVER` file to database; `CACHE_STORE`
  database to redis; removed `backend`/`frontend` published ports; added
  `stop_grace_period` to `backend`/`frontend`/`queue`/`scheduler`; backend health
  check target changed to `/readyz`; added forward-looking `deploy:` blocks to
  `backend`/`frontend`; updated `depends_on` comments on `queue`/`scheduler` to
  reflect that migrations are no longer implied by `backend`'s health check.
- `docker-compose.dev.yml`: added `command: ["dev"]` override for `backend`.
- `project-context.md`: added a short Production/Deployment section (see below).

## 8. Tests

- Test isolation confirmed before running anything: `backend/phpunit.xml` forces
  `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
  `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. No test can reach production MySQL
  or Redis; nothing was flushed or mutated on any shared instance.
- Focused: `ReadinessProbeTest` (2 tests), rate-limiting regression suite
  (`PasswordChangeRateLimitingTest`, `AiAnalysisRateLimitingTest`,
  `AuthRateLimitingTest`, 30 tests). All passed.
- Full backend suite: 594 tests, 566 passed, 2155 assertions, 2 failures. Both
  failures (`AdjudicationDocumentTenantIsolationTest`,
  `ReportsCommercialSummaryReportTest`) are the same pre-existing local environment
  issue: `storage/app/private/...` subdirectories owned by `root` from an earlier
  container run, unwritable by the current shell user. Confirmed unrelated to this
  phase's changes (same failure mode on both, a filesystem permission error, not an
  assertion mismatch) and pre-existing in the working tree before this phase began.

## 9. Recommended next steps

1. Capture real memory/CPU metrics from Dokploy's Monitoring tab across a normal day
   and a document-generation-heavy period before setting any resource limit.
2. When ready to actually convert to Stack mode: recreate the Dokploy service
   against this same repo, as a deliberate, separately-authorised operation, not a
   side effect of a routine deploy.
3. Run the new `migrate` step deliberately before/with every deploy that includes a
   schema change, using the command documented in Section 3.1.
4. Consider `LOG_CHANNEL=stderr` in a future, separate change so `docker logs`
   captures application logs across container replacement.
5. Revisit Redis queues as its own isolated phase if AI-analysis or document
   generation throughput becomes a real bottleneck; requires re-tuning
   `retry_after` against Redis's defaults first.

## 10. Addendum — Production Operations phase (2026-07-20)

A separate phase focused on operational readiness rather than architecture:
deployment/rollback/recovery runbooks, a logging audit, and — the previously
entirely unaudited gap — backup and restore. That phase produced
`production-operations.md` as the living operational handbook; this document
remains the historical engineering record and is deliberately not rewritten
to duplicate it. The one blocker above whose status actually changed
(log capture, Section 5.4) has been updated in place; everything else in this
audit — the Stack-mode blockers, the Redis decisions, the request path — is
unchanged and still accurate as of that phase.
