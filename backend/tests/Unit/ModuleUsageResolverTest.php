<?php

namespace Tests\Unit;

use App\Services\Monitoring\ModuleUsageResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ModuleUsageResolverTest extends TestCase
{
    private function requestFor(string $path): Request
    {
        return Request::create('/api/' . ltrim($path, '/'), 'GET');
    }

    public function test_known_routes_resolve_to_stable_module_keys(): void
    {
        $this->assertSame('dashboard', ModuleUsageResolver::resolve($this->requestFor('dashboard')));
        $this->assertSame('contracts', ModuleUsageResolver::resolve($this->requestFor('projects/42/contracts')));
        $this->assertSame('payment_applications', ModuleUsageResolver::resolve($this->requestFor('payment-applications/17/certify')));
        $this->assertSame('super_admin', ModuleUsageResolver::resolve($this->requestFor('admin/storage')));
    }

    public function test_dynamic_ids_do_not_fragment_module_keys(): void
    {
        $a = ModuleUsageResolver::resolve($this->requestFor('projects/1/contracts/9/variations'));
        $b = ModuleUsageResolver::resolve($this->requestFor('projects/482/contracts/3/variations'));

        $this->assertSame($a, $b);
        $this->assertSame('variations', $a);
    }

    public function test_deepest_matching_segment_wins_over_the_generic_parent(): void
    {
        // /projects/{id}/contracts starts with the generic "projects"
        // segment but the more specific "contracts" segment that follows
        // is the more useful module to report.
        $this->assertSame('contracts', ModuleUsageResolver::resolve($this->requestFor('projects/42/contracts')));
    }

    public function test_query_strings_do_not_affect_module_key(): void
    {
        $withQuery = Request::create('/api/reports?range=30d&sort=desc', 'GET');
        $this->assertSame('reports', ModuleUsageResolver::resolve($withQuery));
    }

    public function test_excluded_routes_return_null(): void
    {
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('up')));
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('notifications')));
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('notifications/unread-count')));
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('admin/application-monitoring')));
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('auth/me')));
    }

    public function test_unknown_routes_return_null_safely(): void
    {
        $this->assertNull(ModuleUsageResolver::resolve($this->requestFor('some/totally-unmapped/endpoint')));
    }

    public function test_labels_are_distinct_from_storage_keys(): void
    {
        $this->assertSame('Payment Applications', ModuleUsageResolver::label('payment_applications'));
        $this->assertSame('Trade Packages', ModuleUsageResolver::label('trade_packages'));
    }

    /**
     * Confirms the routes discovered as previously-unmapped (a repository
     * audit run alongside Appointments/Billing/Application Monitoring
     * fixes) now resolve to a real module key instead of null.
     */
    public function test_previously_unmapped_real_routes_now_resolve(): void
    {
        $this->assertSame('appointments', ModuleUsageResolver::resolve($this->requestFor('appointments')));
        $this->assertSame('appointments', ModuleUsageResolver::resolve($this->requestFor('appointment-types')));
        $this->assertSame('appointments', ModuleUsageResolver::resolve($this->requestFor('appointment-availability/me')));
        // /clients/{id}/projects resolves to 'projects' (the more specific,
        // later segment), same "deepest segment wins" rule as
        // projects/{id}/contracts resolving to 'contracts' — not a bug.
        $this->assertSame('clients', ModuleUsageResolver::resolve($this->requestFor('clients/9')));
        $this->assertSame('users', ModuleUsageResolver::resolve($this->requestFor('users/9/ban')));
        $this->assertSame('billing', ModuleUsageResolver::resolve($this->requestFor('billing/overview')));
        $this->assertSame('billing', ModuleUsageResolver::resolve($this->requestFor('billing/checkout')));
        $this->assertSame('pricing_management', ModuleUsageResolver::resolve($this->requestFor('admin/pricing/plans')));
        $this->assertSame('ai_telemetry', ModuleUsageResolver::resolve($this->requestFor('admin/ai-telemetry/summary')));
        $this->assertSame('ai_analysis', ModuleUsageResolver::resolve($this->requestFor('ai/status')));
        $this->assertSame('prompt_library', ModuleUsageResolver::resolve($this->requestFor('prompts/3/render')));
        $this->assertSame('prompt_library', ModuleUsageResolver::resolve($this->requestFor('admin/prompts/categories')));
        $this->assertSame('prompt_library', ModuleUsageResolver::resolve($this->requestFor('admin/prompts/templates')));
        $this->assertSame('prompt_library', ModuleUsageResolver::resolve($this->requestFor('projects/4/prompts/3/render')));
    }

    public function test_snagging_bug_fix_resolves_to_site_reports(): void
    {
        // The real route resource is 'snagging' (apiResource('snagging', ...)) —
        // the prior 'snags' key matched nothing and this module was never counted.
        $this->assertSame('site_reports', ModuleUsageResolver::resolve($this->requestFor('projects/4/snagging')));
    }

    public function test_ambiguous_templates_segment_stays_unmapped_and_falls_to_the_preceding_segment(): void
    {
        // /admin/templates (Document Templates CRUD) — 'templates' unmapped,
        // falls to 'admin'.
        $this->assertSame('super_admin', ModuleUsageResolver::resolve($this->requestFor('admin/templates')));
        // /admin/prompts/templates (Prompt Library templates) — 'templates'
        // still unmapped, so 'prompts' (the more specific, correct bucket)
        // wins rather than being overwritten by an ambiguous 'templates' key.
        $this->assertSame('prompt_library', ModuleUsageResolver::resolve($this->requestFor('admin/prompts/templates')));
    }
}
