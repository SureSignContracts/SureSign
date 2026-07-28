<?php

namespace Database\Seeders\Demo\Data;

/**
 * Single authored source of truth for the Halden Grove Construction Ltd.
 * demo company and its personas — per the approved demo environment
 * blueprint. Every demo seeder (this phase and future ones) should read
 * names/roles/org identity from here rather than re-declaring them, so the
 * story stays consistent as more phases are added.
 *
 * 'slug' is the idempotency anchor: DemoOrganizationSeeder looks up the
 * organization by this slug so demo:seed can be re-run safely without
 * creating duplicate companies.
 */
class DemoCompanyProfile
{
    public const ORGANIZATION = [
        'name' => 'Halden Grove Construction Ltd.',
        'slug' => 'halden-grove-construction',
        'contact_name' => 'Priya Chandra',
        'email' => 'info@haldengroveconstruction.com',
        'phone' => '+44 121 496 0187',
        'website' => 'https://www.haldengroveconstruction.com',
        'address' => '14 Brindley Court, Gas Street Basin',
        'city' => 'Birmingham',
        'state' => 'West Midlands',
        'postcode' => 'B1 2JB',
        'country' => 'GB',
        'currency' => 'GBP',
        'timezone' => 'Europe/London',
        'registration_number' => '07845213',
        'vat_number' => 'GB 214 5563 89',
        'is_active' => true,
        // The demo represents a company that has been live on SureSign for
        // months, not a fresh signup — onboarding is already complete.
        'is_onboarded' => true,
    ];

    public const BRANDING = [
        'primary_color' => '#1E3A5F',
        'secondary_color' => '#0F1F30',
        'accent_color' => '#D98E29',
        'font_family' => 'Inter',
        'company_display_name' => 'Halden Grove',
        'tagline' => 'Commercial construction, delivered with certainty.',
        'description' => 'Halden Grove Construction Ltd. is a commercial construction '
            . 'contractor delivering mixed-use, residential-led, and light industrial '
            . 'schemes across multiple regions, from early-stage design through to '
            . 'final account close-out.',
        // No real logo/favicon/letterhead assets exist yet — deliberately left
        // null rather than pointing at a placeholder file. See Phase 1 report
        // "future extension points": a follow-up task should add real
        // branding assets before any screenshot work begins.
        'logo_path' => null,
        'logo_dark_path' => null,
        'favicon_path' => null,
        'cover_image_path' => null,
        'letterhead_path' => null,
        'header_template_path' => null,
        'footer_template_path' => null,
    ];

    /**
     * Every persona is a Client-role (org-scoped) user — this app's role
     * model has no per-organization Admin; only Client is org-level (see
     * AGENTS.md's Admin/Super Admin platform-wide note). 'is_primary_demo_user'
     * flags the account documented in the blueprint (Daniel Okafor) as the
     * one screenshots/videos/sales demos should log in as by default — it's
     * metadata for humans following this file, not a database column.
     */
    public const USERS = [
        [
            'email' => 'priya.chandra@haldengroveconstruction.com',
            'name' => 'Priya Chandra',
            'first_name' => 'Priya',
            'last_name' => 'Chandra',
            'job_title' => 'Commercial Director',
            'is_primary_demo_user' => false,
        ],
        [
            'email' => 'daniel.okafor@haldengroveconstruction.com',
            'name' => 'Daniel Okafor',
            'first_name' => 'Daniel',
            'last_name' => 'Okafor',
            'job_title' => 'Senior Quantity Surveyor',
            'is_primary_demo_user' => true,
        ],
        [
            'email' => 'megan.fairweather@haldengroveconstruction.com',
            'name' => 'Megan Fairweather',
            'first_name' => 'Megan',
            'last_name' => 'Fairweather',
            'job_title' => 'Project Manager',
            'is_primary_demo_user' => false,
        ],
        [
            'email' => 'tom.aldridge@haldengroveconstruction.com',
            'name' => 'Tom Aldridge',
            'first_name' => 'Tom',
            'last_name' => 'Aldridge',
            'job_title' => 'Project Manager',
            'is_primary_demo_user' => false,
        ],
        [
            'email' => 'sarah.blythe@haldengroveconstruction.com',
            'name' => 'Sarah Blythe',
            'first_name' => 'Sarah',
            'last_name' => 'Blythe',
            'job_title' => 'Site Manager',
            'is_primary_demo_user' => false,
        ],
        [
            'email' => 'james.ridley@haldengroveconstruction.com',
            'name' => 'James Ridley',
            'first_name' => 'James',
            'last_name' => 'Ridley',
            'job_title' => 'Document Controller',
            'is_primary_demo_user' => false,
        ],
    ];

    public static function primaryDemoUserEmail(): string
    {
        foreach (self::USERS as $user) {
            if ($user['is_primary_demo_user']) {
                return $user['email'];
            }
        }

        throw new \RuntimeException('No primary demo user flagged in DemoCompanyProfile::USERS.');
    }
}
