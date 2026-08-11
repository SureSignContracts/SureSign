# Drawing Register

## What this is

A structured register of a project's drawings — drawing number, discipline,
status, and location — each linked to an existing project document. The
Drawing Register does not store files itself; it describes what an
already-uploaded document represents from a construction point of view.

**Drawings vs. Documents**: Documents is the authoritative file library for
the whole project. Drawings is a structured register built on top of it —
register a document as a drawing once you want to track its drawing number,
discipline, status, and location alongside the file itself.

## Who can use it

Any authenticated member of the project's own organisation can view the
Drawing Register, register a drawing, edit drawing metadata, and remove a
drawing registration.

## Where to find it

Project → **Drawings** (under the Delivery section of the project menu).

## Registering a drawing

1. Select **Register Drawing**.
2. Choose an existing project **Document** from the selector. Only documents
   not already registered as a drawing are shown — search by title, file
   name, or reference number.
3. Enter the **Drawing Number** and **Drawing Title**.
4. Optionally choose a **Discipline** and **Status**, and enter a
   **Location Reference** (e.g. "Block A – Level 02").
5. Save.

New files are not uploaded here — upload or generate the document in
**Documents** first, then register it as a drawing.

## Discipline and status

Discipline options include Architectural, Structural, Civil, Mechanical,
Electrical, Plumbing, Fire, Landscape, General, and Other. Status options
include Draft, For Review, For Information, For Approval, For Construction,
As Built, and Superseded. Both are optional descriptive fields, not an
approval workflow.

## Search and filters

Search matches drawing number, title, and the current revision's (or
linked) document title or reference number. Filter the register by
discipline or status; clear all filters with one action.

## Current Revision

Once a drawing has revision history, the register's **Current Revision**
column shows its current revision code — see [Drawing
Revisions](revisions.md). A drawing with no revision history yet shows
"—"; a revision that exists but has no recorded code (for example, one
carried over from before revision tracking existed) shows "Not recorded".

## Viewing a drawing

Select **View** on a drawing row to open it in the dedicated Drawing
Viewer — see [Viewing a Drawing](viewing-a-drawing.md) for page navigation,
zoom, and Fit Width. Clicking anywhere else on the row opens the same
viewer directly.

## Drawing details, editing, and removal

Use the **Edit** (pencil) action on a drawing row to open its details for
editing, or **Remove** (bin) to remove the registration. The details view
also shows the linked document and offers a quick **Preview** using the
same viewer as Documents.

## Editing a drawing

Drawing Number, Title, Discipline, Status, and Location Reference can all be
edited after registration. The linked document cannot be changed from the
edit form — if the wrong document was selected, remove the registration and
register the correct document instead.

## Removing a drawing registration

Removing a drawing only removes its register entry. **The linked document
remains available in Documents** — it is never deleted, and it can be
registered again as a new drawing at any time.

## Related

- [Viewing a Drawing](viewing-a-drawing.md)
- [Drawing Revisions](revisions.md)
- [Documents overview](../documents/overview.md)
- [Previewing documents](../documents/previewing.md)
- [Downloading documents](../documents/downloading.md)
