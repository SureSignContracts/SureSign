# Drawing Hotspot Record Relationships (Drawing Phase 7)

Covers the architecture behind connecting a Drawing Hotspot to an
operational Snag/RFI/QA Report/Variation record — both linking an
existing record (Phase 6B) and creating a new one directly from the
Drawing Viewer (Phase 7). What a customer sees is documented in the
public User Guide's
[Viewing a Drawing](../docs/drawings/viewing-a-drawing.md) page instead.

## The relationship is never a foreign key on the operational record

`DrawingHotspotLink` (polymorphic `linkable_type`/`linkable_id`, Phase 6B)
remains the sole relationship. Snag, Rfi, and QaReport carry **no**
`drawing_id`, `drawing_revision_id`, `drawing_hotspot_id`, `page_number`,
`x`, or `y` column — this was a deliberate, repeatedly-reaffirmed
architecture decision across Phases 7A–7C, not an oversight. A future
change proposing to denormalize any of this onto an operational record
should be treated as a regression, not a convenience.

## `App\Services\Drawings\DrawingHotspotLinkService`

The single place a hotspot/record relationship is validated and created
— `linkOrFail(DrawingHotspot $hotspot, string $type, Model $record, User $actor): DrawingHotspotLink`.
Resolves Project/Organisation ownership from the **hotspot's own chain**
(`hotspot->revision->drawing->project_id`/`organization_id`), never from
a caller-supplied Project — this is what lets both the existing Link
Existing endpoint (`DrawingHotspotLinkController::store()`, refactored in
Phase 7B1 to call this service) and the three Snag/RFI/QA create
endpoints share identical validation without diverging. Deliberately
narrow: it does not create/authorize the operational record itself (each
module's own controller stays authoritative for that), and does not
manage unlinking (only one caller does that today).

## Optional `drawing_hotspot_id` on Snag/RFI/QA create (Phase 7B1)

`SnagController`/`RfiController`/`QaReportController::store()` each
accept an optional `drawing_hotspot_id`. Absent: behaviour is unchanged
from before Phase 7 existed. Present: record creation, the module's own
existing activity logging, and the hotspot link are wrapped in one
`DB::transaction()` — any validation failure (nonexistent hotspot,
cross-project, cross-tenant, duplicate) rolls back the whole thing,
including the just-created record. RFI's `GenerateProjectNotificationsJob`
dispatch and both RFI's/QA's synchronous notification calls were moved to
fire only **after** that transaction commits — before Phase 7B1 these
fired with no transaction at all to race against; introducing one without
also moving the notifications would have let a rolled-back create still
produce a customer-visible notification.

## Historical relationship vs. authoring — the one deliberate rule change

Two structurally separate restrictions exist, and Phase 7B1 changed only
one of them:

- **Hotspot authoring** (`DrawingHotspotController` — add/edit
  label/move/delete a hotspot itself) remains current-revision-only,
  unchanged since Phase 6A. `assertCurrentRevision()` still gates all of
  `store()`/`update()`/`destroy()` there.
- **Hotspot/record relationships** (`DrawingHotspotLinkController` —
  link/unlink/create+link) were deliberately relaxed in Phase 7B1: a
  hotspot on a historical revision is still a real, specific location on
  that issued drawing, so operating on the *relationship* between it and
  a construction record never modifies the historical drawing or hotspot
  itself. The `if ($drawing->current_revision_id !== $revision->id)
  abort(422, ...)` guard that previously blocked this was removed from
  `store()`/`destroy()` only — never from the authoring controller.

The frontend mirrors this with two separate flags in
`DrawingHotspotOverlay` — `editable` (authoring, current-revision-only,
unchanged) and `canManageLinks` (relationships, current-or-historical,
new in Phase 7B3) — never derived from each other.

## Variation stays Link Existing only

`VariationController::store()` is contract-scoped
(`store(Request $request, Contract $contract)`), not project-scoped like
the other three (`store(Request $request, Project $project)`) — a
structural mismatch with the Drawing Viewer's own context, on top of the
already-documented commercial/contractual weight a Variation carries.
This is why Variation was never given an optional `drawing_hotspot_id` in
Phase 7B1, and why the Drawing Viewer's Create Record menu
(`DrawingHotspotOverlay`'s `CREATE_RECORD_OPTIONS`) excludes it outright
— Variation remains reachable only through the existing Link Existing
flow, which continues to support it.

## Drawing-origin creation context is presentational only

There is no backend equivalent of this — it's entirely a frontend
concept. `DrawingCreationContext` (a plain TypeScript type,
`frontend/src/components/drawings/DrawingCreationContext.ts`) carries the
hotspot id, drawing number, the *actively viewed* revision's label
(historical-aware — never `drawing.current_revision`), page number, and
hotspot label into `SnagModal`/`NewRfiModal`/`QaModal` as one optional
prop, `drawingContext`. It drives exactly two things, both convenience
only: the small "Creating from Drawing Location" header
(`DrawingCreationContextBadge`), and the `Snag.location`/`QaReport.area`
prefill from the hotspot's own label (never a fabricated "Drawing
S-204..." string, never persisted verbatim beyond that one edit-anytime
text field). `drawing_hotspot_id` is added to the create payload only
when `drawingContext` is present, and only on the create branch of
Snag/QA's combined create+edit modals — never on an edit save.

## Related

- [Drawing Register / Revisions / Hotspot Authoring](../docs/drawings/viewing-a-drawing.md)
- Production deployment note for the Phase 6 legacy-revision backfill:
  [Production Deployment Notes](production-deployment-notes.md)
