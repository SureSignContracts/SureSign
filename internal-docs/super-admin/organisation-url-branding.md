# Organisation URL Branding — Phase 1 & Phase 2

Branded SureSign hostnames per organisation (e.g.
`star-affinity.suresigncontracts.app`), for the public, customer-facing
surfaces that already know their organisation before login. Phase 2 adds
customer-owned domains (Bring Your Own Domain, e.g.
`contracts.star-affinity.co.uk`) on top of the same architecture, plus
slug history/redirects and a central host resolver. Automatic DNS/SSL
provisioning remains explicitly out of scope in both phases — see the
Phase 2 section below.

---

## Phase 1

## Architecture audit (performed before any code was written)

### Signed URLs — the API-host boundary

`Illuminate\Routing\UrlGenerator::signedRoute()` (confirmed by reading
`vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php`, not
assumed) computes its HMAC over the **full absolute URL, including host**,
by default. Verification (`hasCorrectSignature()`) compares against the
live request's own `$request->url()` — also host-inclusive.

This would be a real problem if the backend API itself were ever reached
on a per-organisation branded hostname. It isn't, and must never become
one: every public signed endpoint
(`PublicAppointmentActionController::showCancel`/`showReschedule`,
`PublicConsultationViewController::show`/`summary`/`ics`) returns
`response()->json(...)` — there is no server-rendered HTML page on the API
host at all. The customer-visible link is always rewritten onto the
separate `marketing/` Next.js app by `AppointmentPublicLinkService`, which
then calls the API back on its own fixed base URL.

**Hard constraint, not a preference**: the backend API stays on a single,
fixed, never-branded host forever. Do not:

- proxy the API through organisation subdomains;
- generate signed API URLs using organisation hosts;
- make Laravel's signature validation depend on the branded frontend host;
- introduce host-based authentication or tenant resolution into API
  middleware.

The flow, unchanged from before this phase and required to stay this way:

```
Branded frontend URL (marketing/)
  → frontend reads the signed query parameters
  → frontend calls the fixed API host
  → Laravel validates the signature against the fixed API URL
```

Given this, branding could be added with **zero change** to signed-URL
generation or validation — confirmed from the code, not guessed.

### No existing host-based tenant resolution

No `middleware.ts` existed in either frontend app; no Host-header-based
Organization lookup existed in the backend. Every branding lookup
(`BrandingService::forOrganization()`) takes an explicit `organization_id`
resolved from the authenticated user or a token-bound model — never from
hostname. This is genuinely new infrastructure.

### `organizations.slug` vs. the new `url_slug`

`organizations.slug` already exists (unique, `Str::slug($name)`-generated),
but is used only for local storage folder naming
(`ProjectStorageService`) and display, is silently regenerated on every
name edit (`OrganizationController::update()`), and has no reserved-name
or DNS-label validation. Reusing it directly as a public hostname would
couple two unrelated concerns and skip required validation. A dedicated
`url_slug` column was added instead — nullable, never auto-generated,
changed only through an explicit Super Admin action.

### No public-URL namespace collision risk

`AppointmentType`/`ConsultancyService` slugs are already globally unique
across the whole platform (`AppointmentType::where('slug', $slug)` has no
organisation scope) — branded subdomains are additive branding, not a
namespacing fix.

## What was built

### Database

- Migration `2026_08_25_000001_add_url_slug_to_organizations_table.php` —
  `organizations.url_slug` (`string(63)`, nullable, unique).
- A soft-deleted organisation's `url_slug` is automatically excluded from
  resolution (Eloquent's `SoftDeletes` global scope) — combined with the
  DB-level unique constraint (never cleared on soft delete), a deleted
  organisation's hostname cannot be silently reclaimed without a Super
  Admin explicitly freeing it first.

### Validation

- `App\Support\Organizations\UrlSlugValidator` — the single source of
  truth for slug format (2–63 chars, lowercase letters/digits/hyphens,
  must start/end with a letter or digit, no consecutive hyphens),
  normalisation (lowercase + trim, applied before both validation and
  persistence), and reserved-name checking.
- `config/organisation_branding.php` — `root_domain` (the platform-wide
  branding on/off switch — see below) and `reserved_hostnames` (a
  configurable, extendable list: `www`, `app`, `api`, `admin`,
  `superadmin`, `billing`, `stripe`, `google`, `consultancy`,
  `appointments`, `localhost`, etc.).
- `App\Http\Requests\UpdateOrganizationUrlSlugRequest` — format, reserved
  names, and **case-insensitive uniqueness against the normalised value**
  are all checked in one `withValidator()` pass. This matters: a
  `Rule::unique()` in `rules()` would check the *raw* submitted string,
  which on a case-sensitive DB collation (SQLite's default; not something
  to rely on with MySQL either) can pass validation for `"STAR-AFFINITY"`
  against an existing `"star-affinity"` row, only to then fail as an
  unhandled DB constraint violation (500) once the value is lowercased
  before saving. Caught and fixed by the test suite (see below) before
  this ever reached a real environment.

### Super Admin management

- `PUT`/`DELETE /organizations/{organization}/url-slug` —
  `role:Super Admin` **only** (mirrors the manual/complimentary
  subscription-assignment group's precedent: changing a customer's public
  hostname is a deliberate production-infra action, not a routine settings
  edit). Both require `reason` (min 10 chars) and `confirmed: true`,
  matching `ManageAiCreditsRequest`/`UpdateAiCreditOperatingModeRequest`'s
  established convention.
- Every change writes an `organization.url_branding_created` /
  `_changed` / `_removed` `ActivityLog` entry (previous/new slug, acting
  user, reason, timestamp) — never the full signed URLs or customer
  tokens.
- Admin (platform-wide, non-Super-Admin) and Client roles get read-only
  visibility only (the existing `GET /organizations/{id}` and
  `GET /organization` responses now include `url_slug`, since it's just
  another organisation column) — no separate endpoint needed.
- Frontend: `OrganisationUrlBrandingSection` on
  `/admin/companies/{id}`, mirroring `OrganizationSubscriptionSection`'s
  confirmation-dialog pattern (reason textarea + explicit checkbox before
  any mutation).

### URL generation

- `App\Services\OrganisationUrlGenerator` — the single place a branded vs.
  default public URL is assembled. `publicUrl()`/`publicUrlWithRawQuery()`
  return the organisation's branded URL only when **both** are true:
  `config('organisation_branding.root_domain')` is set (an operator has
  deliberately turned branding on platform-wide) **and** the organisation
  has a non-null `url_slug`. Otherwise, byte-for-byte the same fallback
  behaviour as before this phase (`config('suresign.marketing_url')`).
  `root_domain` is a genuinely new, explicit config value — never derived
  by stripping a leading label off `MARKETING_URL`'s own host, which would
  be fragile string surgery.
- `AppointmentPublicLinkService`'s `*MarketingUrl()` methods (the only
  places in the codebase that concatenated `marketing_url` directly) now
  route through the generator, keyed off the appointment's own
  `organization_id`. The signed **API** URLs these wrap
  (`cancelApiUrl()`/`rescheduleApiUrl()`/etc.) are completely unchanged.
- `publicUrlWithRawQuery()` exists as a distinct method from `publicUrl()`
  specifically to avoid re-encoding a signed link's already-URL-encoded
  `expires`/`signature` query string through `http_build_query()`, which
  could otherwise subtly alter Laravel's own encoding.

### Public hostname → branding resolution

- `App\Http\Controllers\Api\PublicOrganisationBrandingController`
  (`GET /public/organisation-branding/{slug}`, public, throttled, 10-minute
  cached) — returns `url_slug`/`organisation_name`/`logo_url`/
  `accent_color` only. Never an internal id (the frontend never needs
  one — it already knows the slug from the hostname itself, and that's
  the only correlation value it forwards back), never billing/user/
  project/settings/storage-path/contact data. 404s identically for an
  invalid-format slug, a reserved slug, an unknown slug, an inactive
  organisation, or a soft-deleted organisation's stale slug.

### Cross-host tenant isolation

- `App\Support\Organizations\EnforcesPublicOrganizationHost` — wired into
  `PublicAppointmentActionController::findByToken()` and
  `PublicConsultationViewController::findByToken()` (both single choke
  points already used by every action on those controllers).
- The marketing frontend forwards its resolved branded slug as an
  **`X-Suresign-Org-Slug` HTTP header** — deliberately never a query
  parameter, since every one of these endpoints sits behind Laravel's
  `signed` middleware, which HMACs the full query string; a query
  parameter added after the link was generated would simply fail
  signature verification. A header sits outside the signed payload
  entirely. `tests/Feature/OrganisationUrlBrandingTest.php`'s
  `test_signed_url_still_validates_without_the_org_header_ever_touching_the_signature`
  is a regression guard for exactly this.
- Enforcement rule: no header → always passes (the hostname carries no
  authorisation either way — the default host is always valid for any
  resource). Header given but it doesn't resolve to a real organisation,
  or resolves to a *different* organisation than the resource's own
  `organization_id` → the resource is treated as not found — identical to
  "token doesn't exist," never a distinct error, so a mismatched host
  can't be used to probe which organisation a token belongs to.
- **Public bookings without an organisation** (a Consultancy booking taken
  with no customer organisation — `Appointment::organization_id` is
  nullable): a request declaring *any* branded host against such a
  resource is treated as a mismatch, never a pass, and no organisation is
  ever invented to produce a branded URL for it — it always falls back to
  the default hostname.

### Frontend (marketing app)

Deliberately scoped to only the components that already know they're
rendering an organisation-owned public resource
(`PublicAppointmentExperience`, `PublicConsultationExperience`,
`PublicConsultationSummaryExperience`) — **no** Next.js `middleware.ts` or
root-layout host-classification layer was added across the whole
marketing site (homepage, pricing, etc. are unaffected and untouched).

- `marketing/src/lib/organisationBranding.ts` — `resolveOrgSlugFromHostname()`
  resolves the org slug from the real `window.location.hostname` (never
  spoofable server-side, since it's the browser's actual address bar);
  fully off unless `NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN` is
  configured. `fetchOrganisationBranding()` has a 10-minute client-side
  cache mirroring the backend's own TTL. `orgSlugHeader()` is what
  `publicAppointments.ts`'s shared `request()` function spreads onto every
  fetch's headers.
- `useOrganisationBranding()` + `OrganisationBrandingBadge` — an additive
  "Booked with {name}" badge with the organisation's logo when resolved;
  renders nothing when unbranded. Deliberately does **not** re-skin the
  page's own accent colour/theme — a broader visual re-skin is out of
  scope for this phase.

### Reserved hostnames

`www`, `app`, `api`, `admin`, `superadmin`, `auth`, `login`, `signup`,
`docs`, `support`, `status`, `mail`, `email`, `smtp`, `ftp`, `cdn`,
`assets`, `static`, `files`, `storage`, `media`, `billing`, `payments`,
`checkout`, `stripe`, `google`, `calendar`, `consultancy`, `appointments`,
`adjudication`, `marketing`, `root`, `system`, `null`, `localhost`, `blog`,
`help`, `demo`, `test`, `staging`, `dev`, `development`, `production`,
`sandbox`, `internal`, `suresign`, `suresigncontracts` — see
`config/organisation_branding.php`. Extend that list, never hardcode an
exception elsewhere.

## Environment variables

| Variable | Where | Purpose |
|---|---|---|
| `ORGANISATION_BRANDED_ROOT_DOMAIN` | backend `.env` | Registrable domain branded hostnames are minted under (e.g. `suresigncontracts.app`). Unset = branding fully OFF platform-wide. |
| `NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN` | `marketing/.env` | Same value, exposed client-side so the browser can resolve its own hostname against it. |

Both must be set together for branding to actually take effect end to end.

## Local development

Set both root-domain env vars to `localhost`. Browsers resolve any
`*.localhost` hostname to `127.0.0.1` natively — no `/etc/hosts` edits, no
hardcoded developer-specific port. A locally configured organisation with
`url_slug = "star-affinity"` then resolves to
`http://star-affinity.localhost:3001` against the marketing app's own dev
port, exactly mirroring the production shape.

## Production deployment (documented, not yet exercised in this
environment)

- **Cloudflare**: a wildcard DNS record (`*.suresigncontracts.app`)
  pointing at the marketing app's origin, with a wildcard/SAN TLS
  certificate covering the same. Proxy status and origin routing must
  preserve the original `Host` header through to the origin (this
  codebase's `TrustProxies` config already trusts `X-Forwarded-Host` from
  the private Docker bridge ranges — see `bootstrap/app.php` — so no
  change is needed there, but this must be verified against the real
  proxy chain before going live).
- **Dokploy**: the marketing app's routing rule must accept the wildcard
  host rather than a single fixed hostname.
- **Backend**: `ORGANISATION_BRANDED_ROOT_DOMAIN` set once branding should
  go live; the API's own host configuration is completely unaffected and
  must remain a single fixed value.

**Not claimed as production-validated**: no real wildcard DNS/TLS/Dokploy
configuration was created or tested in this environment. This must be
done and verified against at least one real organisation subdomain before
relying on this in production.

## Known limitations / explicitly deferred

- The authenticated app frontend (`frontend/`, distinct from the public
  `marketing/` app) stays on its fixed, unbranded host this phase — no
  customer-facing page there needs to resolve an organisation before
  login yet, and re-skinning the logged-in app is a larger, separate
  decision.
- No blanket "every unknown hostname renders a branded-neutral 404 across
  the whole marketing site" layer exists — only the itemised
  Appointment/Consultation experience components resolve branding. An
  unknown/mismatched host against a *specific* token-bound resource
  already safely 404s via the existing per-endpoint tenant-isolation
  check; a request to `nonexistent.suresigncontracts.app/` (the homepage)
  currently still renders the ordinary default marketing site. Building a
  platform-wide host-classification/404 layer was judged disproportionate
  to this phase's itemised call sites and is a candidate for a later
  phase if genuinely needed.
- Customer-owned domains, DNS/SSL automation, per-project domains, and
  white-label removal of SureSign branding are all explicitly out of
  scope — see the top of this document.

## Testing

`backend/tests/Feature/OrganisationUrlBrandingTest.php` — 29 tests:
slug format/normalisation/reserved-name rejection, Super-Admin-only
management endpoints + Activity Log, case-insensitive duplicate
rejection, organisation-name-change-does-not-change-slug, generator
branded/fallback behaviour (including null organisation and
not-yet-configured root domain), `AppointmentPublicLinkService`
integration (branded and fallback), cross-host tenant isolation (no
header/matching header/mismatched header/unknown header/org-less-resource
cases), a regression guard proving the org-header check never touches
Laravel's own signature verification, and the public branding endpoint
(known/case-insensitive/unknown/reserved/inactive/soft-deleted slugs).

Full backend suite (2322 tests) run after these changes: **zero
regressions**. The only failures present are pre-existing and already
documented in this repo's own prior-phase reports (two
`MARKETING_URL`-environment-dependent Consultancy Communications tests
that assume a production-like `.env` value this local development
environment doesn't have, plus unrelated local storage-directory
permission errors on this machine) — confirmed unrelated by reverting this
phase's changes and observing the same failures persist.

Frontend/marketing `tsc --noEmit` both clean.

---

## Phase 2

Continuation on top of Phase 1's architecture — no redesign. Confirmed
before writing code: signed-URL generation/validation still only ever
touches the fixed API host (nothing changed there); the API host
constraint from Phase 1 remains absolute and applies identically to
customer-owned domains — the API is **never** reachable via a customer's
own hostname, exactly as it's never reachable via a branded subdomain.

### Central host resolution

`App\Services\Organizations\OrganisationHostResolver` is now the ONE place
a raw hostname is classified — it replaces the duplicated
`Organization::where('url_slug', ...)` lookups that previously lived
independently in `EnforcesPublicOrganizationHost` and
`PublicOrganisationBrandingController` (Phase 1's original approach).
Resolution order for a raw hostname:

1. An `organization_domains` row with this exact hostname, status
   `active`, belonging to an active organisation → `TYPE_CUSTOMER_DOMAIN`.
2. If the host matches `{label}.{root_domain}`: an active organisation
   whose **current** `url_slug` is that label → `TYPE_ORGANISATION`;
   otherwise an active organisation whose **history** contains that label
   → `TYPE_HISTORIC_SLUG`.
3. Otherwise → `TYPE_NONE` — deliberately covering BOTH an ordinary
   platform host (`app.`/`www.`/the bare root domain) and a genuinely
   unknown hostname. Callers must never distinguish the two: an
   unresolved host always behaves exactly like the absence of any
   branding signal, and always falls back to default behaviour rather
   than guessing.

The marketing frontend now **always** sends the browser's real
`window.location.hostname` as the `X-Suresign-Org-Host` header on every
public token-based request (previously, Phase 1 only sent a value when it
locally recognised a branded-slug pattern). This is why "unresolved →
pass" had to become the correct behaviour for the cross-tenant check (see
below) rather than "any non-empty header that doesn't resolve → fail": an
ordinary visit to the plain default platform host must always be a valid
way to view ANY organisation's resource, and that host now always arrives
as a (deliberately unresolved) header value.

### Cross-host tenant isolation, upgraded

`EnforcesPublicOrganizationHost::hostMatchesOrganization()` now delegates
entirely to the resolver. The rule is unchanged in spirit from Phase 1,
restated precisely for the resolver's vocabulary:

- No header, or header resolves to `TYPE_NONE` → always passes.
- Header resolves to a *different* organisation than the resource's own
  `organization_id` (whether via a current slug, a historic slug, or a
  customer domain) → resource treated as not found, identical to "token
  doesn't exist."
- Header resolves to a **historic** slug that matches the resource's own
  organisation → **passes**, not a mismatch. The organisation identity is
  what's being verified, regardless of which of that organisation's own
  hostnames (current or superseded) reached the API — the frontend is
  responsible for redirecting the *page view* away from a historic
  hostname (see below); the API itself still resolves correctly if a
  request reaches it anyway (e.g. a stale bookmarked deep link).

`tests/Feature/OrganisationUrlBrandingPhase2Test.php`'s
`test_public_appointment_action_passes_with_historic_slug_header_for_the_same_organization`
and `test_signed_url_still_validates_with_domain_resolution_in_play` cover
this directly.

### Slug history

New table `organization_url_slug_history` (`App\Models\OrganizationUrlSlugHistory`,
immutable/append-only) records the value of `organizations.url_slug`
every time it's superseded — via `updateUrlSlug()`/`removeUrlSlug()` in
`OrganizationController`, written BEFORE the column is overwritten.

**Reuse prevention** (`UpdateOrganizationUrlSlugRequest::withValidator()`):
a slug that a *different* organisation has ever released stays
permanently reserved to that organisation — checked via a plain query
against the history table (no DB unique constraint on the history table
itself, since the SAME organisation may legitimately cycle a slug in and
out of use multiple times, which would violate a hard uniqueness
constraint on that table). The *same* organisation reclaiming its own
historical slug remains fully allowed.

**Redirect behaviour** — decided deliberately, not assumed, per the
signed-URL audit: because the signature never depends on the marketing
host (see Phase 1's own "API host boundary" finding), a redirect at the
marketing layer cannot break any signed link — a released slug redirecting
to the organisation's current canonical hostname is therefore SAFE from a
security standpoint. The approved policy implements exactly that, with
explicit guards:

- Redirect only when the historical slug and the current canonical
  hostname resolve to the **same, currently active** organisation.
- The full path and query string (including a signed link's
  `expires`/`signature` pair) are preserved unchanged — the redirect
  target is built from `OrganisationUrlGenerator::currentBaseUrl()` (which
  never accepts a caller-supplied destination) plus the browser's own
  current path/query, never anything else.
- If the organisation is inactive, or ownership is otherwise ambiguous
  (should never happen given the resolver's own checks, but the endpoint
  fails closed regardless), the request returns a neutral 404 instead of
  a redirect. Never reveals which organisation a historic hostname
  belonged to when it isn't safely resolvable.
- Mechanically: `PublicOrganisationBrandingController` returns
  `host_type: "historic_slug"` plus a `redirect_base_url` field for this
  case; `OrganisationBrandingProvider` (marketing frontend) is what
  actually performs `window.location.replace()` — the backend itself
  never issues an HTTP redirect, since the resource being viewed lives on
  the Next.js marketing app, not the API.

### Customer-owned domains (Bring Your Own Domain)

**Domain model** — `organization_domains` (`App\Models\OrganizationDomain`),
a dedicated table, never overloaded onto `organizations`. One organisation
may eventually own more than one domain (`Organization::domains()`);
`Organization::activeDomain()` is the one that's actually live. `hostname`
is globally unique and **permanently** claimed once registered, regardless
of status — deliberately stricter than the slug-history reuse model:
freeing a hostname (even for the same organisation to re-register) requires
an explicit Super Admin hard-delete of the row, since a customer domain is
a rarer, higher-stakes claim than an internal branded slug.

**Verification** (`App\Services\Organizations\DomainVerificationService`) —
follows the Vercel/Netlify/Shopify convention rather than inventing a
proprietary scheme:

- **TXT** record at `_suresign-verify.{hostname}` (prefix configurable via
  `ORGANISATION_DOMAIN_VERIFICATION_TXT_PREFIX`) with value
  `suresign-domain-verify={token}` — proves ownership. A dedicated,
  namespaced label rather than requiring a TXT record on the domain's own
  apex (which the customer may already use for something else, e.g. SPF).
- **CNAME** record pointing the hostname at
  `ORGANISATION_DOMAIN_CNAME_TARGET` — proves the customer has actually
  routed traffic at SureSign. The same target for every customer domain
  regardless of organisation; the organisation is identified at request
  time by the resolved Host, exactly like the branded-subdomain flow.
- Both are required before a domain can move past `verified`. Real DNS
  lookups are performed via `App\Services\Organizations\DnsRecordLookup`
  (a thin wrapper around `dns_get_record()`, injected so it can be faked
  in tests without real network calls) — never fails the caller; a lookup
  exception is caught and recorded as a failed check, never thrown.
- **Manual/on-demand only** this phase — mirrors
  `StripeReconciliationService`'s own "deliberately not scheduled"
  precedent. A Super Admin action
  (`POST .../domains/{domain}/verify`) or the `domains:verify-pending`
  Artisan command (`--dry-run` supported) triggers a real check; nothing
  runs automatically on a cron, since a domain's DNS state only changes
  when the customer edits their own DNS.

**State model** (`App\Support\Organizations\DomainStatus`): `pending` →
`awaiting_dns` (a check ran, found nothing yet) → `verified` (TXT + CNAME
both confirmed) → `active` (explicit Super Admin action — the documented
point at which an operator has confirmed the real production
origin/certificate coverage is genuinely ready) → `disabled`
(reversible, re-verifiable) / `failed` (a check ran and came back
negative) / `removed` (terminal, hostname stays permanently reserved).

**No automatic SSL provisioning exists or is claimed** —
`DomainVerificationService::verify()` only ever reaches `verified`, never
`active`; moving to `active` is a deliberate, separate Super Admin
decision, the documented moment an operator has confirmed Cloudflare
origin routing + certificate coverage for that specific hostname are
actually ready (see Deployment below). This codebase automates none of
that provisioning.

**Admin experience** — `OrganisationDomainsSection` on
`/admin/companies/{id}` (Super Admin only for every mutation; Admin gets
read-only visibility, matching the platform-wide-role precedent
elsewhere): register, view verification instructions (TXT/CNAME values),
trigger a verify check, activate, disable, remove. No customer
(Client-role) domain management exists.

### URL generation priority (upgraded)

`OrganisationUrlGenerator::brandedBase()` priority order, per the approved
architecture:

1. A verified, **active** customer-owned domain (`Organization::activeDomain()`).
2. A branded `url_slug` subdomain (only when
   `config('organisation_branding.root_domain')` is set).
3. The default marketing host.

A custom domain has no platform-wide on/off gate the way branded slugs do
(`root_domain`), since activating one is already an explicit,
individually-verified Super Admin action per domain — there's nothing
platform-wide left to gate. `currentBaseUrl()` (new) exposes just the
scheme+host part of this same priority chain, used exclusively for the
historic-slug redirect above — it never accepts a caller-supplied
destination.

### Public route coverage decisions

| Route family | Platform host | Organisation subdomain | Customer domain |
|---|---|---|---|
| Appointment cancel/reschedule | ✅ (default) | ✅ | ✅ |
| Consultation view/summary/ics | ✅ (default) | ✅ | ✅ |
| Public booking listing (`/book/{slug}`, `/consultancy/{code}`) | ✅ (default) | not yet wired to branding (out of scope — these pages don't currently resolve an organisation from the slug the way a token does) | not yet wired |
| Marketing homepage/pricing/etc. | ✅ (default) | unaffected — no host-classification layer added platform-wide | unaffected |
| Super Admin / internal Admin routes | ✅ (fixed, unbranded) | N/A — never touched | N/A — never touched |

### Reserved-domain / duplicate prevention

`StoreOrganizationDomainRequest` rejects: invalid hostname format, any
hostname equal to or ending in `.{root_domain}` (that space is exclusively
for branded `url_slug` subdomains, never an arbitrary `Domain` row), and
any hostname already claimed by any organisation (including a `removed`
one — see the domain model's own permanence note above).

### Local development

Both the branded-subdomain and customer-domain flows share the same local
setup as Phase 1: set `ORGANISATION_BRANDED_ROOT_DOMAIN`/
`NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN` to `localhost`. A local
customer-domain test additionally requires an actual resolvable hostname
(e.g. an `/etc/hosts` entry) since `*.localhost` wildcard resolution
doesn't help for an arbitrary external-looking domain — real DNS
verification against a genuinely local hostname isn't meaningful, so this
is a manual/documentation-only concern, not something this phase automates
for local dev.

### Security review additions (Phase 2)

- **Host spoofing**: unchanged from Phase 1 — the header is a coordination
  signal, never itself an authorisation mechanism; every check still
  independently verifies the resource's own `organization_id`.
- **Released-domain takeover**: impossible — `organization_domains.hostname`
  is a hard DB-unique constraint, permanent regardless of status.
- **Slug takeover**: impossible cross-organisation — enforced by the
  history-table reuse check; same-organisation reuse remains allowed by
  design.
- **Verification replay**: a captured verification token only proves
  ownership of the DNS zone at the moment of a `verify()` call; it has no
  standing authorisation value beyond that (no session/credential is
  derived from it).
- **Reserved domains**: the branded root domain's own namespace can never
  be registered as a customer `Domain` row.
- **Duplicate domains**: DB-unique `hostname`, plus an explicit
  application-level check with a clean 422 (not a raw constraint
  violation) in `StoreOrganizationDomainRequest`.
- **Open redirects**: `redirect_base_url` is always backend-derived from
  the resolver's own result — the frontend never accepts or forwards a
  user-supplied redirect target.
- **Signed URLs**: unaffected — confirmed by
  `test_signed_url_still_validates_with_domain_resolution_in_play`.

### Testing

`tests/Feature/OrganisationUrlBrandingPhase2Test.php` — 25 tests: slug
history capture (change + remove), same-organisation slug reuse allowed,
cross-organisation slug reuse blocked, slug-history read endpoint,
`OrganisationHostResolver` (current slug / historic slug for an active
organisation / historic slug ignored for an inactive organisation /
unknown host / platform host / active customer domain / non-active
customer domain ignored), URL generator priority (domain over slug over
default), cross-host isolation with a historic-slug header, full domain
lifecycle (register / reject-reserved-namespace / reject-duplicate /
Admin-forbidden / verify-success / verify-TXT-mismatch /
verify-never-throws-on-DNS-failure / activate-requires-verified /
activate→disable→remove with Activity Log), and a regression guard that
signed URLs still validate with domain resolution in play.

Three Phase 1 tests were updated (not silently left stale) to reflect the
now-full-hostname contract for `X-Suresign-Org-Host` and the public
branding endpoint's `{host}` parameter (previously bare `{slug}`) —
`test_public_appointment_action_404s_with_unknown_org_header`'s
expectation changed from 404 to 200, a deliberate, documented consequence
of the frontend now always sending the real hostname rather than only a
recognised branded-slug pattern (see "Central host resolution" above for
why "unresolved → pass" is the correct behaviour here).

Full backend suite (2347 tests, up from 2322) run after these changes:
**zero regressions** — the same 4 pre-existing, already-documented
environment-dependent failures as Phase 1's own report, unchanged.
Frontend/marketing `tsc --noEmit` both clean.

`php artisan domains:verify-pending --dry-run` was confirmed to load and
resolve its dependencies correctly; it could not be exercised against a
real running database from this shell (no Docker/MySQL connectivity in
this environment) — its actual DNS-lookup logic is what the mocked-`DnsRecordLookup`
test suite verifies instead.

### Deployment (Phase 2 additions — documented, not exercised)

- **Cloudflare**: for EVERY active customer domain, the origin must be
  configured to accept that specific hostname and present valid TLS for
  it (a wildcard cert covers `*.suresigncontracts.app`, never an arbitrary
  customer domain — each one needs its own certificate coverage, e.g. via
  Cloudflare for SaaS / a per-hostname custom certificate). This is a
  genuinely per-domain operational step, not automated by this codebase.
- **DNS**: `ORGANISATION_DOMAIN_CNAME_TARGET` must be a real, stable
  hostname at SureSign's own edge that customers are told to CNAME to.
- **Dokploy**: the marketing app's routing must accept arbitrary
  customer hostnames in addition to the wildcard branded-subdomain rule.
- **Not claimed as production-validated**: no real customer domain's DNS
  was verified against live infrastructure in this environment.

### Known limitations / explicitly deferred (Phase 2)

- Public booking-listing pages (`/book/{slug}`, `/consultancy/{code}`)
  are not yet wired to resolve organisation branding — only the
  token-bound Appointment/Consultation pages are, matching this phase's
  itemised scope.
- No automatic SSL/certificate provisioning, no automatic periodic
  DNS re-verification, no health-check scheduling (`domains:verify-pending`
  is manual/on-demand only, matching the explicit brief).
- The authenticated app frontend (`frontend/`) remains unbranded, same as
  Phase 1.
- Domain health diagnostics are captured (`last_check_result`) but there
  is no dedicated dashboard/alerting surface beyond the per-organisation
  Super Admin domain list.

---

## Customer Self-Service & Entitlement Control

Adds customer (Client-role) self-service management of an organisation's
own branded SureSign subdomain from **Settings → Company Branding →
Custom URL**, gated by a real, configurable entitlement. Builds directly
on Phase 1/2 — no redesign of `OrganisationUrlGenerator`,
`OrganisationHostResolver`, `url_slug`, slug history, reserved-hostname
rules, or the customer-domain model.

### Architecture audit findings

- `Feature::CUSTOM_BRANDING` already existed in the ten-key Entitlement
  Specification registry, but `OrganizationController::getBranding()`/
  `updateBranding()` (the existing Company Branding page) had **zero**
  `FeatureGate` check anywhere — any Client-role user of any organisation,
  on any plan, could already edit the logo/colours/tagline. This phase is
  the first real, wired `FeatureGate::allows()` enforcement point in the
  codebase (previously architecture-only, per `FeatureGate`'s own
  docblock).
- Two NEW keys were added rather than reusing `custom_branding` —
  `Feature::CUSTOM_BRANDED_SUBDOMAIN` (a public hostname — materially
  higher-stakes than cosmetic logo/colour branding) and `Feature::CUSTOM_DOMAIN`
  (reserved for a future customer self-service phase; Super Admin-managed
  only today). Never merged with each other or with `custom_branding`.
  This is the SECOND deliberate registry amendment to what was originally
  a closed ten-key list (the first was `ai_credits_per_month`) —
  `tests/Unit/Entitlements/FeatureTest.php` updated from eleven to
  thirteen approved keys accordingly.
- The prompt's assumed "existing organisation-admin/customer-owner role"
  does not exist in this codebase's role model — Super Admin and Admin are
  platform-wide; only Client is org-scoped, with no finer per-user
  permission distinguishing an "org admin" from an ordinary Client user.
  The new customer endpoints reuse the EXACT SAME authorisation gate the
  existing Company Branding page already uses ("any Client-role user of
  this organisation; Super Admin/Admin have no organisation of their own
  to manage this for") — no new role/permission surface introduced.
- **A real architectural fact, not a bug**: `FeatureGate` resolves an
  already-`active` subscription's entitlements from its immutable
  `SubscriptionEntitlementSnapshot`, frozen at activation. Adding
  `CUSTOM_BRANDED_SUBDOMAIN` to `pricing_plan_entitlements` today does
  nothing for any subscription whose snapshot predates the key — it
  silently resolves "not entitled" (a logged warning, not an error) until
  that subscription's next real commercial event. This was surfaced and a
  deliberate rollout mechanism was approved rather than working around it
  — see "Entitlement rollout" below.

### Entitlement architecture

- `Feature::CUSTOM_BRANDED_SUBDOMAIN` / `Feature::CUSTOM_DOMAIN` — boolean
  feature flags, `EntitlementCategory::FEATURE`, added to the existing
  registry in `App\Support\Entitlements\Feature`. No plan-name string
  comparison exists anywhere in any controller or frontend component —
  every check is `FeatureGate::allows($organization, Feature::CUSTOM_BRANDED_SUBDOMAIN)`.
- **Initial plan mapping** (approved 2026-08-27, migration
  `2026_08_27_000001_add_custom_url_branding_entitlements_to_plans.php`):
  `CUSTOM_BRANDED_SUBDOMAIN` — Essential `false`, Professional `true`,
  Enterprise `true`. `CUSTOM_DOMAIN` — `false` for every plan (Super
  Admin-managed only this phase).
- **Configurability**: entirely through the existing
  `pricing_plan_entitlements` table / Pricing Management entitlement
  editor (Phase G2) — a Super Admin can flip either key for any plan with
  zero code changes. Enterprise contractual overrides and grandfathered
  organisations continue through the pre-existing, untouched
  `EntitlementOverrideRepository`/override-resolution step in
  `FeatureGate` (checked BEFORE the snapshot/plan-default resolution, so
  it's unaffected by anything in this phase). Trial behaviour is likewise
  untouched — `PlanEntitlements::trialProfile()` doesn't include this key,
  so a trialing organisation resolves "not entitled" by default (a
  deliberately conservative choice — trial does not need a bespoke
  decision this phase).

### Entitlement rollout (existing subscriptions)

`App\Console\Commands\RefreshEntitlementSnapshotsForCapabilityRollout`
(`entitlements:refresh-capability-rollout`) — a one-time, manually
triggered, reviewable command, never auto-run from a migration, seeder,
scheduler, or deployment entrypoint:

- Targets only subscriptions whose `SubscriptionAccessPolicy`-resolved
  mode is `FULL`/`GRACE` (i.e. genuinely entitled today — never
  `NONE`/`RESTRICTED`/`TRIAL`) AND whose `plan_code_snapshot` is
  `professional` or `enterprise`.
- Writes a NEW `SubscriptionEntitlementSnapshot` via
  `EntitlementSnapshotService::snapshotForEntitlementRollout()` — a
  deliberately distinct `source_transition`
  (`subscription.entitlement_rollout`) from every real commercial-event
  transition, so it's never confused with an activation/upgrade/downgrade
  in any report or integrity check.
- The regenerated snapshot contains the organisation's FULL current live
  plan-entitlement set (`EntitlementSnapshotService::buildEntitlementsPayload()`,
  reused unchanged, exposed as `public` specifically for this command's
  dry-run comparison) — not a hand-merged single key. If any OTHER plan
  entitlement had drifted in `pricing_plan_entitlements` since a given
  subscription's original snapshot, this rollout also picks that up. This
  was an explicit, approved trade-off, not an oversight.
- `--dry-run` reports eligible/would-change/unchanged/skipped/failed
  counts without writing anything; a real run requires `--confirm` or an
  interactive confirmation. Idempotent (re-running reuses the existing
  rollout snapshot for an unchanged subscription rather than duplicating
  it). Each subscription is processed independently — one failure never
  aborts the batch.
- Never touches `Feature::CUSTOM_DOMAIN`.
- Recommended production flow: deploy → `--dry-run` → review → confirmed
  run → verify `FeatureGate` now returns `true` for an existing eligible
  organisation → confirm Essential/​`CUSTOM_DOMAIN` unchanged.

### Authorisation

- `App\Http\Controllers\Api\OrganizationBrandingUrlController`
  (`GET`/`PUT`/`DELETE /organization/url-slug`) — scoped exclusively to
  `$request->user()->organization`, never a caller-supplied organisation
  id. Super Admin/Admin get a 422 ("no organisation of their own"),
  matching `getBranding()`/`updateBranding()`'s exact existing precedent.
  A Client user only ever affects their own organisation — there is no
  code path that accepts any other.
- The hostname is never an authorisation boundary here either — this
  controller resolves everything from the authenticated session, never
  from any Host header.

### Shared mutation service

`App\Services\Organizations\OrganizationUrlSlugService` — the ONE place
`validateCandidate()` (format/reserved/uniqueness/cross-organisation
history reuse — identical rules Phase 2 already established) and
`apply()` (history capture + persist + Activity Log) live. Both
`OrganizationController::updateUrlSlug()`/`removeUrlSlug()` (Super Admin)
and `OrganizationBrandingUrlController::update()`/`destroy()` (customer)
call this same service — neither duplicates the other's logic. `apply()`
takes an explicit `$actorType` (`'super_admin'` / `'organisation_customer'`)
recorded in the Activity Log, so Super Admin audit review can always tell
which path a change came through. If a customer changes their slug, it is
immediately visible on the Super Admin organisation page (same
`organizations.url_slug` column, same `OrganizationUrlSlugHistory` table —
never two sources of truth).

### Customer API

- `GET /organization/url-slug` → `{ url_slug, entitled, preview_url }` —
  always available to any Client user (even without entitlement — needed
  to render the UI's own upgrade-state/no-entitlement messaging).
- `PUT /organization/url-slug` → requires `Feature::CUSTOM_BRANDED_SUBDOMAIN`;
  a non-entitled organisation gets a customer-safe 403 (never
  `FeatureNotEntitledException`'s own internal message, which names the
  organisation id and feature key verbatim — see that exception's
  docblock on why it must never reach an HTTP response directly).
- `DELETE /organization/url-slug` → deliberately NOT gated by entitlement
  — an organisation that has lost access (plan downgrade, lapsed
  subscription) may still turn its own existing branded URL off. See
  "Subscription-state behaviour" below for the full approved policy.

### Company Branding UI

`frontend/src/components/settings/CustomUrlSection.tsx`, mounted in
`(dashboard)/settings/page.tsx`'s Company Branding tab, directly after the
Display Name/Description/Tagline identity fields and before Accent
Colour. States: no entitlement + no slug (calm upgrade prompt, no
mutation controls rendered at all — matches "backend enforces, UI doesn't
merely hide"); entitled + no slug (input + live preview); entitled + slug
set (current URL, Open/Change/Remove); slug set but entitlement since
lost (URL still shown and still openable/removable, "Change" hidden,
explanatory copy); saving/removing (disabled controls, preserved input);
inline, customer-safe validation errors sourced directly from the
backend's own response — no validation rule is duplicated in the
frontend. Confirmation dialogs (plain language, no infrastructure
terms — "your customer-facing links will use X going forward" / "links
already sent to customers will keep working") appear before both Save and
Remove.

### Custom-domain capability boundary

`Feature::CUSTOM_DOMAIN` exists and is wired nowhere in any customer-facing
controller or UI this phase — deliberately. Real DNS verification/TLS/
support workflows for Bring Your Own Domain still require Super Admin
involvement (see Phase 2's `DomainVerificationService`). The entitlement
key and the Super Admin domain-management surface (`OrganizationDomainController`,
built in Phase 2) are both already in place, so a future phase can enable
customer self-service purely by (a) configuring this key `true` for
eligible plans and (b) building the customer-facing UI/endpoints — no
further backend architecture change required. **This phase does not claim
customer-owned-domain self-service exists** — it remains Super
Admin-only.

### Subscription-state behaviour (approved policy)

Reuses `SubscriptionAccessPolicy`/`FeatureGate` entirely — no new access
policy was invented:

- `FeatureGate::allows()` already resolves `NONE`/`RESTRICTED` (no
  subscription, cancelled, suspended, unpaid, or `past_due` past its grace
  window) to "not entitled" for ANY feature key, automatically blocking
  `PUT /organization/url-slug` in every one of those states — no extra
  code needed in the controller.
- **Existing branded links remain operational even after entitlement is
  lost** — `OrganisationUrlGenerator`/`OrganisationHostResolver` read
  `organizations.url_slug`/`OrganizationDomain` directly and NEVER consult
  `FeatureGate` — entitlement loss cannot retroactively break an
  already-generated/emailed link.
- **Mutation (set/change) is blocked without entitlement; removal remains
  available regardless** — confirmed by
  `test_customer_removal_is_allowed_even_without_entitlement` and
  `test_restricted_subscription_blocks_setting_but_existing_url_still_resolves`.
- **The URL generator never falls back immediately on entitlement loss**
  — it has no entitlement dependency to fall back FROM; only an explicit
  removal (customer or Super Admin action) changes what it resolves.

### Activity Log

Reuses the existing `organization.url_branding_created`/`_changed`/
`_removed` actions unchanged — `apply()`'s metadata now additionally
records `actor_type` (`super_admin` / `organisation_customer`) and
`changed_by` (the acting user id, whichever side). Never logs signed
links, customer tokens, or full email URLs.

### Testing

`tests/Feature/OrganisationUrlBrandingCustomerSelfServiceTest.php` — 21
tests: entitlement (entitled/non-entitled/no-subscription, GET reporting
accuracy), authorisation (Super Admin/Admin denied, cross-organisation
isolation, customer cannot reach Super Admin domain endpoints), slug
lifecycle via the customer path (create/change/remove, history recorded,
cross-organisation reuse blocked, reserved-name rejection, org-name-change
doesn't affect slug, removal allowed without entitlement), subscription
state (a cancelled subscription blocks mutation but the existing slug's
public link still resolves), link generation (a customer-set slug is used
in newly generated links), and the rollout command (dry-run makes no
writes, creates a new snapshot for a subscription missing the key,
idempotent on re-run, never touches Essential-plan subscriptions or
`CUSTOM_DOMAIN`, skips a cancelled subscription).

`tests/Unit/Entitlements/FeatureTest.php` updated (not left stale) from
eleven to thirteen approved registry keys.

Full backend suite (2368 tests, up from 2347): **zero regressions** — the
same 4 pre-existing, already-documented environment failures as every
prior phase's report, unchanged. Frontend `tsc --noEmit`, ESLint (on the
new file — zero errors/warnings; five pre-existing errors in the
surrounding `settings/page.tsx` file predate this change and are
unrelated), and a full production build (`npm run build`, including
`/app/settings`) all pass. Marketing app `tsc --noEmit` clean (untouched
this phase). `git diff --check` clean.

### Known limitations

- Customer-owned domain self-service does not exist — Super Admin-only,
  by design this phase.
- The one-time entitlement rollout command has not been run against a
  real production database in this environment (no live DB connectivity
  here) — verified exclusively via the automated test suite's realistic
  snapshot fixtures.
- No FAQ entry was added (reviewed — the Company Branding help page's own
  new section already covers the customer-facing question clearly).

## Phase 4 — Complete White-Label Branding Experience

Makes the experience around a branded hostname feel organisation-owned
end to end — login, browser metadata/favicon/OG, public-page accent,
loading, error pages, email button copy, and a Company Branding preview
panel — while SureSign stays visibly the platform ("Powered by
SureSign"). Purely additive on top of Phases 1–3: `OrganisationHostResolver`,
`OrganisationUrlGenerator`, `PublicOrganisationBrandingController`'s core
resolution logic, and Phase 3's entitlement gating are all unchanged.
Cloudflare/SSL/DNS automation, certificate provisioning, domain health
scheduling, and domain analytics remain explicitly out of scope — a
future infrastructure operations phase.

### The one resolved architecture question: branded login

`frontend/` (the authenticated app) stays permanently fixed-host — this
was never reopened. The branded login experience lives entirely in
`marketing/`, collects **no credentials at all** (not even an email
field — `marketing/src/components/login/LoginGateway.tsx`), and hands off
to `frontend/`'s real, completely unmodified `/login` via one query
parameter, `brandHost` (the visited hostname, nothing else). This was a
deliberate, explicit design constraint (not merely convenient):
`backend/config/cors.php` already documents "the marketing site... never
authenticates against the app API" — a boundary Phase 4 respects rather
than works around. `useAuthStore`'s existing-but-never-called `setToken()`
method was considered as a possible bridge mechanism and rejected for the
same reason — it would require marketing to obtain a real auth token
itself.

`brandHost` is treated as untrusted input throughout: validated by an
identical strict hostname-only regex on both sides
(`marketing/src/lib/hostnameValidation.ts` /
`frontend/src/lib/hostnameValidation.ts` — two small files, not a
cross-app import; see below for why), never used as a navigation/redirect
target anywhere, resolved only through the existing, unmodified
`GET /public/organisation-branding/{host}` endpoint, and stripped from
`frontend/`'s visible URL via `history.replaceState` immediately after
being read — it never lingers in the address bar/history, is never
logged, and is never referenced again once the real `login()` call
succeeds. `frontend/src/app/login/page.tsx`'s existing `login()` call,
token storage, and post-login role-based redirect are completely
untouched — this is the *only* place in the authenticated app that ever
reads a hostname-shaped value from the URL for branding purposes.
Everywhere else in `frontend/`, organisation branding continues to come
exclusively from the authenticated user's own `organization.branding` via
`/auth/me` — never from any hostname, before or after this phase.

### Host classification moves to the edge: `proxy.ts`

`marketing/src/proxy.ts` is new — Next.js 16.2 deprecated the
`middleware.ts` file convention in favour of `proxy.ts`
(https://nextjs.org/docs/messages/middleware-to-proxy); this was
discovered empirically during Batch 1 (a `middleware.ts` at the project
root doesn't even load when the app uses a `src/` directory — it silently
produces a `MODULE_UNPARSABLE` build error until moved to `src/middleware.ts`,
and even then Next logs a deprecation warning and recommends `proxy.ts`,
which enforces the Node.js runtime unconditionally — a `runtime` route
config is actually disallowed on a Proxy file, unlike the old middleware
convention). Scope is deliberately narrow: recognise the default/platform
host (a zero-lookup allowlist fast path — the overwhelming common case),
otherwise resolve the host through the existing branding endpoint and
either pass through untouched or `NextResponse.rewrite()` to a new neutral
`marketing/src/app/branding-not-found/page.tsx`. It never authenticates,
never resolves tenant permissions, never mutates branding state, never
generates a customer URL, and never makes more than one backend call per
request.

**Tri-state resolution, not boolean** — `marketing/src/lib/organisationBrandingServer.ts`'s
`fetchOrganisationBrandingServer()` returns `resolved` / `not_found` (an
authoritative, clean 404 from the backend) / `unavailable` (network
error, timeout, or unexpected status). Only `not_found` triggers the
neutral `branding-not-found` rewrite; `unavailable` always fails OPEN to
the ordinary default experience. This distinction is the whole point of
the tri-state design — a backend outage must never make every branded
organisation look nonexistent. **Verified empirically, not assumed**, in
the dev Docker Compose stack:
1. Default host → zero backend calls, immediate pass-through.
2. A branded-looking host with the backend up (platform branding fully
   off in this environment, so the backend authoritatively 404s) →
   correctly rewritten to `branding-not-found`.
3. `docker compose stop backend`, same host → correctly degrades to the
   ordinary default site (not a false 404) — confirmed by direct
   before/after title comparison.
4. `docker compose start backend`, same host, no marketing rebuild →
   correctly recovers to the authoritative `branding-not-found` rewrite
   again.

Duplicate-lookup review: `fetchOrganisationBrandingServer()` is wrapped in
React's `cache()` (per-request memoisation across `generateMetadata()`,
the page component, and its `opengraph-image.tsx`/`icon.tsx` siblings in
the same render) and its underlying `fetch()` uses Next's own Data Cache
(`next: { revalidate: 60 }`). `proxy.ts` and the route handler that
follows it are genuinely separate invocations with no shared React
`cache()` scope, so `proxy.ts`'s own call is one accepted, documented
remaining duplicate per request — cheap in practice because it's still a
warm Next Data Cache hit in the common case, not a second real round
trip. No custom request-context framework was built to eliminate this one
inexpensive call, per the reviewed guidance not to over-engineer it away.

### Branding cache invalidation and `branding_version`

Reuses `BrandingSetting`/`Organization`'s own `updated_at` as
`branding_version` (`PublicOrganisationBrandingController::show()`) —
no new database column. `logo_url` carries it as `?v={branding_version}`
for browser cache-busting. `App\Support\Organizations\BrandingCacheInvalidator`
(new, small, best-effort/non-fatal — mirrors `AiCreditWorkflowLifecycle`'s
own "never fail the real operation" contract) forgets the specific
`org-branding:{host}` cache key(s) for an organisation's known hosts
(current `url_slug` host + every `organization_domains` row) — called
from `OrganizationController::updateBranding()`/`uploadLogo()`,
`OrganizationUrlSlugService::apply()` (both the previous AND new host —
either could still be serving a stale cached response), and
`DomainVerificationService::activate()`/`disable()`/`reactivate()`/
`remove()`. `marketing/`'s own fetch-side cache for this one endpoint is
deliberately shortened to `revalidate: 60` (not the backend's 600s) —
the backend already owns real invalidation on a save; this just bounds
how long the marketing-side fetch cache can keep serving a
pre-invalidation response, without needing a cross-app protected
revalidation callback (considered and rejected as unnecessary complexity
for what a short revalidate window already solves).

### Metadata, favicon, OG image

`marketing/src/lib/brandedMetadata.ts`/`brandedOgImage.tsx`/`brandedIcon.tsx`
are shared helpers behind `generateMetadata()` + per-segment
`opengraph-image.tsx`/`icon.tsx` on the 3 branded token routes
(`appointments/[token]`, `consultations/[token]`, and
`consultations/[token]/summary` — which inherits its parent segment's
icon/OG image per Next's own convention, so it needed no separate files).
All three resolve branding from the *request's own Host header only* —
never a query parameter, never a client-suppliable organisation name or
image URL — and fall back to the exact pre-Phase-4 default SureSign
title/image/favicon whenever branding isn't cleanly `resolved`, regardless
of *why* (unbranded, `not_found`, or `unavailable` all produce the
identical, single fallback).

### Loading, public-page accent, email label

`StateScreens.tsx`'s `LoadingSkeleton` shows the organisation's logo
instead of the generic pulsing bar when `useOrganisationBranding()`
already has a cached result — this only ever helps the 3 experience
components' own internal `state.kind === 'loading'` case (a repeat
navigation within the same cached session), not the outer Suspense
fallback in each page, which renders before the branding provider even
mounts and stays generic — documented, not a defect.

`OrganisationBrandingProvider` now also sets a scoped CSS custom-property
override (`--accent`/`--accent-fg`, luminance-checked for a readable
foreground) on a wrapping `<div>` around its children — every existing
`bg-accent`/`text-accent-fg` element already inside that subtree
picks up the organisation's accent colour automatically. Deliberately
just this one variable pair, nothing else — "accent only, never a full
re-theme."

`EmailNotificationService::send()` gained a destination-aware default
action label: "Open {Org} Workspace" only when the resolved `action_url`
is genuinely a `{frontend_url}/app/...` customer workspace destination —
never for `/admin/...`, a support destination, no URL at all, or when the
caller already asked for a specific non-default label. The check is a
string-prefix match against `actionMeta()`'s own existing convention (it
always builds `action_url` this way already) rather than a new URL
classification service — `actionMeta()`'s signature and its ~30 existing
call sites are completely untouched. `BrandingService::forOrganization()`
already encapsulates the `feature_white_label` gate — reused directly,
never re-checked independently, and an organisation with no branding row
still gets its own name in the label (this is organisation-specific
customer-facing context, not a white-label-exclusive feature).

### Error pages

`marketing/src/app/not-found.tsx` (a genuine unknown PATH, server-rendered,
branding resolved from the request Host the same way `generateMetadata()`
does) and `error.tsx` (Next's error-boundary convention forces this to be
a Client Component, so it resolves branding client-side instead, the same
way `LoginGateway`/`OrganisationBrandingBadge` already do). Both fall back
to the plain SureSign experience whenever branding isn't cleanly resolved,
and render only branding-safe fields — no hostname-resolution internals,
no ids. The "unknown branded *hostname*" case (as opposed to an unknown
path) is a different page entirely — `proxy.ts`'s own
`branding-not-found` rewrite from earlier in this section.

`not-found.tsx` required `export const dynamic = 'force-dynamic'` —
without it, `next build` fails to prerender the special `/_not-found`
shell (`headers()` has no real request context available during static
generation). Discovered and fixed during this phase's own build
verification, not merely assumed to work from dev-mode testing alone.

### Company Branding Preview panel

A new "Branding Preview" tab in `frontend/src/app/(dashboard)/settings/page.tsx`
(`frontend/src/components/settings/BrandingPreviewPanel.tsx`), reusing the
page's own existing live, unsaved `brandForm` state — reacts immediately
to a logo/colour/name edit, no network round trip, matching the existing
colour picker's own "live preview, not persisted" convention. Restrained
to exactly 4 targets (Workspace / Login / Public page / Email) with
Desktop/Tablet/Mobile as viewport-width toggles over the *same* preview,
and Normal/Loading/Error as state toggles within the Public Page preview
only — no additional preview targets were added. Every preview is a
small, clearly-labelled, **non-functional visual approximation** rather
than a live embed of the real production component in every one of the
4 cases (not just Login) — `marketing/` and `frontend/` are separate
Next.js applications with no shared-package/workspace convention anywhere
in this repo (confirmed: no `packages/` directory, no `workspaces` key in
either `package.json`), and even the real in-app Workspace sidebar is a
live, interactive component wired to auth/navigation state that a preview
must never risk triggering. Approximating all 4 consistently was chosen
over mixing "real" and "approximated" previews.

### Security review

Branding remained presentation-only throughout this phase — nothing here
changes authenticated organisation ownership, public-token ownership,
signed-link validation, or any existing policy/entitlement check; a
hostname never authorises anything, in `proxy.ts` or anywhere else.
`brandHost`'s full untrusted-input handling is covered above. The one
new customer-facing surface with a real security property worth calling
out explicitly: `branding-not-found`/`not-found.tsx` render *only* the
same branding-safe fields `PublicOrganisationBrandingController` already
returned pre-Phase-4 (organisation name, logo URL, accent colour) — never
an internal id, never a hostname-resolution detail, never a hint about
which other hostnames DO resolve.

### Testing

New: `backend/tests/Feature/OrganisationUrlBrandingPhase4EmailLabelTest.php`
(7 tests — workspace destination gets the branded label; an `/admin/...`
destination keeps the generic label; no action URL keeps the generic
label; no organisation keeps the generic label; an explicit caller label
is never overridden; an organisation with no branding row still gets its
own name; `feature_white_label` disabled still uses the organisation's
name, never falls back to a literal "SureSign" string). All pass, plus
the existing `CommunicationsPlatformBatch4Test` (15 tests) confirmed
unaffected.

Full backend suite (2375 tests): **zero regressions attributable to this
phase** — the 6 failures present are the same pre-existing,
already-documented environment issues from prior phases (a
`docker-compose.prod.yml` path lookup, Redis-presence-dependent
`ApplicationMonitoringMetricsTest`/`UserPresenceServiceTest`, and the
already-documented `PaymentApplicationExcelDisclosureTest`/
`SupportTicketControllerTest` storage-permission cases).

`marketing/` and `frontend/` `tsc --noEmit` and `eslint`: clean on every
new file this phase touched (`LoginGateway.tsx` needed one fix during
verification — `react-hooks/set-state-in-effect`, resolved by routing
both branches of its host-resolution effect through the same promise
chain rather than an early synchronous `setState`). Pre-existing lint
findings in files this phase touched only incidentally
(`settings/page.tsx`'s several pre-existing `react-hooks/set-state-in-effect`/
`no-explicit-any` instances, `login/page.tsx`'s pre-existing `setReady(true)`/
`err: any`) are unchanged — the exact "pre-existing pattern being matched,
not a new issue" precedent this doc's own Phase 3 section already
establishes.

**Correction (recovery task, same day)**: this phase's own build
verification originally reported `next build` failing to prerender
`/_global-error` in both apps (`Cannot read properties of null (reading
'useContext')`, digest `2210570694`) and flagged it as a pre-existing
production blocker. **That was a false alarm caused by the verification
method, not a real defect.** Root cause: `docker-compose.dev.yml` sets
`NODE_ENV=development` on the long-running `marketing`/`frontend`
containers (required for `npm run dev`); the verification ran `npm run
build` via `docker exec` *inside those same containers*, inheriting the
leaked `NODE_ENV=development`. Proven with a 3-way isolation test on
`marketing` (`NODE_ENV=production` → succeeds; `NODE_ENV=development` →
fails with the exact same digest; `NODE_ENV` unset, matching the real
Dockerfile's `builder` stage exactly — neither Dockerfile sets `NODE_ENV`
before `RUN npm run build` — → succeeds). Confirmed conclusively with two
real `docker build` runs of `marketing/Dockerfile` (one clean, one hit an
unrelated transient `@next/swc` native-binding fetch flake on the first
attempt, gone on retry with `--no-cache`) and one clean `docker build` of
`frontend/Dockerfile` on the first try — **the actual production Docker
build pipeline for both apps was never affected**. No code, config, or
architecture change was made — there was nothing to fix. Always validate
a production build via the real `Dockerfile`/`docker build`, never via
`docker exec ... npm run build` inside the dev-mode container.

### Known limitations

- No live Dokploy/production-environment validation was performed — only
  the dev Docker Compose stack, as documented above. Do not read the
  Batch 1 verification steps as production-equivalent.
- The Company Branding Preview's Login/Public-page/Email panels are
  visual approximations, not literal embeds of the real production
  components — see the Preview section above for why.
- No automated accessibility audit tool was run against any new page.
- Email client rendering (Outlook/Gmail/Apple Mail) for the new
  destination-aware label was not manually verified across real clients —
  only the existing `EmailComponents`/`buildEventBodyHtml()` rendering
  path, entirely unchanged, carries it.
