# Demo Environment — Deployment

Permanent deployment of the demo environment to `demo.suresigncontracts.app`, alongside Production and Development. See [index.md](index.md) for the seeder/data architecture — this page covers the infrastructure specifically.

## Architecture summary

A separate Dokploy project (`Demo`), deployed via `docker-compose.demo.yml`, alongside `Production` (`docker-compose.prod.yml`) and `Development` (`docker-compose.dev.yml`, untouched by this work).

| Component | Approach |
|---|---|
| Backend | Own container (`demo-backend`), reuses Production's `suresign-backend:latest` image **unmodified** — Laravel config is entirely runtime env, nothing is baked in at build time. |
| Frontend | Own container (`demo-frontend`), **its own build** (`suresign-frontend-demo:latest`) — same Dockerfile/source as Production, different build args. Cannot reuse Production's built image: Next.js bakes `NEXT_PUBLIC_API_URL` into the client bundle at build time (confirmed via `frontend/src/lib/api.ts`), so a shared image would send every demo browser request to the *production* API. |
| Queue | One lightweight worker (`demo-queue`), same image as `demo-backend`. |
| Scheduler | **None.** Deliberately omitted — see "Why no scheduler" below. |
| MySQL | Shared with Production — same `mysql` container, the existing `suresign_demo` database, a **dedicated least-privilege MySQL user** scoped only to it. |
| Redis | Shared with Production — same `redis` container, a distinct logical DB index (`REDIS_CACHE_DB=1`) and a distinct cache/session prefix (`suresign_demo_`). |
| Storage | Fully isolated — its own named Docker volume (`demo_backend_storage`), never the same volume as Production's `backend_storage`. |
| Networking | Joins Production's Docker network (`suresign_shared`, an external network) to resolve `mysql`/`redis` by service name — no ports published for either. |
| Domain termination | Its own Nginx (`demo-nginx`, `docker/nginx/demo.conf`), published on its own host port, wired to `demo.suresigncontracts.app` directly in Dokploy/Cloudflare — bypassing Production's Nginx entirely, the same pattern already used for `docs`/`marketing`. |

## Files

```
docker-compose.demo.yml            The Demo stack: demo-backend, demo-frontend
                                    (with its own build block), demo-queue,
                                    demo-nginx. No demo-scheduler service.
docker/nginx/demo.conf              Domain routing + rate limiting + noindex
                                    headers for demo.suresigncontracts.app.
frontend/Dockerfile                 Gained two demo-only build ARGs
                                    (NEXT_PUBLIC_DEMO_MODE, NEXT_PUBLIC_DEMO_VERSION)
                                    — unset (falsy) for every Production build.
frontend/src/components/shared/DemoBanner.tsx
                                    The visual indicator — see "Visual indicator" below.
frontend/src/app/layout.tsx          Renders <DemoBanner /> unconditionally; the
                                    component itself no-ops outside a demo build.
backend/config/app.php               Gained 'demo' => env('APP_DEMO', false) —
                                    marks the running instance itself as demo.
docker-compose.prod.yml               Network given an explicit name
                                    (`suresign_shared`) so docker-compose.demo.yml
                                    can join it as `external: true`. Addressing
                                    change only — no behavioural change to
                                    Production on its own.
backend/.dockerignore                 Excludes storage/app/demo-private/* —
                                    found and fixed during validation (see
                                    "Issue found during validation" below).
```

## Why no scheduler

The scheduler drives time-based, org-wide production behaviours (`SendDeadlineReminders`, `NotificationEngineService`'s hourly `calendar:sync`, module-usage aggregation) that are meaningless against a fixed-anchor-date demo dataset (see [index.md](index.md)'s Anchor date strategy) and carry a real, avoidable risk: a running scheduler could attempt to send a real deadline-reminder email referencing one of Halden Grove's fictional (but plausible-looking) addresses. `MAIL_MAILER=log` on every demo container already makes any outbound send technically impossible, but omitting the scheduler entirely is the simpler, structurally safer choice — there's no time-based job to accidentally misfire if the process doesn't exist.

## Security posture

- **Never indexed**: `X-Robots-Tag: noindex, nofollow, noarchive` header plus a `Disallow: /` `robots.txt`, both served directly by `demo-nginx` (`docker/nginx/demo.conf`).
- **Rate limiting**: a general zone (20 req/s per IP) and a tighter zone specifically on `/api/login` (5 req/s per IP) at the Nginx layer, in addition to whatever Cloudflare-side rules are configured (the primary control — see "Cloudflare configuration" below).
- **No outbound integrations possible**: `MAIL_MAILER=log`, `AI_ENABLED=false`, and blank `OPENAI_API_KEY`/`AWS_*`/`COMPANIES_HOUSE_API_KEY` on every demo container.
- **Database least-privilege**: a dedicated MySQL user (see below) with `GRANT ALL ON suresign_demo.*` only — no access whatsoever to the real `suresign` database, even if the demo backend were fully compromised.
- **Distinct session/cache namespace**: `SESSION_COOKIE=suresign_demo_session`, `CACHE_PREFIX=suresign_demo_`, `REDIS_CACHE_DB=1` — a demo session or cache key can never collide with or be confused for a production one.
- **`security_opt: no-new-privileges:true`** on every demo container, matching Production's existing containers.

### Creating the dedicated MySQL user

Run once against the production MySQL server before first deploy (safe, additive — grants only on `suresign_demo`, touches no existing user or data):

```sql
CREATE USER IF NOT EXISTS 'suresign_demo'@'%' IDENTIFIED BY '<a real, generated secret — never reuse the placeholder from local testing>';
GRANT ALL PRIVILEGES ON suresign_demo.* TO 'suresign_demo'@'%';
FLUSH PRIVILEGES;
```

Store the password as `DEMO_DB_PASSWORD` in Dokploy's environment secrets for the Demo project.

## Environment variables (Dokploy → Demo project secrets)

| Variable | Value | Notes |
|---|---|---|
| `DEMO_APP_KEY` | Output of `php artisan key:generate --show` | **Generate a fresh one — never reuse Production's `APP_KEY`.** |
| `DEMO_DB_USERNAME` | `suresign_demo` | The dedicated least-privilege user above. |
| `DEMO_DB_PASSWORD` | The real generated secret | Never the local-testing placeholder. |
| `DEMO_APP_URL` | `https://demo.suresigncontracts.app` | Defaults to this if unset. |
| `DEMO_NEXT_PUBLIC_API_URL` | `https://demo.suresigncontracts.app/api` | Defaults to this if unset — baked into the frontend build. |
| `DEMO_VERSION` | `1.0.0` | Baked into the frontend build for the banner text; keep in sync with `config('demo.version.version')`. |

`DB_PASSWORD` (Production's existing secret) is **not** reused — the demo backend authenticates as its own dedicated MySQL user, not the `suresign` user.

## Visual indicator

`DemoBanner.tsx` renders a small strip — "SureSign Demo Environment · Demo Version {X} · Fictional demonstration data" — only when `NEXT_PUBLIC_DEMO_MODE=true` was baked into the build (Production never sets this build arg, so the component is permanently inert there; verified in production code, not just by convention).

**Two ways to hide it for a screenshot session:**
- Append `?hideDemoBanner=1` to any URL — hides it for that page load only, nothing persisted.
- Click the banner's close (×) button — persists via `localStorage`, stays hidden for the rest of that browser's session.

## Dokploy deployment steps

1. **Confirm Production is already deployed** and its `suresign` network exists under the name `suresign_shared` (redeploy Production first if `docker-compose.prod.yml`'s network-naming change hasn't been applied yet — Compose will rename/recreate the network on next deploy; this briefly recreates Production's containers to reattach them, so schedule it as a normal maintenance deploy, not mid-incident).
2. **Create the dedicated MySQL user** (see above) against the production MySQL server.
3. **Create a new Dokploy project**: `Demo`.
4. **Add a new Compose service** in that project pointing at `docker-compose.demo.yml` in this repo.
5. **Set the environment variables** listed above as Dokploy secrets for the Demo project.
6. **Deploy.** Dokploy builds `demo-frontend` (its own build step, ~same duration as Production's frontend build) and pulls/reuses `suresign-backend:latest` for `demo-backend`/`demo-queue` (no additional build time if Production was already built on this host).
7. **Run the release step** (mirroring how Production's migrations are a deliberate, separate step): `docker exec suresign_demo_backend php artisan demo:reset --force` — this migrates and fully seeds the demo database for the first time.
8. **Wire the domain**: in Dokploy, point `demo.suresigncontracts.app` at the `demo-nginx` service's published port (8081 by default — confirm this port is actually free on the target host first; it collides with an unrelated local project in this dev sandbox, which is not relevant to the real server).
9. **Cloudflare DNS**: add an `A` (or `CNAME`, matching however `app.suresigncontracts.app`/`docs.suresigncontracts.app` are already configured) record for `demo.suresigncontracts.app` pointing at the same Hetzner VPS. Enable Cloudflare's proxy (orange cloud) for the DDoS/bot protection layer.
10. **Cloudflare rules**: add a rate-limiting rule for `demo.suresigncontracts.app` (in addition to `demo-nginx`'s own zones) and confirm the domain is excluded from Cloudflare's own indexing/caching of HTML if that's configured differently per-domain.
11. **Run validation** (see below).

## Validation performed (local, before this real deployment)

Before writing these deployment steps, the exact `docker-compose.demo.yml` architecture was validated end-to-end in this dev sandbox — not merely reviewed on paper. Summary (full detail in `project-context.md`):

- Built `suresign-frontend-demo:latest` with demo build args; confirmed `demo.suresigncontracts.app` correctly baked into the client bundle (`grep` across `.next/static/chunks/`).
- Brought up `demo-backend`, `demo-queue`, `demo-frontend` against Production's actual `mysql`/`redis` containers (via a network carrying the same service-name aliases Compose would create in the real deployment).
- `demo-backend`'s `/readyz` returns `200`; `config('app.demo')` returns `true`; `DB::connection()->getDatabaseName()` returns `suresign_demo`.
- Ran `demo:reset --force` inside the real container — full 7-project portfolio seeded cleanly.
- Ran `demo:validate` — 0 errors, 0 warnings, Business signals unchanged from prior runs.
- Ran `demo:manifest --write` — froze successfully to the isolated storage path.
- Confirmed storage isolation via `/proc/mounts` inside the container: `storage/app` is mounted from the dedicated `demo_backend_storage` volume (a distinct block device), not a subfolder of Production's volume.
- Confirmed Production was **completely unaffected** throughout: `suresign_backend` stayed on the `suresign` database the whole time, and `suresign`/`suresign_demo` both show the same table count (98) post-migration, proving no cross-contamination.
- **One real issue found and fixed during this validation**: `backend/.dockerignore` didn't exclude `storage/app/demo-private/*`, and a root-owned leftover directory there (from earlier local dev testing) blocked `docker build` outright ("permission denied" on the build context). Fixed by adding it to `.dockerignore`, matching the existing pattern already used for `storage/framework/testing`.
- Local test containers, volumes, and the temporary network were fully torn down afterward — this dev sandbox was left exactly as found (only the dedicated `suresign_demo` MySQL user was intentionally kept, since it's a genuinely useful artifact here too and is scoped safely).

## Post-deployment validation checklist (run this on the real server)

```bash
docker exec suresign_demo_backend php artisan demo:reset --force
docker exec suresign_demo_backend php artisan demo:validate
docker exec suresign_demo_backend php artisan demo:status
docker exec suresign_demo_backend php artisan demo:manifest --write
```

Confirm:
- [ ] `demo.suresigncontracts.app` loads the login page over HTTPS.
- [ ] Logging in as `daniel.okafor@haldengroveconstruction.com` (reset the password first — see [index.md](index.md)'s Passwords section; it was never printed anywhere retrievable across this session's many re-seeds) reaches a populated dashboard.
- [ ] `demo:validate` reports 0 errors.
- [ ] `curl https://demo.suresigncontracts.app/robots.txt` returns `Disallow: /`.
- [ ] `docker exec suresign_demo_queue pgrep -f 'artisan queue:work'` succeeds.
- [ ] No `demo-scheduler` container exists in the Demo project.
- [ ] Production's dashboard/data are completely unaffected — spot-check `app.suresigncontracts.app` still shows real customer data, not Halden Grove's.

## Maintenance

- **Reseed**: `demo:seed` (idempotent, safe anytime) or `demo:reset --force` (full rebuild) — identical commands to this dev sandbox, just run against the permanent container.
- **Freeze before a capture session**: `demo:manifest --write`, per the Screenshot Production Pack's pre-capture checklist.
- **Updating to a new platform version**: redeploy the Demo project in Dokploy after Production's own deploy — since `demo-backend`/`demo-queue` reuse `suresign-backend:latest` directly, a Production release is automatically available to Demo on its next redeploy, no separate build. `demo-frontend` needs its own rebuild (same trigger, same Dokploy redeploy action) since it's a distinct image.
- **Version drift check**: `demo:manifest` (without `--write`) any time to see if the live environment has drifted from the last frozen snapshot.

## Rollback

- **Bad demo data** (a seeder bug, a bad manual edit): `demo:reset --force` rebuilds from scratch — the demo database has no real data to lose.
- **Bad platform deploy** (Demo now running a broken image): redeploy the previous image tag for `demo-backend`/`demo-frontend` in Dokploy, exactly as you would roll back Production — the demo stack has no independent versioning concern beyond "which image tag is running."
- **Demo stack itself needs to come down**: `docker compose -f docker-compose.demo.yml down` removes the demo containers only — `suresign_shared` network, `mysql`, `redis`, and Production are untouched (the network is `external: true` from the demo file's perspective, so `down` never attempts to remove it).

## Screenshot workflow

See the **Marketing Asset Production Guide** and **Screenshot Production Pack** (separate deliverables) for the full capture process. In short, against `demo.suresigncontracts.app`:

1. `demo:validate` — confirm 0 errors.
2. `demo:manifest --write` — freeze the state about to be captured.
3. Log in as the persona specified per shot (see the Master Shot List), append `?hideDemoBanner=1` to every URL during capture.
4. Capture per the Screenshot Capture Guide's exact spec (viewport, theme, sidebar state, etc.).
5. `demo:manifest` (no `--write`) afterward — confirm "No drift," proving nothing changed mid-session.
