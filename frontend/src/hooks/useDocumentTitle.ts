'use client';

import { useEffect } from 'react';
import { buildPageTitle } from '@/lib/pageTitle';

/**
 * Sets `document.title` from the shared browser-title convention (see
 * `lib/pageTitle.ts` and CLAUDE.md's "Dynamic Browser Titles" section).
 *
 * Pass `page: undefined` to skip entirely — used when an outer layout has
 * already delegated the title to a more specific nested owner (e.g.
 * AppLayout skips it for project workspace routes, which ProjectLayout
 * owns instead). Pass `page: null` (or an unmapped route's label) to fall
 * back to the bare "SureSign" title.
 *
 * The effect only re-runs when the composed title string actually changes
 * — a background React Query refetch that leaves the organisation/project
 * name unchanged never touches `document.title` again, and enrichment
 * (plain page title -> page + project + organisation) happens as a single
 * clean update once that data loads, never as a flicker of intermediate
 * states.
 */
export function useDocumentTitle(
  page: string | null | undefined,
  opts?: { project?: string | null; organization?: string | null }
): void {
  const title = page === undefined
    ? undefined
    : buildPageTitle({ page, project: opts?.project, organization: opts?.organization });

  useEffect(() => {
    if (title === undefined) return;
    document.title = title;
  }, [title]);
}
