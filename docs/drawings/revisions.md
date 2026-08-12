# Drawing Revisions

## What this is

A history of the actual issued files for a drawing over time — each
revision links to a specific project document, and one revision is always
the **current** revision. Older revisions are preserved permanently; adding
a new revision never removes or replaces an earlier one.

## Where to find it

Open a drawing (from the [Drawing Register](overview.md) or the [Drawing
Viewer](viewing-a-drawing.md)) and select **Revisions**.

## Adding a revision

1. Select **Add Revision**.
2. Choose an existing project **Document** — only documents not already
   used by another revision of this drawing are shown. Upload the file in
   **Documents** first if it hasn't been uploaded yet.
3. Enter a **Revision Code** (for example `P01`, `C01`, `A`, or any code
   your project uses — there is no fixed format).
4. Optionally choose a **Status**, enter an **Issued Date** and **Issued
   By**, and add **Notes**.
5. Save.

The new revision immediately becomes the drawing's current revision. The
previous current revision is kept exactly as it was — its own status is
never automatically changed.

## Revision history

The Revisions panel lists every revision, most recent first, with its
revision code, status, issued date, linked document, and who added it. The
current revision is clearly marked.

## Viewing an older revision

Select the open icon on any revision in the history list to view it in the
Drawing Viewer. A revision that isn't current is clearly labelled
**Historical**, with a banner naming the actual current revision and a
link back to it. Viewing an older revision never changes which revision is
current.

## Editing a revision

A revision's Revision Code, Status, Issued Date, Issued By, and Notes can
be edited after it's added. The linked document cannot be changed — if the
wrong file was selected, add a new, correct revision instead.

## Drawings without revision history

A drawing registered before revision tracking existed, or one that hasn't
had a revision explicitly added yet, has no current revision recorded.
Its Drawing Register and Viewer still show its originally-registered
document normally — nothing is lost, it simply has no revision history to
display yet until a revision is added. [Drawing locations](viewing-a-drawing.md#drawing-locations)
can't be added, edited, or linked to project records until the drawing has
at least one revision, since a location always belongs to a specific
revision — add a revision first.

## Related

- [Drawing Register overview](overview.md)
- [Viewing a Drawing](viewing-a-drawing.md)
