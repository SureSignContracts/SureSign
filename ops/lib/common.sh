#!/usr/bin/env bash
# Shared helpers for ops/healthcheck.sh and ops/diagnostics.sh.
#
# Sourced, never executed directly — this file assumes the caller has
# already done `set -euo pipefail`. Holds exactly the deployment knowledge
# that both scripts would otherwise duplicate: where the compose file is,
# what the services are called, how to resolve a service to a container,
# and how to tell a critical service from an independent one. Generic
# output formatting each script needs is not force-fit in here — see the
# note above each script's own pass()/warn()/fail() equivalents.
#
# Usage: source "$(dirname "$0")/lib/common.sh"

# ── Configuration — the one place these are defined; healthcheck.sh and
#    diagnostics.sh both source this instead of maintaining their own copy.

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"

BACKEND_SERVICE="${BACKEND_SERVICE:-backend}"
FRONTEND_SERVICE="${FRONTEND_SERVICE:-frontend}"
MYSQL_SERVICE="${MYSQL_SERVICE:-mysql}"
REDIS_SERVICE="${REDIS_SERVICE:-redis}"
QUEUE_SERVICE="${QUEUE_SERVICE:-queue}"
SCHEDULER_SERVICE="${SCHEDULER_SERVICE:-scheduler}"
NGINX_SERVICE="${NGINX_SERVICE:-nginx}"

# Services whose downtime means SureSign itself is down (see
# healthcheck.sh's fuller explanation, not repeated here) — everything else
# in $COMPOSE_FILE (docs, marketing) is still checked, just not treated as
# critical to SureSign's own availability.
CORE_SERVICES="${CORE_SERVICES:-backend frontend mysql redis queue scheduler nginx}"

BACKEND_PORT="${BACKEND_PORT:-8000}"
BACKEND_READYZ_PATH="${BACKEND_READYZ_PATH:-/readyz}"
FRONTEND_PORT="${FRONTEND_PORT:-3000}"
FRONTEND_PATH="${FRONTEND_PATH:-/login}"
CURL_TIMEOUT_SECONDS="${CURL_TIMEOUT_SECONDS:-5}"

OPS_DOC="${OPS_DOC:-production-operations.md}"
BACKUP_SCRIPT="${BACKUP_SCRIPT:-ops/backup.sh}"
RESTORE_SCRIPT="${RESTORE_SCRIPT:-ops/restore.sh}"
BACKUP_DIR="${BACKUP_DIR:-backups}"

# ── Colour support ─────────────────────────────────────────────────────

if [ -t 1 ] && [ "${TERM:-dumb}" != "dumb" ]; then
  C_GREEN=$'\033[0;32m'; C_YELLOW=$'\033[0;33m'; C_RED=$'\033[0;31m'
  C_BLUE=$'\033[0;34m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
  C_GREEN=''; C_YELLOW=''; C_RED=''; C_BLUE=''; C_BOLD=''; C_RESET=''
fi

section() { printf '\n%s%s%s\n' "$C_BOLD" "$1" "$C_RESET"; }

# ── Docker / Compose resolution ────────────────────────────────────────

require_docker() {
  # Sets the global $DC to the working "docker compose"/"docker-compose"
  # invocation (already pointed at $COMPOSE_FILE), or exits 2 with a clear
  # message. This is the one deliberate early-exit in either script: if the
  # tool every other check depends on isn't there, nothing else is
  # checkable, not just one failed line item.
  if ! command -v docker >/dev/null 2>&1; then
    printf '%s\xe2\x9c\x97 docker is not installed or not on PATH.%s\n' "$C_RED" "$C_RESET"
    exit 2
  fi

  if docker compose version >/dev/null 2>&1; then
    DC="docker compose -f $COMPOSE_FILE"
  elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose -f $COMPOSE_FILE"
  else
    printf '%s\xe2\x9c\x97 Neither "docker compose" nor "docker-compose" is available.%s\n' "$C_RED" "$C_RESET"
    exit 2
  fi

  if [ ! -f "$COMPOSE_FILE" ]; then
    printf '%s\xe2\x9c\x97 %s not found in the current directory — run this from the repo root.%s\n' "$C_RED" "$COMPOSE_FILE" "$C_RESET"
    exit 2
  fi
}

cid_for() {
  # Container ID for a compose service name, or empty string if not
  # running/not defined. `|| true` is deliberate: `docker compose ps -q` on
  # an undefined service exits non-zero, and under set -e/pipefail in a
  # plain assignment (not inside an `if`) that would abort the whole script
  # — found by actually running healthcheck.sh against a wrong service name
  # while building it, not by reasoning about it in the abstract.
  $DC ps -q "$1" 2>/dev/null | head -n1 || true
}

is_core_service() {
  case " $CORE_SERVICES " in
    *" $1 "*) return 0 ;;
    *) return 1 ;;
  esac
}
