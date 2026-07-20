# ops/

Operational scripts for SureSign production — run these on the host that
runs `docker compose` (currently the Hetzner CX33 via Dokploy), from the
repo root. See [`production-operations.md`](../production-operations.md) for
the full runbooks each of these fits into; this file is just a map of what's
here.

```
ops/
├── backup.sh          Back up the MySQL database and backend_storage volume
├── restore.sh         Reverse backup.sh (destructive; requires confirmation)
├── healthcheck.sh     "Is SureSign healthy?" — fast, PASS/WARN/FAIL, exit code 0/1/2
├── diagnostics.sh     "If not, why?" — deeper, read-only evidence collection
├── lib/
│   └── common.sh      Shared config/helpers for healthcheck.sh and diagnostics.sh
│                       (sourced, not run directly)
├── reports/           Timestamped diagnostics.sh output (gitignored contents;
│                       the directory itself is tracked via .gitkeep)
└── README.md          This file
```

## Quick reference

| Situation | Run |
|---|---|
| After a deploy, rollback, reboot, or infra change | `./ops/healthcheck.sh` |
| Healthcheck came back WARNING or UNHEALTHY | `./ops/diagnostics.sh` |
| Before/after a disaster-recovery restore | `./ops/backup.sh`, then `./ops/restore.sh` |
| Routine backup | `./ops/backup.sh [destination-dir]` (not yet on a schedule — see production-operations.md) |

## Safety

- `backup.sh` and `diagnostics.sh` are read-only — safe to run at any time,
  as often as you like.
- `healthcheck.sh` is read-only.
- `restore.sh` is **destructive** — overwrites the current database and
  storage volume. Requires typing `yes` to confirm.
- None of these scripts restart a service, retry a job, clear a cache, or
  print a secret value.
