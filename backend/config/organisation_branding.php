<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organisation URL Branding — Root Domain
    |--------------------------------------------------------------------------
    |
    | The registrable domain organisation-branded hostnames are minted under,
    | e.g. "suresigncontracts.app" so an organisation with url_slug
    | "star-affinity" resolves to "star-affinity.suresigncontracts.app".
    |
    | Deliberately explicit config, never derived/guessed from MARKETING_URL's
    | own host (e.g. by stripping a leading "www."/"app." label) — that kind
    | of string surgery is fragile and silently wrong for any host shape that
    | doesn't match the assumption. When this is null, App\Services\
    | OrganisationUrlGenerator always falls back to the existing marketing
    | site host — organisation URL branding is fully OFF platform-wide,
    | regardless of any organisation's configured url_slug, until an operator
    | deliberately sets this.
    |
    | Local development: set to "localhost" (see docs/deployment section on
    | Organisation URL Branding) — browsers resolve any "*.localhost" host to
    | 127.0.0.1 natively, so "star-affinity.localhost:3001" works with zero
    | /etc/hosts edits.
    |
    */

    'root_domain' => env('ORGANISATION_BRANDED_ROOT_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Hostnames
    |--------------------------------------------------------------------------
    |
    | Labels no organisation may claim as its url_slug — platform
    | infrastructure names, product surfaces, and common abuse targets.
    | Checked case-insensitively (App\Support\Organizations\UrlSlugValidator
    | normalises to lowercase before comparing). Extend this list rather than
    | hardcoding exceptions elsewhere.
    |
    */

    'reserved_hostnames' => [
        'www', 'app', 'api', 'admin', 'superadmin', 'auth', 'login', 'signup',
        'docs', 'support', 'status', 'mail', 'email', 'smtp', 'ftp', 'cdn',
        'assets', 'static', 'files', 'storage', 'media', 'billing', 'payments',
        'checkout', 'stripe', 'google', 'calendar', 'consultancy',
        'appointments', 'adjudication', 'marketing', 'root', 'system', 'null',
        'localhost', 'blog', 'help', 'demo', 'test', 'staging', 'dev',
        'development', 'production', 'sandbox', 'internal', 'suresign',
        'suresigncontracts',
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer-Owned Domains (Bring Your Own Domain) — Phase 2
    |--------------------------------------------------------------------------
    |
    | verification_txt_prefix: the DNS TXT record host label a customer must
    | create to prove ownership, e.g. "_suresign-verify.contracts.customer.com"
    | with a value of "suresign-domain-verify=<random token>" — mirrors the
    | Vercel/Netlify/GitHub Pages convention of a dedicated, namespaced TXT
    | label rather than requiring a TXT record on the domain's own apex
    | (which the customer may already be using for something else, e.g. SPF).
    |
    | cname_target: what the customer must point their CNAME at. Every
    | customer domain resolves to the SAME target regardless of which
    | organisation owns it — the actual organisation is identified at
    | request time by the Host header the marketing edge sees, exactly like
    | the branded-subdomain flow — never a per-customer target.
    |
    | Neither of these enables automatic DNS/SSL provisioning — see
    | App\Services\Organizations\DomainVerificationService's own docblock
    | and internal-docs/super-admin/organisation-url-branding.md's
    | "Customer-Owned Domains" section for what production still requires
    | (Cloudflare origin routing + certificate coverage for arbitrary
    | customer hostnames) that this codebase does not automate.
    |
    */

    'verification_txt_prefix' => env('ORGANISATION_DOMAIN_VERIFICATION_TXT_PREFIX', '_suresign-verify'),

    'cname_target' => env('ORGANISATION_DOMAIN_CNAME_TARGET'),

];
