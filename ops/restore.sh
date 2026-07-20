#!/bin/sh
# SureSign restore — reverses ops/backup.sh. Run on the target host (a fresh
# Hetzner server for a full Day 0 recovery, or the existing one to roll back
# to an earlier backup). See production-operations.md's "Day 0: Disaster
# Recovery" section for the full sequence this fits into — this script only
# does the data half; migrate/dev-server/reverse-proxy setup happen
# separately per that document.
#
# Usage: ./ops/restore.sh <db-dump.sql.gz> <storage-tar.gz>
#
# DESTRUCTIVE: overwrites the running suresign database and the
# backend_storage volume's current contents. Requires typing "yes" to
# confirm. Never run this against a database/volume you have not
# deliberately decided to overwrite.
set -eu

DB_DUMP="${1:-}"
STORAGE_TAR="${2:-}"

if [ -z "$DB_DUMP" ] || [ -z "$STORAGE_TAR" ]; then
  echo "Usage: $0 <db-dump.sql.gz> <storage-tar.gz>" >&2
  exit 1
fi
if [ ! -f "$DB_DUMP" ] || [ ! -f "$STORAGE_TAR" ]; then
  echo "Error: one or both input files not found." >&2
  exit 1
fi

echo "This will OVERWRITE the current suresign database and backend_storage"
echo "volume contents on this host with:"
echo "  DB dump:      $DB_DUMP"
echo "  Storage tar:  $STORAGE_TAR"
printf "Type 'yes' to continue: "
read -r CONFIRM
if [ "$CONFIRM" != "yes" ]; then
  echo "Aborted."
  exit 1
fi

echo "==> Restoring MySQL ..."
gunzip -c "$DB_DUMP" | docker exec -i suresign_mysql sh -c 'MYSQL_PWD=$MYSQL_PASSWORD mysql -usuresign suresign'
echo "    Database restored."

echo "==> Restoring backend_storage volume ..."
# Clears the volume first (-v ...:/data, not :/data:ro this time) so a
# restore onto a non-empty volume (e.g. re-running restore, or restoring
# onto a server that already ran migrate/dev once) doesn't leave stale files
# mixed in with the restored ones.
docker run --rm \
  -v suresign_backend_storage:/data \
  -v "$(cd "$(dirname "$STORAGE_TAR")" && pwd)":/backup \
  alpine sh -c "rm -rf /data/* /data/..?* /data/.[!.]* 2>/dev/null; tar -xzf /backup/$(basename "$STORAGE_TAR") -C /data"
echo "    Storage restored."

echo "==> Done. Next: run the deploy sequence in production-operations.md"
echo "    (build/pull images, docker compose up -d, then the migrate step)."
