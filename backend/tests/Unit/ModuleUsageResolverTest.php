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
}
