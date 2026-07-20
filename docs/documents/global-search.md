# Global Documents

## What this is

An organisation-wide search across every document belonging to your
accessible projects: files you or a colleague uploaded, and PDFs SureSign
generated automatically (payment certificates, notices, variation orders,
and similar). It answers where a document is, which project owns it, what
created it, and how to get back to the record it belongs to.

Global Documents is read-only. You cannot upload, generate, or delete a
document from this page. Those actions remain inside a project's own
**Documents** tab, where they have always lived.

## Who can use it

Client users see documents belonging to their own organisation's projects.
Super Admin and Admin see the same search, following the same access rules
as [Global Commercial](../commercial/global-overview.md) and
[Projects](../projects/overview.md).

## Where to find it

Select **Documents** from the main sidebar (outside of any project).

## What you will see

- A summary of Total Documents, Uploaded, Generated, and AI Generated,
  counted across all documents you can access.
- Search by filename, title, reference number, project name, or trade
  package name.
- Filters for project, module, document type, uploaded versus generated,
  AI generated only, and file type.
- Preview and download for every result, using the same preview and
  download behaviour as the project Documents tab.
- Where a document was generated from a specific record such as a
  Variation, Payment Notice, Payment Application, or Trade Package, an
  arrow action takes you straight back to that record.

## Table view and Explorer view

A view toggle next to the filters switches between two ways of looking at
the same results:

- **Table** (the default) — a dense, sortable table with a column per
  document: name, project, module, document type, origin, file type, date,
  size, and actions. Best for searching and auditing, which is what this
  page is used for most.
- **Explorer** — the same results grouped visually by project, then by
  module or source area within that project, so you can browse by context
  instead of scanning rows. Click a project or module heading to expand or
  collapse it. Explorer only ever shows documents your current search and
  filters already matched — a project or module with nothing in it under
  the current filters simply doesn't appear.

Your chosen view is saved in the page's URL (`?view=table` or
`?view=explorer`), so it survives a refresh, browser back/forward, and a
copied link — sending someone a Global Documents link opens them straight
into the same view you were looking at.

Explorer groups whatever page of results is currently loaded, the same
results the Table view's pagination also works from — if a project's
documents span more than one page of results, Explorer shows that project's
documents from the current page only, same as Table does. Use the
pagination controls (visible in both views) to move through the rest.

## What is not covered yet

A small number of documents have no direct link back to their source
record. This happens when a document belongs to a Contract, since there is
currently no dedicated Contract page to link to. These documents remain
fully searchable, previewable, and downloadable; only the direct link back
is unavailable for now.

## Related

- [Documents (project level)](overview.md): where documents are actually
  uploaded, generated, and managed.
- [Dashboard](../dashboard/overview.md)
- [Projects](../projects/overview.md)
