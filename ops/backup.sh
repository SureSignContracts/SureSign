#!/bin/sh
# SureSign production backup — run on the Hetzner host (or via cron there),
# not inside a container. Backs up the two things that cannot be
# reconstructed from the git repo: the MySQL database and the
# backend_storage Docker volume (uploaded files, generated documents,
# branding assets). Everything else (images, config, code) is already
# reproducible from the repo + .env — see production-operations.md.
#
# Usage: ./ops/backup.sh [destination-dir]
# Default destination: ./backups relative to wherever this is run from.
#
# Does NOT delete old backups (no retention policy enforced here — see
# production-operations.md's Backup section for the recommended retention
# and off-host copy step, which this script deliberately does not do; a
# backup that only ever lives on the same disk as what it backs up is not
# a real backup against "the VPS burned down").
set -eu

DEST="${1:-./backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$DEST"

echo "==> Backing up MySQL (suresign_mysql) ..."
# MYSQL_PWD read from the container's own already-set env var at
# execution time (never passed on this host's command line or written to
# shell history) — same pattern already used by docker-compose.prod.yml's
# mysql healthcheck.
docker exec suresign_mysql sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysqldump -usuresign --single-transaction --routines --triggers --no-tablespaces suresign' \
  | gzip > "$DEST/suresign-db-$STAMP.sql.gz"
echo "    -> $DEST/suresign-db-$STAMP.sql.gz"

echo "==> Backing up backend_storage volume (uploads, generated documents, branding) ..."
# Runs a throwaway alpine container that mounts the same named volume
# read-only and tars it — never touches the running backend/queue/scheduler
# containers, so this is safe to run at any time without stopping anything.
docker run --rm \
  -v suresign_backend_storage:/data:ro \
  -v "$(cd "$DEST" && pwd)":/backup \
  alpine sh -c "tar -czf /backup/suresign-storage-$STAMP.tar.gz -C /data ."
echo "    -> $DEST/suresign-storage-$STAMP.tar.gz"

echo "==> Done. Copy both files off this host before trusting them as a real backup."
ls -lh "$DEST"/*"$STAMP"*
