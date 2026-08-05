<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Services\EmailNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Organisation URL Branding, Phase 4 — EmailNotificationService::send()'s
 * destination-aware "Open {Org} Workspace" action label. Covers every
 * case in the refined design: only a genuine /app/... workspace
 * destination gets relabelled, an explicit caller-supplied label (even
 * one that happens to equal the generic default's exact string) is never
 * silently overridden by coincidence in a way that changes intent, an
 * /admin/... destination keeps the generic label, and no organisation
 * keeps the generic label.
 */
class OrganisationUrlBrandingPhase4EmailLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
            'notification_settings' => ['variation.approved'],
            'feature_white_label' => true,
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'fake-id'], 201)]);
    }

    private function orgWithBranding(string $displayName): Organization
    {
        $org = Organization::create(['name' => 'Legal Name Ltd', 'slug' => 'legal-name-' . uniqid(), 'timezone' => 'Europe/London', 'email' => 'org@example.com']);
        BrandingSetting::create(['organization_id' => $org->id, 'company_display_name' => $displayName]);
        return $org;
    }

    public function test_workspace_destination_gets_organisation_branded_label(): void
    {
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            $org,
        );

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'Open Acme Construction Workspace'));
    }

    public function test_admin_destination_keeps_generic_label(): void
    {
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/admin/organizations/1'),
            $org,
        );

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];
            return str_contains($html, 'View in SureSign') && !str_contains($html, 'Open Acme Construction Workspace');
        });
    }

    public function test_no_action_url_keeps_generic_label_and_renders_no_button(): void
    {
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send('variation.approved', 'Variation Approved', 'Body.', [], $org);

        Http::assertSent(fn ($request) => !str_contains($request->data()['htmlContent'], 'Open Acme Construction Workspace'));
    }

    public function test_no_organisation_keeps_generic_label(): void
    {
        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            null,
        );

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];
            return str_contains($html, 'View in SureSign') && !str_contains($html, 'Workspace');
        });
    }

    public function test_explicit_caller_label_is_never_overridden(): void
    {
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial', 'View Variation'),
            $org,
        );

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];
            return str_contains($html, 'View Variation') && !str_contains($html, 'Open Acme Construction Workspace');
        });
    }

    public function test_organisation_with_no_branding_row_still_gets_org_name_label(): void
    {
        $org = Organization::create(['name' => 'Bare Org Ltd', 'slug' => 'bare-org-' . uniqid(), 'timezone' => 'Europe/London', 'email' => 'bare@example.com']);

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            $org,
        );

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'Open Bare Org Ltd Workspace'));
    }

    /**
     * Organisation URL Branding, Phase 5 (Stage 4, Part E) — a genuine
     * bug found in the audit: the relabelling above already existed, but
     * the underlying action_url still pointed at the fixed app host even
     * for a fully branded organisation. Confirms the actual href now
     * reflects the organisation's own branded subdomain.
     */
    public function test_workspace_destination_url_uses_organisations_branded_slug(): void
    {
        Config::set('suresign.frontend_url', 'https://app.suresigncontracts.app');
        Config::set('organisation_branding.root_domain', 'suresigncontracts.app');
        $org = $this->orgWithBranding('Acme Construction');
        $org->update(['url_slug' => 'acme-co']);

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            $org,
        );

        Http::assertSent(fn ($request) => str_contains(
            $request->data()['htmlContent'],
            'https://acme-co.suresigncontracts.app/app/projects/1/commercial'
        ));
    }

    /**
     * An unbranded organisation (no active custom domain, no url_slug, or
     * root_domain unconfigured) must fall back unchanged to the fixed app
     * host — never a broken/partial URL.
     */
    public function test_unbranded_organisation_url_falls_back_to_fixed_app_host(): void
    {
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            $org,
        );

        Http::assertSent(fn ($request) => str_contains(
            $request->data()['htmlContent'],
            rtrim(config('suresign.frontend_url'), '/') . '/app/projects/1/commercial'
        ));
    }

    public function test_feature_white_label_disabled_still_gets_org_name_label_not_suresign(): void
    {
        // BrandingService::forOrganization() returns null when
        // feature_white_label is off, but BrandingService::displayName()
        // already falls back to the organisation's own name in that case
        // — never to the literal string "SureSign" — so the label is
        // still organisation-specific customer-facing context, not a
        // white-label-only feature. This is a deliberate design point
        // (see Phase 4 plan's Batch 3 section), verified explicitly here.
        SuresignSetting::instance()->update(['feature_white_label' => false]);
        $org = $this->orgWithBranding('Acme Construction');

        EmailNotificationService::send(
            'variation.approved',
            'Variation Approved',
            'Body.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial'),
            $org,
        );

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'Open Legal Name Ltd Workspace'));
    }
}
