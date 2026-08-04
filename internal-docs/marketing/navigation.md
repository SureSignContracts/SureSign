# Marketing Navigation

The public marketing website is served from `marketing/` at
`suresigncontracts.app`.

## Primary navigation

The shared header loads the current public pricing plans server-side in
`marketing/src/components/nav/MarketingNav.tsx` and passes them to the
interactive desktop/mobile shell in `MarketingNavClient.tsx`.

Current public links:

- Product
- Pricing dropdown
- Services dropdown
  - Services overview
  - Consultancy
  - Adjudication
- Contact
- Documentation
- Log In
- Book a Demo

`Contact` routes to `/contact`. `Documentation` and `Log In` remain external
links. The mobile menu renders from the same link collection as desktop, so
navigation changes must not be duplicated in separate arrays.

The footer mirrors the same product architecture: Platform contains Product,
Pricing, Documentation, and the existing product overview anchors; Services
contains the `/services` overview, Consultancy, and Adjudication. Company,
Legal, and Trust retain the existing contact, legal, security, procurement,
and demo routes.

## Professional services

`/services` is the parent marketing page for SureSign professional services.
It explains the distinction between Consultancy and Adjudication and links to
their canonical routes:

- `/consultancy`
- `/adjudication`

The child routes remain top-level and must not be moved beneath `/services`.

## Pricing section

Pricing uses a documentation-style page structure:

- `/pricing` is the overview and plan selector.
- `/pricing/[slug]` is the reusable deep-dive route for every visible,
  published plan returned by `GET /api/pricing`.
- `/pricing/compare` contains the complete feature comparison.

The dropdown order is Overview, the current Super Admin-managed plan list,
then Compare Plans. Plan names and slugs are never duplicated in the
marketing navigation. Renaming, hiding, archiving, or adding a plan changes
the dropdown and generated sitemap automatically after pricing cache
revalidation. Desktop and mobile use the same accessible dropdown component.

## Adjudication Services

`/adjudication` explains the relationship between SureSign and its separate
specialist sibling company, Adjudication Services. The route is an
informational SureSign page and must not be converted into an automatic
redirect.

All calls to action leaving SureSign use the permanent external destination:

`https://www.adjudicationservices.co.uk/`

External links open in a new tab, use `noopener noreferrer`, include an
external-link icon, and provide screen-reader text explaining the transition.
The page must not describe Adjudication Services as a SureSign module or imply
that records transfer automatically between platforms.

## Product and security pages

`/product` holds the detailed product workflow sections removed from the
homepage during the commercial trust and conversion pass. The homepage keeps
the shorter buyer narrative; the Product link preserves access to the full
workflow breadth.

`/security` is linked from the footer and included in the sitemap. It uses
repository-verifiable control descriptions and must not imply an unverified
certification, hosting location, retention commitment, incident-response SLA,
or encryption-at-rest guarantee.

The theme control remains available in the footer rather than the primary
navigation. Dark mode and stored/system preference behaviour are unchanged.

## Contact flow

`/contact` submits to `POST /api/marketing-contact`. The API validates all
fields, silently accepts honeypot submissions, applies the
`marketing-contact` rate limiter, and sends the enquiry to
`MARKETING_CONTACT_EMAIL` through the existing Brevo delivery service.
The configured default recipient is `tech@suresigncontracts.com`.

## Sitemap

`marketing/src/app/sitemap.ts` is the source for the public XML sitemap. It
includes the homepage, Product, Security, Pricing overview, every current
public plan page, Compare Plans, Adjudication, Contact, and Book a Demo.
