<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationDomainRequest;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Services\Organizations\DomainVerificationService;
use Illuminate\Http\Request;

/**
 * Organisation URL Branding, Phase 2 — Super Admin management of
 * customer-owned domains. Mutations are `role:Super Admin` ONLY (see
 * routes/api.php, mirrors `AiCreditsGrantController`/the manual-subscription
 * assignment group's precedent) — Admin/Client get read-only visibility via
 * `index()`/`show()` only. No customer-facing (Client-role) domain
 * management exists — matches the brief's explicit scope.
 *
 * Never exposes `verification_token` to a non-privileged caller by
 * omission — this controller IS the privileged (Super Admin/Admin) surface,
 * so returning the full model here is intentional (an operator needs the
 * token to hand to the customer), unlike a hypothetical future
 * customer-facing domain page.
 */
class OrganizationDomainController extends Controller
{
    private function findDomain(Organization $organization, int $domainId): OrganizationDomain
    {
        $domain = OrganizationDomain::where('organization_id', $organization->id)->findOrFail($domainId);

        return $domain;
    }

    public function index(Organization $organization)
    {
        return response()->json(['data' => $organization->domains()->orderByDesc('created_at')->get()]);
    }

    public function store(StoreOrganizationDomainRequest $request, Organization $organization, DomainVerificationService $service)
    {
        $domain = $service->initiate($organization, $request->normalizedHostname());

        ActivityLog::record(
            'organization.domain_created',
            "Customer domain \"{$domain->hostname}\" registered for \"{$organization->name}\": {$request->validated('reason')}",
            $request->user(),
            $organization,
            ['domain_id' => $domain->id, 'hostname' => $domain->hostname, 'reason' => $request->validated('reason')],
            null,
            $organization->id,
        );

        return response()->json(['data' => $domain], 201);
    }

    public function verify(Request $request, Organization $organization, int $domain, DomainVerificationService $service)
    {
        $model = $this->findDomain($organization, $domain);
        $verified = $service->verify($model->fresh());

        ActivityLog::record(
            $verified ? 'organization.domain_verified' : 'organization.domain_verification_failed',
            ($verified ? 'Customer domain verified' : 'Customer domain verification failed') . " for \"{$model->hostname}\" ({$organization->name})",
            $request->user(),
            $organization,
            ['domain_id' => $model->id, 'hostname' => $model->hostname, 'result' => $model->fresh()->last_check_result],
            null,
            $organization->id,
        );

        return response()->json(['data' => $model->fresh()]);
    }

    public function activate(Request $request, Organization $organization, int $domain, DomainVerificationService $service)
    {
        $validated = $request->validate(['reason' => 'required|string|min:10|max:1000', 'confirmed' => 'required|accepted']);
        $model = $this->findDomain($organization, $domain);

        if (! $model->isVerified()) {
            return response()->json(['message' => 'This domain must be verified before it can be activated.'], 422);
        }

        $service->activate($model);

        ActivityLog::record('organization.domain_activated',
            "Customer domain \"{$model->hostname}\" activated for \"{$organization->name}\": {$validated['reason']}",
            $request->user(), $organization,
            ['domain_id' => $model->id, 'hostname' => $model->hostname, 'reason' => $validated['reason']],
            null, $organization->id);

        return response()->json(['data' => $model->fresh()]);
    }

    public function disable(Request $request, Organization $organization, int $domain, DomainVerificationService $service)
    {
        $validated = $request->validate(['reason' => 'required|string|min:10|max:1000', 'confirmed' => 'required|accepted']);
        $model = $this->findDomain($organization, $domain);
        $service->disable($model);

        ActivityLog::record('organization.domain_disabled',
            "Customer domain \"{$model->hostname}\" disabled for \"{$organization->name}\": {$validated['reason']}",
            $request->user(), $organization,
            ['domain_id' => $model->id, 'hostname' => $model->hostname, 'reason' => $validated['reason']],
            null, $organization->id);

        return response()->json(['data' => $model->fresh()]);
    }

    public function remove(Request $request, Organization $organization, int $domain, DomainVerificationService $service)
    {
        $validated = $request->validate(['reason' => 'required|string|min:10|max:1000', 'confirmed' => 'required|accepted']);
        $model = $this->findDomain($organization, $domain);
        $service->remove($model);

        ActivityLog::record('organization.domain_removed',
            "Customer domain \"{$model->hostname}\" removed for \"{$organization->name}\": {$validated['reason']}",
            $request->user(), $organization,
            ['domain_id' => $model->id, 'hostname' => $model->hostname, 'reason' => $validated['reason']],
            null, $organization->id);

        return response()->json(['data' => $model->fresh()]);
    }
}
