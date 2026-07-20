#!/usr/bin/env bash
#
# SureSign Operational Diagnostics
#
# Complements ops/healthcheck.sh: that script answers "is SureSign healthy"
# fast, with PASS/WARN/FAIL. This one answers "if not, why" — it collects
# the operational evidence a first-level investigation normally needs by
# hand, so an operator who just saw UNHEALTHY or WARNING can run one command
# instead of manually re-running a dozen `docker exec`/`docker logs`/`df`
# commands themselves.
#
# STRICTLY READ-ONLY. This script must never (and does not):
#   - modify production data
#   - restart, stop, or recreate any container
#   - retry or clear any queue job
#   - clear any cache
#   - print a secret value
#
# Deployment knowledge (compose file location, service names, container
# resolution) is shared with healthcheck.sh via ops/lib/common.sh, not
# duplicated here.
#
# Usage: ./ops/diagnostics.sh
# Writes console output AND a timestamped, colour-stripped copy to
# ops/reports/diagnostics-<UTC timestamp>.txt (see production-operations.md's
# Diagnostics section for how to read it / attach it to an incident).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

REPORT_DIR="${REPORT_DIR:-ops/reports}"
LOG_TAIL_LINES="${LOG_TAIL_LINES:-40}"
DISK_PATH="${DISK_PATH:-/}"
DISK_WARN_PCT="${DISK_WARN_PCT:-80}"

require_docker
mkdir -p "$REPORT_DIR"

REPORT_FILE="$REPORT_DIR/diagnostics-$(date -u +%Y-%m-%d-%H%M%S).txt"

# Colour decisions (in common.sh) are made against the REAL terminal before
# this redirection exists — tee duplicates the coloured stream to the
# terminal as-is, but the copy landing in the report file has ANSI escape
# codes stripped by the nested sed, so the saved report is plain, readable
# text suitable for pasting into an incident ticket.
exec > >(tee >(sed -E 's/\x1b\[[0-9;]*m//g' > "$REPORT_FILE")) 2>&1

# ── Evidence helpers (deliberately not pass/fail — see the design note
#    above; this script reports what it found, then reasons cautiously
#    about it once, in the Summary section, not per line). ────────────────

note()  { printf '  %s\n' "$1"; }
kv()    { printf '  %-32s %s\n' "$1:" "${2:-unknown}"; }
warn()  { printf '  %s\xe2\x9a\xa0%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
miss()  { printf '  %s\xe2\x80\x94%s %s\n' "$C_BLUE" "$C_RESET" "$1"; }

ISSUES=()
record_issue() { ISSUES+=("$1"); }

# `docker exec` into a service, or print why not and return 1 — every
# section below is guarded with `if in_container ...; then ... fi` so a
# down container degrades one section's detail, never the whole script.
exec_in() {
  local cid="$1"; shift
  [ -n "$cid" ] || return 1
  docker exec "$cid" "$@" 2>/dev/null
}

BACKEND_CID="$(cid_for "$BACKEND_SERVICE")"
FRONTEND_CID="$(cid_for "$FRONTEND_SERVICE")"
MYSQL_CID="$(cid_for "$MYSQL_SERVICE")"
REDIS_CID="$(cid_for "$REDIS_SERVICE")"
QUEUE_CID="$(cid_for "$QUEUE_SERVICE")"
SCHEDULER_CID="$(cid_for "$SCHEDULER_SERVICE")"
NGINX_CID="$(cid_for "$NGINX_SERVICE")"

ALL_SERVICES="$($DC config --services 2>/dev/null || true)"

# ==========================================================================
printf '%s==================================================%s\n' "$C_BOLD" "$C_RESET"
printf '%sSureSign Production Diagnostics%s\n' "$C_BOLD" "$C_RESET"
printf 'Generated:      %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S UTC')"
printf 'Hostname:       %s\n' "$(hostname 2>/dev/null || echo unknown)"
printf 'Docker Context: %s\n' "$(docker context show 2>/dev/null || echo unknown)"
printf 'Report file:    %s\n' "$REPORT_FILE"
printf '%s==================================================%s\n' "$C_BOLD" "$C_RESET"

# ── 1. Docker ──────────────────────────────────────────────────────────
section "1. Docker"
kv "Docker version" "$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo unknown)"
kv "Compose version" "$($DC version --short 2>/dev/null || echo unknown)"
kv "Context" "$(docker context show 2>/dev/null || echo unknown)"
note ""
note "Running containers:"
docker ps --format '  {{.Names}}\t{{.Status}}\t{{.Image}}' 2>/dev/null || note "  (could not list containers)"
note ""
note "Recent container exits (last 5, any container, any time):"
docker ps -a --filter status=exited --format '  {{.Names}}\texited {{.Status}}' 2>/dev/null | head -n5 | sed 's/^/  /' || true

# ── 2. Containers (per SureSign service) ──────────────────────────────
section "2. Container Details"
if [ -z "$ALL_SERVICES" ]; then
  warn "Could not read service list from $COMPOSE_FILE"
else
  while IFS= read -r svc; do
    [ -n "$svc" ] || continue
    cid="$(cid_for "$svc")"
    note ""
    note "-- $svc --"
    if [ -z "$cid" ]; then
      miss "not running"
      continue
    fi
    kv "  Container ID" "${cid:0:12}"
    kv "  Image" "$(docker inspect -f '{{.Config.Image}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Status" "$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Health" "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Restart count" "$(docker inspect -f '{{.RestartCount}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Started at" "$(docker inspect -f '{{.State.StartedAt}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Networks" "$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Mounts" "$(docker inspect -f '{{range .Mounts}}{{.Destination}} {{end}}' "$cid" 2>/dev/null || echo unknown)"
    # Environment summary: a count only, never names or values — backend,
    # queue, and scheduler share nearly the same ~60-variable env block
    # (see docker-compose.prod.yml's *backend_env anchor), so listing every
    # name three times over would be noise, not a summary. Section 3
    # (Application) already reports the handful of these that actually
    # matter for diagnosis individually.
    env_count="$(docker inspect -f '{{len .Config.Env}}' "$cid" 2>/dev/null || echo unknown)"
    kv "  Environment variables set" "$env_count"
    restart_count="$(docker inspect -f '{{.RestartCount}}' "$cid" 2>/dev/null || echo 0)"
    if printf '%s' "$restart_count" | grep -qE '^[0-9]+$' && [ "$restart_count" -ge 3 ]; then
      record_issue "Container '$svc' has restarted $restart_count times — possible cause: crash loop, failing healthcheck, or OOM kill (see section 12)."
    fi
  done <<EOF
$ALL_SERVICES
EOF
fi

# ── 3. Backend / Application ──────────────────────────────────────────
section "3. Application"
if [ -z "$BACKEND_CID" ]; then
  miss "Backend container not running — cannot collect application details"
  record_issue "Backend container is not running — no application-level diagnosis possible until it is."
else
  kv "PHP version" "$(exec_in "$BACKEND_CID" php -r 'echo PHP_VERSION;' || echo unknown)"
  kv "Laravel version" "$(exec_in "$BACKEND_CID" php artisan --version || echo unknown)"
  kv "APP_ENV" "$(exec_in "$BACKEND_CID" printenv APP_ENV || echo unknown)"
  kv "LOG_STACK" "$(exec_in "$BACKEND_CID" printenv LOG_STACK || echo unknown)"
  kv "CACHE_STORE" "$(exec_in "$BACKEND_CID" printenv CACHE_STORE || echo unknown)"
  kv "QUEUE_CONNECTION" "$(exec_in "$BACKEND_CID" printenv QUEUE_CONNECTION || echo unknown)"
  kv "SESSION_DRIVER" "$(exec_in "$BACKEND_CID" printenv SESSION_DRIVER || echo unknown)"
  kv "APP_TIMEZONE" "$(exec_in "$BACKEND_CID" printenv APP_TIMEZONE || echo unknown)"

  if exec_in "$BACKEND_CID" sh -c 'test -L public/storage' >/dev/null 2>&1; then
    kv "Public storage symlink" "present"
  else
    kv "Public storage symlink" "MISSING"
    record_issue "public/storage symlink is missing — branding/document URLs will 404 until the next container start runs storage:link."
  fi

  live_log_stack="$(exec_in "$BACKEND_CID" printenv LOG_STACK || true)"
  case "$live_log_stack" in
    *stderr*) : ;;
    *) record_issue "LOG_STACK ('${live_log_stack:-unset}') does not include stderr — 'docker logs' will not show Laravel application errors, only container lifecycle events." ;;
  esac
fi

# ── 4. Database ────────────────────────────────────────────────────────
section "4. Database"
if [ -z "$MYSQL_CID" ]; then
  miss "MySQL container not running — cannot collect database details"
  record_issue "MySQL container is not running — every dependent service (backend/queue/scheduler) is likely degraded because of this."
else
  myq() { exec_in "$MYSQL_CID" sh -c "MYSQL_PWD=\$MYSQL_PASSWORD mysql -usuresign -N -e \"$1\"" 2>/dev/null || true; }

  kv "MySQL version" "$(myq 'SELECT VERSION()')"
  kv "Database size (MB)" "$(myq "SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables WHERE table_schema='suresign'")"
  kv "Table count" "$(myq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='suresign'")"

  note ""
  note "Largest tables (top 5 by size):"
  myq "SELECT CONCAT(table_name, ': ', ROUND((data_length+index_length)/1024/1024,1), ' MB') FROM information_schema.tables WHERE table_schema='suresign' ORDER BY (data_length+index_length) DESC LIMIT 5" | sed 's/^/  /'

  note ""
  note "Migration status:"
  pending="$(exec_in "$BACKEND_CID" php artisan migrate:status --pending 2>/dev/null || true)"
  if [ -n "$pending" ] && ! printf '%s' "$pending" | grep -qi "no pending migrations"; then
    printf '%s\n' "$pending" | sed 's/^/  /'
    record_issue "There are pending migrations that have not been run against this database — see 'Migration status' above. Run the documented migrate step (production-operations.md) if this deploy included a schema change."
  else
    note "  No pending migrations detected (or artisan migrate:status --pending is not supported by this Laravel version — treat as inconclusive, not confirmed clean)."
  fi
fi

# ── 5. Redis ────────────────────────────────────────────────────────────
section "5. Redis"
if [ -z "$REDIS_CID" ]; then
  miss "Redis container not running — cannot collect Redis details"
  record_issue "Redis container is not running — cache, rate limiting, and scheduler locks are all degraded (not data loss; MySQL remains authoritative — see production-readiness-audit.md)."
else
  kv "Redis version" "$(exec_in "$REDIS_CID" redis-cli info server 2>/dev/null | grep '^redis_version:' | cut -d: -f2 | tr -d '\r')"
  kv "Memory used" "$(exec_in "$REDIS_CID" redis-cli info memory 2>/dev/null | grep '^used_memory_human:' | cut -d: -f2 | tr -d '\r')"
  kv "Connected clients" "$(exec_in "$REDIS_CID" redis-cli info clients 2>/dev/null | grep '^connected_clients:' | cut -d: -f2 | tr -d '\r')"
  kv "Persistence (rdb last save)" "$(exec_in "$REDIS_CID" redis-cli info persistence 2>/dev/null | grep '^rdb_last_save_time:' | cut -d: -f2 | tr -d '\r')"
  kv "Key count (current DB)" "$(exec_in "$REDIS_CID" redis-cli dbsize 2>/dev/null | tr -d '\r')"

  mem_pct="$(exec_in "$REDIS_CID" redis-cli info memory 2>/dev/null | grep '^used_memory_rss_human:' | cut -d: -f2 | tr -d '\r' || true)"
  [ -n "$mem_pct" ] && kv "Memory (RSS)" "$mem_pct"
fi

# ── 6. Queue ────────────────────────────────────────────────────────────
section "6. Queue"
kv "QUEUE_CONNECTION (backend env)" "$(exec_in "$BACKEND_CID" printenv QUEUE_CONNECTION || echo unknown)"
if [ -z "$QUEUE_CID" ]; then
  miss "Queue worker container not running"
  record_issue "Queue worker container is not running — queued jobs (AI analysis, notifications) are not being processed at all."
else
  kv "Worker container status" "$(docker inspect -f '{{.State.Status}}' "$QUEUE_CID" 2>/dev/null || echo unknown)"
  kv "Worker health" "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$QUEUE_CID" 2>/dev/null || echo unknown)"
  kv "Worker started at" "$(docker inspect -f '{{.State.StartedAt}}' "$QUEUE_CID" 2>/dev/null || echo unknown)"
  worker_running="$(exec_in "$QUEUE_CID" sh -c "pgrep -f 'artisan queue:work'" | head -n1 || true)"
  if [ -n "$worker_running" ]; then
    kv "queue:work process" "running (pid $worker_running)"
  else
    kv "queue:work process" "NOT FOUND"
    record_issue "Queue container is running but no 'artisan queue:work' process was found inside it — possible cause: the worker process crashed without the container exiting."
  fi

  if [ -n "$MYSQL_CID" ]; then
    failed_count="$(exec_in "$MYSQL_CID" sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysql -usuresign -N -e "SELECT COUNT(*) FROM suresign.failed_jobs"' || true)"
    kv "Failed jobs count" "${failed_count:-unknown}"
    if printf '%s' "$failed_count" | grep -qE '^[0-9]+$' && [ "$failed_count" -gt 0 ]; then
      note ""
      note "Most recent failed jobs (up to 5, queue/exception summary only):"
      exec_in "$MYSQL_CID" sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysql -usuresign -N -e "SELECT failed_at, queue, LEFT(exception,120) FROM suresign.failed_jobs ORDER BY failed_at DESC LIMIT 5"' | sed 's/^/  /' || true
      record_issue "There are $failed_count failed job(s) — see the list above (not retried or cleared by this script)."
    fi
  else
    miss "Cannot read failed_jobs — MySQL container not running"
  fi
fi

# ── 7. Scheduler ────────────────────────────────────────────────────────
section "7. Scheduler"
if [ -z "$SCHEDULER_CID" ]; then
  miss "Scheduler container not running"
  record_issue "Scheduler container is not running — suresign:send-deadline-reminders and calendar:sync are not ticking."
else
  kv "Container status" "$(docker inspect -f '{{.State.Status}}' "$SCHEDULER_CID" 2>/dev/null || echo unknown)"
  kv "Health" "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$SCHEDULER_CID" 2>/dev/null || echo unknown)"
  sched_running="$(exec_in "$SCHEDULER_CID" sh -c "pgrep -f 'artisan schedule:work'" | head -n1 || true)"
  if [ -n "$sched_running" ]; then
    kv "schedule:work process" "running (pid $sched_running)"
  else
    kv "schedule:work process" "NOT FOUND"
    record_issue "Scheduler container is running but no 'artisan schedule:work' process was found inside it."
  fi
  note ""
  note "Recent scheduled-task activity is not exposed via a dedicated log today —"
  note "cross-check section 13 (Logs) for 'suresign:send-deadline-reminders' or 'calendar:sync' entries."
fi

# ── 8. Storage ──────────────────────────────────────────────────────────
section "8. Storage"
kv "Host disk usage ($DISK_PATH)" "$(df -h / 2>/dev/null | tail -n1 | awk '{print $5" used, "$4" free"}' || echo unknown)"
if [ -z "$BACKEND_CID" ]; then
  miss "Backend container not running — cannot collect storage/app details"
else
  kv "storage/app permissions" "$(exec_in "$BACKEND_CID" sh -c 'stat -c "%A %U:%G" storage/app 2>/dev/null || stat -f "%Sp %Su:%Sg" storage/app' || echo unknown)"
  kv "storage/app size" "$(exec_in "$BACKEND_CID" du -sh storage/app 2>/dev/null | awk '{print $1}' || echo unknown)"
  note ""
  note "Largest directories under storage/app (top 5):"
  exec_in "$BACKEND_CID" sh -c 'du -sh storage/app/*/ 2>/dev/null | sort -rh | head -n5' | sed 's/^/  /' || note "  (could not enumerate)"
fi

# ── 9. LibreOffice ──────────────────────────────────────────────────────
section "9. LibreOffice"
if [ -z "$BACKEND_CID" ]; then
  miss "Backend container not running — cannot check LibreOffice"
else
  LIBRE_FOUND=""
  for bin in soffice libreoffice; do
    if exec_in "$BACKEND_CID" sh -c "command -v $bin" >/dev/null 2>&1; then
      LIBRE_FOUND="$bin"
      break
    fi
  done
  if [ -n "$LIBRE_FOUND" ]; then
    kv "Executable" "$LIBRE_FOUND"
    kv "Version" "$(exec_in "$BACKEND_CID" "$LIBRE_FOUND" --version | head -n1 || echo unknown)"
  else
    kv "Executable" "NOT FOUND (checked soffice, libreoffice)"
    record_issue "LibreOffice is not available inside the backend container — document PDF conversion will fail for every request."
  fi
fi

# ── 10. AI ──────────────────────────────────────────────────────────────
section "10. AI"
if [ -z "$BACKEND_CID" ]; then
  miss "Backend container not running — cannot check AI configuration"
else
  ai_key_present="false"
  ai_provider="none"
  for var in ANTHROPIC_API_KEY OPENAI_API_KEY; do
    val="$(exec_in "$BACKEND_CID" printenv "$var" || true)"
    if [ -n "$val" ]; then
      ai_key_present="true"
      ai_provider="$var"
    fi
  done
  kv "Provider API key present (env)" "$ai_key_present"
  [ "$ai_provider" != "none" ] && kv "Provider variable set" "$ai_provider"
  kv "AI_MAX_TOKENS" "$(exec_in "$BACKEND_CID" printenv AI_MAX_TOKENS || echo unset)"
  note "  (AI is actually enabled/configured per-organisation in suresign_settings — this only reflects the env-level fallback key, never the value itself, never the DB toggle.)"
fi

# ── 11. Networking ──────────────────────────────────────────────────────
section "11. Networking"
note "Docker networks:"
docker network ls --format '  {{.Name}}\t{{.Driver}}' 2>/dev/null || true
note ""
note "Published ports (host-exposed only):"
docker ps --format '  {{.Names}}\t{{.Ports}}' 2>/dev/null | grep -v $'\t$' | sed 's/^/  /' || note "  (none reported)"

if [ -n "$BACKEND_CID" ]; then
  if exec_in "$BACKEND_CID" sh -c "getent hosts $REDIS_SERVICE" >/dev/null 2>&1; then
    kv "Backend -> Redis DNS" "resolves"
  else
    kv "Backend -> Redis DNS" "DOES NOT RESOLVE"
    record_issue "Backend cannot resolve the '$REDIS_SERVICE' hostname on the internal network — possible cause: network misconfiguration or Redis container not attached to the expected network."
  fi
  if exec_in "$BACKEND_CID" sh -c "getent hosts $MYSQL_SERVICE" >/dev/null 2>&1; then
    kv "Backend -> MySQL DNS" "resolves"
  else
    kv "Backend -> MySQL DNS" "DOES NOT RESOLVE"
    record_issue "Backend cannot resolve the '$MYSQL_SERVICE' hostname on the internal network."
  fi
fi

# ── 12. Resources ────────────────────────────────────────────────────────
section "12. Resources"
kv "Host disk usage" "$(df -h / 2>/dev/null | tail -n1 | awk '{print $5}' || echo unknown)"
note ""
note "Per-container resource usage (one-shot snapshot, not a time series):"
docker stats --no-stream --format '  {{.Name}}\tCPU {{.CPUPerc}}\tMEM {{.MemPerc}} ({{.MemUsage}})' 2>/dev/null || note "  (docker stats unavailable)"

disk_pct="$(df -P / 2>/dev/null | tail -n1 | awk '{gsub("%","",$5); print $5}' || true)"
if printf '%s' "$disk_pct" | grep -qE '^[0-9]+$' && [ "$disk_pct" -ge "$DISK_WARN_PCT" ]; then
  record_issue "Host disk usage is ${disk_pct}%, at or above the ${DISK_WARN_PCT}% warning threshold."
fi

# ── 13. Logs ──────────────────────────────────────────────────────────────
section "13. Logs"
note "Last $LOG_TAIL_LINES lines per service (container stdout/stderr — see"
note "production-operations.md's Logging section for what does/doesn't reach here):"

for svc_pair in "backend:$BACKEND_CID" "queue:$QUEUE_CID" "scheduler:$SCHEDULER_CID" "nginx:$NGINX_CID"; do
  svc="${svc_pair%%:*}"; cid="${svc_pair##*:}"
  note ""
  note "-- $svc --"
  if [ -z "$cid" ]; then
    miss "not running, no logs to show"
    continue
  fi
  docker logs --tail "$LOG_TAIL_LINES" "$cid" 2>&1 | sed 's/^/  /' || note "  (could not read logs)"
done

if [ -n "$BACKEND_CID" ]; then
  note ""
  note "-- LibreOffice conversion failures (from backend logs, if any) --"
  docker logs --tail 200 "$BACKEND_CID" 2>&1 | grep -i "libreoffice\|conversion failed" | tail -n "$LOG_TAIL_LINES" | sed 's/^/  /' || note "  none found in the last 200 lines"

  note ""
  note "-- AI job failures (from backend logs, if any) --"
  docker logs --tail 200 "$BACKEND_CID" 2>&1 | grep -i "AnalyseContractWithAiJob failed\|AnalyseTradePackageWithAiJob failed" | tail -n "$LOG_TAIL_LINES" | sed 's/^/  /' || note "  none found in the last 200 lines"
fi

# ── 14. Recent Events ─────────────────────────────────────────────────────
section "14. Recent Events"
note "Container restart counts (non-zero only):"
if [ -n "$ALL_SERVICES" ]; then
  while IFS= read -r svc; do
    [ -n "$svc" ] || continue
    cid="$(cid_for "$svc")"
    [ -n "$cid" ] || continue
    rc="$(docker inspect -f '{{.RestartCount}}' "$cid" 2>/dev/null || echo 0)"
    if printf '%s' "$rc" | grep -qE '^[0-9]+$' && [ "$rc" -gt 0 ]; then
      note "  $svc: $rc restart(s)"
    fi
  done <<EOF
$ALL_SERVICES
EOF
fi
note ""
note "Most recent image build/deploy timestamp (per core container, if detectable):"
for svc in "$BACKEND_SERVICE" "$FRONTEND_SERVICE"; do
  cid="$(cid_for "$svc")"
  [ -n "$cid" ] || continue
  created="$(docker inspect -f '{{.Created}}' "$cid" 2>/dev/null || echo unknown)"
  note "  $svc container created: $created"
done
note "  (This is when the container was created, which is the closest reliable"
note "  signal to 'last deploy time' available without a dedicated deploy log —"
note "  not necessarily exact if the container was restarted without a rebuild.)"

# ── 15. Summary ───────────────────────────────────────────────────────────
section "15. Summary"

if [ "${#ISSUES[@]}" -eq 0 ]; then
  printf '\n%sNo obvious issue detected%s from the evidence collected above.\n' "$C_GREEN" "$C_RESET"
  printf 'This does not guarantee nothing is wrong — only that nothing in this\n'
  printf "script's specific checks stood out. Re-run ./ops/healthcheck.sh, and if\n"
  printf 'the symptom is still present, it may need evidence this script does not\n'
  printf 'collect yet — see production-operations.md.\n'
else
  printf '\n%sPotential Issues Detected%s (%d) — read as leads to investigate, not\n' "$C_BOLD" "$C_RESET" "${#ISSUES[@]}"
  printf 'confirmed root causes:\n\n'
  i=1
  for issue in "${ISSUES[@]}"; do
    printf '  %d. %s\n' "$i" "$issue"
    i=$((i + 1))
  done

  printf '\n%sRecommended Next Steps%s\n' "$C_BOLD" "$C_RESET"
  printf '  - Cross-check each item above against production-operations.md'"'"'s\n'
  printf '    relevant runbook (Deploy/Rollback/Queue/Database/Storage sections).\n'
  printf '  - This script changed nothing — safe to re-run after taking any action,\n'
  printf '    to confirm whether the evidence has changed.\n'
  printf '  - If a fix requires a restart, retry, or cache clear, that is a\n'
  printf '    deliberate operator decision — see the matching runbook, this script\n'
  printf '    will not do it for you.\n'
fi

printf '\nFull report saved to: %s\n' "$REPORT_FILE"
