# Production Deployment Notes

Focused, per-feature operational prerequisites that must be carried out
when deploying a specific SureSign feature to an environment with existing
data — not a general deployment runbook (see
[`demo-environment/deployment.md`](demo-environment/deployment.md) for the
demo-environment-specific infrastructure runbook; this file is for
feature-level data/migration prerequisites instead). Add a new section here
only when a feature genuinely introduces one — most features need nothing
here at all.

## Drawing Hotspot Authoring — legacy revision backfill (Drawing Phase 6)

**Applies to:** any environment that already has Drawing Register data from
before Drawing Revisions existed (Phase 4) — i.e. `drawings` rows created
before revision tracking was added, which have no row in `drawing_revisions`
and `drawings.current_revision_id IS NULL`.

**Why this matters:** Drawing Hotspot authoring (Phase 6A) and linking
(Phase 6B) are only available on a Drawing's **current revision** — a
hotspot always belongs to a real `DrawingRevision` row
(`drawing_hotspots.drawing_revision_id`), never to `Drawing.document_id` or
`Drawing::effectiveDocument()`'s legacy fallback. A Drawing with no revision
at all simply cannot receive a hotspot yet — the Viewer already surfaces
this honestly ("Add a drawing revision before recording drawing
locations."), but a real operator step is what actually resolves it for a
whole environment's worth of legacy Drawings at once.

**Before enabling/using Drawing Hotspot authoring in an environment
containing legacy Drawings:**

1. Apply the Drawing Revision migrations (`2026_08_31_000001_create_drawing_revisions_table`,
   `2026_08_31_000002_add_current_revision_id_to_drawings_table`) — already
   part of the normal `php artisan migrate` run for any environment
   deploying Phase 4 or later, not a separate step.
2. Run:

   ```
   php artisan drawings:backfill-initial-revisions
   ```

3. Confirm the command completes successfully (exit code 0, no errors in
   its own output).
4. Confirm eligible legacy Drawings now have `current_revision_id`
   populated — e.g. spot-check a few Drawings that predate Phase 4 via the
   Drawing Register or a direct query
   (`select count(*) from drawings where current_revision_id is null`
   should only count Drawings that genuinely still have no usable document,
   if any).
5. Only then enable/use hotspot authoring for that environment's users.

**Operational facts to rely on, not re-derive:**

- The command is **idempotent** — safe to re-run; it never creates a second
  initial revision for a Drawing that already has one, and never touches a
  Drawing that already has `current_revision_id` set.
- It never fabricates a revision code — it constructs the initial revision
  from the Drawing's own existing `document_id`, not an invented value.
- `Drawing.document_id` remains the permanent compatibility fallback
  (`Drawing::effectiveDocument()`) for any Drawing this backfill is never
  run against, or that still has no revision for another reason — nothing
  about hotspots changes that fallback's own behaviour for viewing/
  downloading the Drawing itself.
- Hotspot authoring must never be pointed at a Drawing via the legacy
  fallback-only path — every hotspot route requires a real, resolvable
  `DrawingRevision`, and the API rejects a request for a nonexistent
  revision with 404 regardless of what `Drawing.document_id` holds.

No automated deployment hook runs this command — it is a manual, on-demand
step (same convention as `billing:subscriptions:check-integrity`,
`domains:verify-pending`, and other manual repair/backfill commands in this
codebase), run once per environment as part of enabling this feature.
