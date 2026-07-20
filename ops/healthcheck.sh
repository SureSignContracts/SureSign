#!/usr/bin/env bash
#
# SureSign Operational Health Verification
#
# Fast, repeatable answer to one question: "is SureSign operationally
# healthy right now?" Run this after a deploy, rollback, server reboot,
# infrastructure maintenance, Docker update, or incident recovery — see
# production-operations.md's "Operational Health Verification" section for
# when/how to read the output.
#
# This is NOT a monitoring platform, not a replacement for Docker's own
# healthchecks, and not Prometheus/Grafana. It is a point-in-time check an
# operator runs by hand (or a deploy script calls) and reads the exit code
# of. Run on the HOST that runs `docker compose`, not inside a container.
#
# Design notes (why it's built this way, not just what it does):
#   - docker-compose.prod.yml is treated as the single source of truth.
#     Service names, volume names, and required env vars are all read FROM
#     the compose file at run time, not maintained as a second hard-coded
#     list here — so this script can't silently drift out of sync with the
#     actual deployment the way a second inventory would.
#   - backend/frontend have no published host ports (removed deliberately —
#     see production-readiness-audit.md) — every application-level check
#     runs via `docker exec` into the relevant container, matching how the
#     real deployment is actually reachable, not assuming host access that
#     doesn't exist.
#   - Queue/scheduler "is it actually running" is answered by reading
#     Docker's own HEALTHCHECK verdict for those containers (they already
#     run a pgrep-based check — see docker-compose.prod.yml) rather than
#     reimplementing process detection a second time here.
#   - LibreOffice is checked via the same binary resolution order
#     (soffice, then libreoffice) that DocxToPdfService itself uses, so this
#     script can never disagree with the application about what "installed"
#     means.
#   - `set -euo pipefail` stays on for genuinely unexpected errors, but every
#     individual check is wrapped in an explicit if/else — a failing curl,
#     redis-cli, or mysqladmin is expected, handled, recorded as PASS/WARN/
#     FAIL, and never aborts the script (commands tested by `if` are exempt
#     from `set -e` by design — this isn't a workaround, it's the correct
#     use of the feature).
#
# Complements ops/diagnostics.sh: this script answers "is SureSign healthy",
# fast, PASS/WARN/FAIL only. diagnostics.sh answers "why", in more depth,
# when this one comes back WARNING or UNHEALTHY. Deployment knowledge shared
# between the two (compose file location, service names, container
# resolution) lives in ops/lib/common.sh, not duplicated here.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

# ── Configuration specific to this script (shared config is in common.sh) ─

DISK_PATH="${DISK_PATH:-/}"
DISK_WARN_PCT="${DISK_WARN_PCT:-80}"
DISK_CRIT_PCT="${DISK_CRIT_PCT:-90}"

FAILED_JOBS_WARN_THRESHOLD="${FAILED_JOBS_WARN_THRESHOLD:-1}"

# Best-effort only (see the "SSL / Domain" section) — left unset by default
# deliberately, since hard-coding a production hostname into a generic
# script is exactly the kind of environment-specific assumption this script
# otherwise avoids. Set it explicitly when running against a real host:
#   PRODUCTION_DOMAIN=app.suresigncontracts.app ops/healthcheck.sh
PRODUCTION_DOMAIN="${PRODUCTION_DOMAIN:-}"

# ── Output helpers specific to this script — pass/warn/fail need their own
#    running counts to drive the exit code; diagnostics.sh has no equivalent
#    counters, which is why these stay here rather than in common.sh. ─────

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

pass() { printf '  %s\xe2\x9c\x93%s %s\n' "$C_GREEN" "$C_RESET" "$1"; PASS_COUNT=$((PASS_COUNT + 1)); }
warn() { printf '  %s\xe2\x9a\xa0%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; WARN_COUNT=$((WARN_COUNT + 1)); }
fail() { printf '  %s\xe2\x9c\x97%s %s\n' "$C_RED" "$C_RESET" "$1"; FAIL_COUNT=$((FAIL_COUNT + 1)); }
info() { printf '  %s\xe2\x84\xb9%s %s\n' "$C_BLUE" "$C_RESET" "$1"; }

require_docker

# ==========================================================================
printf '%s==================================================%s\n' "$C_BOLD" "$C_RESET"
printf '%sSureSign Production Health Check%s\n' "$C_BOLD" "$C_RESET"
printf '%s==================================================%s\n' "$C_BOLD" "$C_RESET"

# ── 1. Docker services ────────────────────────────────────────────────────
# Every service defined in docker-compose.prod.yml, derived from the file
# itself rather than a hard-coded list — this also answers "are the queue
# worker and scheduler actually running" (checks 6/7), since both already
# carry a pgrep-based HEALTHCHECK in the compose file; reading Docker's own
# verdict on that is more accurate than this script re-detecting the process
# a second, independent way.

section "Docker"

ALL_SERVICES="$($DC config --services 2>/dev/null || true)"
if [ -z "$ALL_SERVICES" ]; then
  fail "Could not read service list from $COMPOSE_FILE"
else
  while IFS= read -r svc; do
    [ -n "$svc" ] || continue
    # Core services report FAIL when down/unhealthy (they mean SureSign
    # itself is down); non-core services (docs/marketing today) report WARN
    # instead — visible, but not a false "SureSign is unhealthy" over an
    # independent static site being down. See CORE_SERVICES above.
    if is_core_service "$svc"; then problem_fn="fail"; else problem_fn="warn"; fi
    cid="$(cid_for "$svc")"
    if [ -z "$cid" ]; then
      "$problem_fn" "$svc: not running"
      continue
    fi
    state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || echo unknown)"
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo unknown)"
    if [ "$state" != "running" ]; then
      "$problem_fn" "$svc: container state is '$state'"
    elif [ "$health" = "unhealthy" ]; then
      "$problem_fn" "$svc: running but healthcheck reports unhealthy"
    elif [ "$health" = "starting" ]; then
      warn "$svc: running, healthcheck still starting"
    elif [ "$health" = "none" ]; then
      pass "$svc running (no healthcheck defined)"
    else
      pass "$svc running (health: $health)"
    fi
  done <<EOF
$ALL_SERVICES
EOF
fi

BACKEND_CID="$(cid_for "$BACKEND_SERVICE")"
FRONTEND_CID="$(cid_for "$FRONTEND_SERVICE")"
MYSQL_CID="$(cid_for "$MYSQL_SERVICE")"
REDIS_CID="$(cid_for "$REDIS_SERVICE")"

# ── 2 & 3. Application: backend readiness, frontend ───────────────────────
# Both run via `docker exec` — backend/frontend have no published host
# ports (removed deliberately, see production-readiness-audit.md), so a
# host-level curl would not reach either even when they're perfectly
# healthy. Uses the same tool each container's own Docker healthcheck
# already uses (curl for backend, wget for frontend — see
# docker-compose.prod.yml) rather than introducing a third assumption.

section "Application"

if [ -z "$BACKEND_CID" ]; then
  fail "Backend readiness: container not running (see Docker section above)"
else
  if docker exec "$BACKEND_CID" curl -fsS -m "$CURL_TIMEOUT_SECONDS" \
      "http://localhost:${BACKEND_PORT}${BACKEND_READYZ_PATH}" >/dev/null 2>&1; then
    pass "Backend ready ($BACKEND_READYZ_PATH)"
  else
    fail "Backend not ready ($BACKEND_READYZ_PATH did not respond successfully)"
  fi
fi

if [ -z "$FRONTEND_CID" ]; then
  fail "Frontend: container not running (see Docker section above)"
else
  if docker exec "$FRONTEND_CID" wget -q -T "$CURL_TIMEOUT_SECONDS" -O /dev/null \
      "http://127.0.0.1:${FRONTEND_PORT}${FRONTEND_PATH}" >/dev/null 2>&1; then
    pass "Frontend reachable ($FRONTEND_PATH)"
  else
    fail "Frontend not reachable ($FRONTEND_PATH)"
  fi
fi

# ── 4, 5, 8, 9. Infrastructure: MySQL, Redis, storage, LibreOffice ────────

section "Infrastructure"

if [ -z "$MYSQL_CID" ]; then
  fail "MySQL: container not running"
else
  if docker exec "$MYSQL_CID" sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysqladmin ping -h localhost -usuresign --silent' >/dev/null 2>&1; then
    pass "MySQL reachable"
  else
    fail "MySQL not reachable"
  fi
fi

if [ -z "$REDIS_CID" ]; then
  fail "Redis: container not running"
else
  if docker exec "$REDIS_CID" redis-cli ping 2>/dev/null | grep -q '^PONG$'; then
    pass "Redis reachable"
  else
    fail "Redis not reachable"
  fi
fi

if [ -z "$BACKEND_CID" ]; then
  fail "Storage: cannot check, backend container not running"
else
  if docker exec "$BACKEND_CID" sh -c 'test -d storage/app && test -w storage/app' >/dev/null 2>&1; then
    pass "Storage (storage/app) exists and is writable"
  else
    fail "Storage (storage/app) missing or not writable"
  fi

  if docker exec "$BACKEND_CID" sh -c 'test -L public/storage' >/dev/null 2>&1; then
    pass "Public storage symlink exists"
  else
    warn "Public storage symlink missing (public/storage) — branding/document URLs may 404; entrypoint.sh runs storage:link on next restart"
  fi

  # Same resolution order as DocxToPdfService::BINARIES — never a second,
  # independently-drifting opinion about what "LibreOffice is installed"
  # means.
  LIBRE_FOUND=""
  for bin in soffice libreoffice; do
    if docker exec "$BACKEND_CID" sh -c "command -v $bin" >/dev/null 2>&1; then
      LIBRE_FOUND="$bin"
      break
    fi
  done
  if [ -n "$LIBRE_FOUND" ]; then
    LIBRE_VER="$(docker exec "$BACKEND_CID" "$LIBRE_FOUND" --version 2>/dev/null | head -n1 || true)"
    pass "LibreOffice available ($LIBRE_FOUND${LIBRE_VER:+ — $LIBRE_VER})"
  else
    fail "LibreOffice not found (checked soffice, libreoffice) — document PDF conversion will fail"
  fi
fi

# ── 12. Docker volumes ─────────────────────────────────────────────────────
# Volume names read from the compose file's own top-level `volumes:` block,
# then resolved to the real (project-prefixed) volume via the
# com.docker.compose.volume label compose itself sets — never a guessed
# "<project>_<name>" string.

section "Persistent Volumes"

REQUIRED_VOLUMES="$($DC config --volumes 2>/dev/null || true)"
if [ -z "$REQUIRED_VOLUMES" ]; then
  warn "Could not read volume list from $COMPOSE_FILE"
else
  while IFS= read -r vol; do
    [ -n "$vol" ] || continue
    # Preferred: the label compose itself sets when it creates a volume.
    real_name="$(docker volume ls --filter "label=com.docker.compose.volume=$vol" --format '{{.Name}}' 2>/dev/null | head -n1 || true)"
    if [ -z "$real_name" ]; then
      # Fallback: a volume can genuinely exist without this label (e.g.
      # created by an older Compose version, or outside `docker compose up`
      # entirely) without that meaning the volume itself is missing — found
      # exactly this in practice against the real local stack while building
      # this script (suresign_backend_storage exists and is in active use,
      # but carries no compose labels at all). Match by name suffix instead
      # of assuming the label is always present.
      real_name="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep -E "(^|_)${vol}\$" | head -n1 || true)"
    fi
    if [ -n "$real_name" ]; then
      pass "Volume '$vol' exists ($real_name)"
    else
      fail "Volume '$vol' not found — data for this volume would not survive a container replacement"
    fi
  done <<EOF
$REQUIRED_VOLUMES
EOF
fi

# ── Operations: failed jobs, disk, logs, AI config, env, backups, SSL ────

section "Operations"

# 14. Failed queue jobs — a direct, read-only DB query (no artisan
# bootstrap needed) rather than parsing `queue:failed`'s table output.
if [ -z "$MYSQL_CID" ]; then
  warn "Failed jobs: cannot check, MySQL container not running"
else
  FAILED_COUNT="$(docker exec "$MYSQL_CID" sh -c \
    'MYSQL_PWD=$MYSQL_PASSWORD mysql -usuresign -N -e "SELECT COUNT(*) FROM suresign.failed_jobs"' 2>/dev/null || true)"
  if ! printf '%s' "$FAILED_COUNT" | grep -qE '^[0-9]+$'; then
    warn "Failed jobs: could not read failed_jobs count"
  elif [ "$FAILED_COUNT" -ge "$FAILED_JOBS_WARN_THRESHOLD" ]; then
    warn "Failed jobs: $FAILED_COUNT (not cleared or retried by this script — see production-operations.md's Queue Operations)"
  else
    pass "Failed jobs: $FAILED_COUNT"
  fi
fi

# 11. Disk usage.
DF_LINE="$(df -P "$DISK_PATH" 2>/dev/null | tail -n1 || true)"
if [ -z "$DF_LINE" ]; then
  warn "Disk usage: could not read df output for $DISK_PATH"
else
  DISK_PCT="$(printf '%s' "$DF_LINE" | awk '{gsub("%","",$5); print $5}' || true)"
  DISK_AVAIL_KB="$(printf '%s' "$DF_LINE" | awk '{print $4}' || true)"
  if printf '%s' "$DISK_PCT" | grep -qE '^[0-9]+$'; then
    if [ "$DISK_PCT" -ge "$DISK_CRIT_PCT" ]; then
      fail "Disk usage: ${DISK_PCT}% on $DISK_PATH (critical threshold ${DISK_CRIT_PCT}%), ${DISK_AVAIL_KB}KB free"
    elif [ "$DISK_PCT" -ge "$DISK_WARN_PCT" ]; then
      warn "Disk usage: ${DISK_PCT}% on $DISK_PATH, ${DISK_AVAIL_KB}KB free"
    else
      pass "Disk usage: ${DISK_PCT}% on $DISK_PATH, ${DISK_AVAIL_KB}KB free"
    fi
  else
    warn "Disk usage: could not parse df output for $DISK_PATH"
  fi
fi

# 13. Laravel logs — existence, and whether the live container's own
# LOG_STACK actually includes stderr (this doubles as half of check 18:
# does the running deployment match what production-operations.md says).
if [ -z "$BACKEND_CID" ]; then
  warn "Laravel logs: cannot check, backend container not running"
else
  if docker exec "$BACKEND_CID" sh -c 'test -d storage/logs' >/dev/null 2>&1; then
    pass "storage/logs exists"
  else
    fail "storage/logs missing inside the backend container"
  fi

  LIVE_LOG_STACK="$(docker exec "$BACKEND_CID" sh -c 'printenv LOG_STACK' 2>/dev/null || true)"
  case "$LIVE_LOG_STACK" in
    *stderr*)
      pass "LOG_STACK includes stderr ('$LIVE_LOG_STACK') — application errors reach 'docker logs'"
      ;;
    *)
      warn "LOG_STACK is '${LIVE_LOG_STACK:-unset}', not including stderr — 'docker logs' will not show Laravel errors, which contradicts production-operations.md's Logging section"
      ;;
  esac
fi

# 10. AI configuration — presence only, never the value. Note the caveat:
# actual AI enablement is admin/DB-controlled (suresign_settings), not
# purely env — see CLAUDE.md's AI Workflow Context. This only checks the
# env-level provider key some code paths fall back to.
if [ -z "$BACKEND_CID" ]; then
  warn "AI configuration: cannot check, backend container not running"
else
  AI_KEY_PRESENT="false"
  for var in ANTHROPIC_API_KEY OPENAI_API_KEY; do
    val="$(docker exec "$BACKEND_CID" sh -c "printenv $var" 2>/dev/null || true)"
    if [ -n "$val" ]; then
      AI_KEY_PRESENT="true"
    fi
  done
  if [ "$AI_KEY_PRESENT" = "true" ]; then
    pass "AI provider API key configured (env)"
  else
    info "No AI provider API key set via environment (AI is enabled/configured per-organisation in suresign_settings, not purely via env — this is informational, not a failure)"
  fi
fi

# 15. Environment validation — derived entirely from docker compose's own
# "variable is not set" warnings, i.e. every ${VAR} the compose file
# references with no default. No second "required variables" list
# maintained here to drift out of sync with the compose file.
MISSING_VARS="$($DC config 2>&1 >/dev/null | grep -oE '"[A-Za-z_][A-Za-z0-9_]*" variable is not set' | sed -E 's/"([A-Za-z_][A-Za-z0-9_]*)".*/\1/' | sort -u || true)"
if [ -z "$MISSING_VARS" ]; then
  pass "All environment variables referenced by $COMPOSE_FILE are set"
else
  MISSING_LIST="$(printf '%s' "$MISSING_VARS" | tr '\n' ' ')"
  fail "Missing required environment variables: $MISSING_LIST"
fi

# 17. Backup presence — existence/executability only, never runs a backup
# or restore.
if [ -x "$BACKUP_SCRIPT" ]; then
  pass "Backup script present and executable ($BACKUP_SCRIPT)"
else
  fail "Backup script missing or not executable ($BACKUP_SCRIPT)"
fi
if [ -x "$RESTORE_SCRIPT" ]; then
  pass "Restore script present and executable ($RESTORE_SCRIPT)"
else
  fail "Restore script missing or not executable ($RESTORE_SCRIPT)"
fi
if [ -d "$BACKUP_DIR" ]; then
  LATEST_BACKUP="$(find "$BACKUP_DIR" -maxdepth 1 -name '*.sql.gz' -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -n1 | cut -d' ' -f2- || true)"
  if [ -n "$LATEST_BACKUP" ]; then
    info "Most recent local DB backup: $(basename "$LATEST_BACKUP")"
  else
    warn "No .sql.gz backup found in $BACKUP_DIR — has a backup ever been run on this host?"
  fi
else
  info "No local backup directory ($BACKUP_DIR) — backups may be stored off-host only, which production-operations.md recommends; not a failure on its own"
fi

# 16. SSL / domain — best effort, opt-in only (see PRODUCTION_DOMAIN above).
# SSL terminates at Cloudflare/Dokploy's Traefik, entirely outside this
# repo (see production-operations.md) — this cannot be checked from inside
# any SureSign container, only externally against the real domain.
if [ -z "$PRODUCTION_DOMAIN" ]; then
  info "SSL/domain expiry: skipped (set PRODUCTION_DOMAIN to check, e.g. PRODUCTION_DOMAIN=app.suresigncontracts.app) — see production-operations.md, this is a documented future enhancement, not a silent gap"
elif ! command -v openssl >/dev/null 2>&1; then
  warn "SSL/domain expiry: openssl not available on this host, cannot check"
else
  CERT_END="$(printf '' | openssl s_client -connect "${PRODUCTION_DOMAIN}:443" -servername "$PRODUCTION_DOMAIN" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | sed -e 's/^notAfter=//' || true)"
  if [ -z "$CERT_END" ]; then
    warn "SSL/domain expiry: could not retrieve certificate for $PRODUCTION_DOMAIN"
  else
    CERT_END_EPOCH="$(date -d "$CERT_END" +%s 2>/dev/null || true)"
    NOW_EPOCH="$(date +%s)"
    if [ -n "$CERT_END_EPOCH" ]; then
      DAYS_LEFT="$(( (CERT_END_EPOCH - NOW_EPOCH) / 86400 ))"
      if [ "$DAYS_LEFT" -lt 14 ]; then
        fail "SSL certificate for $PRODUCTION_DOMAIN expires in $DAYS_LEFT days ($CERT_END)"
      elif [ "$DAYS_LEFT" -lt 30 ]; then
        warn "SSL certificate for $PRODUCTION_DOMAIN expires in $DAYS_LEFT days ($CERT_END)"
      else
        pass "SSL certificate for $PRODUCTION_DOMAIN valid for $DAYS_LEFT more days"
      fi
    else
      warn "SSL/domain expiry: could not parse certificate expiry date ($CERT_END)"
    fi
  fi
fi

# 18. Production Operations Consistency — concrete, verifiable cross-checks
# only (does the doc still mention every deployed service); deliberately
# does not attempt to judge documentation quality or completeness beyond
# that.
if [ -f "$OPS_DOC" ]; then
  UNDOCUMENTED=""
  while IFS= read -r svc; do
    [ -n "$svc" ] || continue
    grep -q "$svc" "$OPS_DOC" || UNDOCUMENTED="$UNDOCUMENTED $svc"
  done <<EOF
$ALL_SERVICES
EOF
  if [ -z "$UNDOCUMENTED" ]; then
    pass "$OPS_DOC mentions every service defined in $COMPOSE_FILE"
  else
    warn "$OPS_DOC does not mention:$UNDOCUMENTED — deployment may have drifted from documentation"
  fi
else
  warn "$OPS_DOC not found — cannot cross-check the deployment against documented operations"
fi

# ── Summary ────────────────────────────────────────────────────────────

section "Health Summary"
printf 'Passed:   %d\n' "$PASS_COUNT"
printf 'Warnings: %d\n' "$WARN_COUNT"
printf 'Failed:   %d\n' "$FAIL_COUNT"

section "Overall Status"
if [ "$FAIL_COUNT" -gt 0 ]; then
  printf '%s%sUNHEALTHY%s\n' "$C_BOLD" "$C_RED" "$C_RESET"
  exit 2
elif [ "$WARN_COUNT" -gt 0 ]; then
  printf '%s%sHEALTHY (with warnings)%s\n' "$C_BOLD" "$C_YELLOW" "$C_RESET"
  exit 1
else
  printf '%s%sHEALTHY%s\n' "$C_BOLD" "$C_GREEN" "$C_RESET"
  exit 0
fi
