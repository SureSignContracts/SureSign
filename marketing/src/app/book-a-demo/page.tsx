import { redirect } from 'next/navigation';

// Superseded by the Appointments-backed public booking flow at /book/demo
// (Phase 3) — kept as a redirect rather than removed outright so any
// existing bookmarks/indexed links still land somewhere real.
export default function BookADemoPage() {
  redirect('/book/demo');
}
