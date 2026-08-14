# Viewing a Drawing

## What this is

A dedicated, read-only workspace for inspecting a drawing's linked
document — page navigation, zoom, and Fit Width, in a much larger working
area than the Documents preview. It is not a markup or annotation tool.

## Opening the viewer

From the [Drawing Register](overview.md), select **View** on a drawing
row (or click the row itself) to open its Drawing Viewer.

The viewer header shows the Drawing Number, Title, Discipline, Status,
Location Reference, the current revision (if the drawing has revision
history — see [Drawing Revisions](revisions.md)), and the document's file
name, alongside **Revisions**, **Download**, and Back actions.

## Viewing a historical revision

If you open an older revision from the [Revisions](revisions.md) panel,
the viewer clearly marks it **Historical** and shows a banner naming the
actual current revision, with a link to jump straight back to it. This
never changes which revision is current — it's a read-only look at what
was issued at that point.

## Page navigation

For a multi-page document, use the previous/next arrows to move between
pages. The current page and total page count are always shown. The
arrows disable automatically at the first and last page.

## Zoom

Use **Zoom In**/**Zoom Out** to adjust the page size; the current zoom
percentage is always shown alongside them.

## Fit Width

Select **Fit Width** to scale the page to the available viewer width
automatically. Fit Width stays active as the browser window is resized —
resize the window or rotate a tablet/phone and the page rescales to
match. Fit Width switches off automatically the next time Zoom In/Out is
used.

## Downloading

Select **Download** in the viewer header to download the linked document
— the same secured download used throughout SureSign.

## Drawing locations

A drawing revision may have specific locations marked on it, shown as small
markers on the relevant page. Select a marker to see its label and any
linked project records. Locations belong to the exact revision they were
added to — switching to a different revision, or viewing an older one,
shows only that revision's own locations.

### Adding a location

On the drawing's current revision, select **Add Location** in the viewer
header, then click the drawing where you want to place it. A small form
asks for an optional label, then **Save** adds the location — **Cancel**
discards it without saving anything. Locations can only be added on the
current revision; open an older revision from
[Revisions](revisions.md) and its locations are shown but read-only.

### Editing, moving, and removing a location

Select a marker to open it, then:

- **Edit label** to change its label.
- **Move location** to reposition it — click the new position on the same
  page.
- **Remove location** to delete it (with confirmation). If the location has
  linked records, removing it also removes those links — the linked
  records themselves are never deleted.

These actions are only available on the drawing's current revision.

### Creating records from a Drawing Location

From a location's marker, select **Create Record**, then choose **Snag**,
**RFI**, or **QA Report**. This opens the exact same form used on that
module's own page — a small "Creating from Drawing Location" note shows
the drawing number, revision, page, and the location's label (if it has
one). If the location has a label, Snag's Location field and QA Report's
Area field are pre-filled from it — you can still edit or clear this, it's
only a starting suggestion. RFI has no such field; its Drawing connection
is recorded automatically, not written into any of its text fields.

Once you save, the new record is automatically linked to that location —
you stay on the same drawing, revision, and page, and the new record
appears immediately under the location's linked records.

This also works on a location that belongs to an older, historical
revision — a hotspot marked on a superseded issue is still a real,
specific place on the drawing, so you can create and link a record
against it just as you would on the current revision. What you *cannot*
do on a historical revision is add, move, or remove a location itself
(see [Adding a location](#adding-a-location) above) — that stays limited
to the current revision only.

### Linking existing records

From a location's marker, select **Link Existing**, choose a record type
(Snag, RFI, QA Report, or Variation), and search for the record to link.
A location can link to more than one record, and the same record can be
linked from more than one location. Select a linked record's link icon to
open it. Select the small **×** beside a linked record to remove that link
— the record itself is never affected. Like Create Record, this is
available on a historical revision's location as well as the current
revision's.

**Create Record** supports Snag, RFI, and QA Report only. **Variation**
can only be connected to a drawing location via **Link Existing** — there
is no way to create a new Variation from the Drawing Viewer.

A linked Snag, RFI, QA Report, or Variation shows its own **Drawing
Locations** section listing every location it's linked from, with an
**Open Drawing** action that jumps straight to the correct drawing,
revision, and page.

## Supported file types

The viewer displays the linked document exactly as Documents itself would
serve it for preview — a PDF renders directly; a Word document that
Documents already converts to PDF for preview renders the same way; an
image (PNG/JPEG/etc.) displays directly. If a document has no usable
preview (for example, no file has been uploaded to it, or the format
isn't supported), the viewer shows a clear message with a Download option
instead of leaving a blank screen.

## Related

- [Drawing Register overview](overview.md)
- [Drawing Revisions](revisions.md)
- [Previewing documents](../documents/previewing.md)
