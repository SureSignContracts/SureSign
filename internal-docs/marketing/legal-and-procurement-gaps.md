# Marketing legal and procurement content gaps

Status: partially addressed — a Privacy Policy and Terms of Use are live;
the remaining items below still require approved business or legal input.

**Correction (2026-09-14):** this file previously stated "the marketing
footer intentionally does not link to invented Privacy, Terms or Cookie
pages" — that was already stale by the time this file itself was added.
`/privacy` and `/terms` (and the footer's links to them) have existed since
2026-07-28 (commit `2435bdf`), about two hours before this file's own first
commit (`3f013d4`); this file was never updated to reflect it, and the
inconsistency stood for roughly seven weeks. Whether that Privacy/Terms
content actually received the "approved business or legal input" this
file's own status line calls for is a real business/legal question this
documentation pass cannot answer from the repository alone — flagging it
for a genuine decision rather than assuming either way.

What the live pages actually contain (verified directly against
`marketing/src/app/privacy/page.tsx` / `terms/page.tsx`): generic,
non-specific privacy/terms content — no claimed ISO 27001, SOC 2, Cyber
Essentials, UK-only hosting, recovery-time guarantee, or other
certification; no specific company registration number or registered
address stated (the entity is referred to only as "SureSign Contracts").
A Cookie Policy page does not exist — that part of the original statement
above remains accurate.

Required legal decisions (still open — not addressed by the live content):

- controller identity, company registration number, and registered address
  (the live Privacy Policy does not state any of these);
- a standalone cookie policy — the live Privacy Policy states essential
  cookies only, no tracking/advertising cookies, but there is no dedicated
  Cookie Policy page;
- specific data retention and deletion periods (the live Privacy Policy
  describes retention only in general terms — "as long as your account is
  active", "a reasonable period" — with no stated timeframe);
- a data export or return process when a customer leaves;
- confirmation that the live Terms of Use's specific commercial terms
  (liability cap of fees paid in the preceding twelve months, 5/10
  working-day response commitments, etc.) reflect actual approved policy
  rather than placeholder figures.

Required procurement evidence:

- approved hosting provider and production data region wording;
- encryption-at-rest assurance for each production data store;
- sub-processor list;
- incident response and customer notification process;
- backup frequency, retention and approved recovery commitments;
- support hours, support channels and service commitments;
- onboarding and migration scope;
- certification status, including an explicit statement where none is held.

Until those decisions are approved, public copy may describe only implemented,
repository-verifiable controls and must not imply ISO 27001, SOC 2, Cyber
Essentials, UK-only hosting, a recovery-time guarantee, or legal certification.
