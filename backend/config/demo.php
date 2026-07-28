<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Environment Connection
    |--------------------------------------------------------------------------
    |
    | The Eloquent/database connection name the demo seeders and console
    | commands must run against. Never the default connection — this is what
    | keeps demo:seed / demo:reset from ever touching real customer data.
    | See config/database.php's 'demo' connection.
    */
    'connection' => 'demo',

    /*
    |--------------------------------------------------------------------------
    | Demo Environment Storage Root
    |--------------------------------------------------------------------------
    |
    | Isolated filesystem root for demo-generated files (payment application
    | workbooks etc.), mirroring the database connection's isolation — see
    | App\Support\Demo\DemoStorage. Never the same root as real customer
    | uploads/generated documents. Overridable via DEMO_STORAGE_ROOT for a
    | genuinely separate demo deployment later.
    */
    'storage_root' => env('DEMO_STORAGE_ROOT', storage_path('app/demo-private')),

    /*
    |--------------------------------------------------------------------------
    | Demo Anchor Date
    |--------------------------------------------------------------------------
    |
    | The demo environment's own notion of "today" — see App\Support\Demo\
    | DemoClock for the full rationale. Every authored Story class was
    | written against this date; anything evaluating the environment's
    | current state relative to a date (demo:validate's Business signals,
    | future Notification wiring) must compare against this, not real
    | wall-clock time, or the story silently drifts as real time passes.
    |
    | Override via DEMO_ANCHOR_DATE when the environment is eventually
    | rolled forward (all Story class dates shifted by the same number of
    | days) — not yet implemented; today this only stops the *validation
    | tooling* from drifting. See internal-docs' Demo Freeze section.
    */
    'anchor_date' => env('DEMO_ANCHOR_DATE', '2026-07-22'),

    /*
    |--------------------------------------------------------------------------
    | Demo Environment Version
    |--------------------------------------------------------------------------
    |
    | Internal metadata only — never exposed to customers. Lets developers
    | tell at a glance which build of the demo story/data a given screenshot,
    | doc page, or sales recording was captured against, and whether it's
    | still current. Bump 'version' and 'last_updated' whenever a phase adds
    | or materially changes demo data; update 'feature_coverage' as phases
    | land; bump 'platform_version_compatibility' if a platform change
    | (schema, workflow) would make older demo data behave differently.
    |
    | v1.0.0 marks the environment as production-ready for marketing asset
    | capture: the full seven-project portfolio is built, demo:validate
    | passes with 0 errors, storage and database are both isolated, and the
    | anchor-date strategy is in place. See internal-docs/demo-environment
    | for the full v1.0 definition and the Demo Manifest/Freeze workflow.
    */
    'version' => [
        'version' => '1.0.0',

        'story_timeline' => 'Halden Grove Construction Ltd. with the full seven-project '
            . 'portfolio from the approved blueprint: Kingsmill Logistics Hub (recently '
            . 'awarded, contract unsigned), Elmsworth Care Home Extension (pre-construction, '
            . 'commencing in 3 weeks), Northgate Business Units (early construction, month 2 '
            . 'of 12), Riverside Wharf (mid-project, month 9 of 18), Aldermere Distribution '
            . 'Centre (operationally difficult but professionally managed — overdue payment, '
            . 'unresolved RFIs, disputed variation, overdue EOT decision), Coldfield Retail '
            . 'Park (near completion — Practical Completion just achieved, retention pending), '
            . 'and Priory Court Apartments (fully completed — agreed final account, retention '
            . 'released, closed adjudication case). The full construction lifecycle, start to '
            . 'finish, is now represented.',

        'feature_coverage' => [
            'organization_profile' => true,
            'branding' => true,
            'users_and_roles' => true,
            'projects' => true,
            'contracts' => true,
            'contract_ai_analysis' => true,
            'trade_packages' => true,
            'programme' => true,
            'risks' => true,
            'commercial_workflows' => true,
            'final_accounts' => true,
            'retention_releases' => true,
            'closeouts' => true,
            'adjudication' => true,
            'site_management' => true,
            'documents' => true,
            'appointments' => true,
            'full_project_portfolio' => true,
            'storage_isolation' => true,
            'anchor_date_strategy' => true,
            'demo_manifest' => true,
            'notifications' => false,
            'branding_assets' => false,
            'sample_files' => false,
        ],

        'last_updated' => '2026-07-22',

        // The minimum platform schema/feature set this demo data assumes.
        // Bump when a platform change would make this version of the demo
        // data incomplete or inconsistent (e.g. a new required column).
        'platform_version_compatibility' => 'V1.0.0',
    ],

];
