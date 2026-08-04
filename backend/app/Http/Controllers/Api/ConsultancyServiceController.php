<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsultancyServiceRequest;
use App\Http\Requests\UpdateConsultancyServiceRequest;
use App\Models\ActivityLog;
use App\Models\ConsultancyService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Http\Request;

/**
 * Consultancy Service catalogue management. Unlike Appointment Types
 * (Super-Admin-only mutation), this follows the Pricing Management
 * precedent — Super Admin OR Admin, since both are platform-wide roles in
 * this codebase, not org-scoped. See
 * internal-docs/commercial/suresign-consultancy-specification-v1.md.
 */
class ConsultancyServiceController extends Controller
{
    public function __construct(
        private readonly ConsultancyCatalogueService $catalogueService,
    ) {
    }

    public function index(Request $request)
    {
        $query = ConsultancyService::query()->with('appointmentType');

        if ($request->boolean('enabled_only')) {
            $query->where('enabled', true);
        }

        $services = $query->orderBy('display_order')->orderBy('display_name')->get();

        return response()->json($services);
    }

    public function show(Request $request, ConsultancyService $consultancyService)
    {
        return response()->json($consultancyService->load('appointmentType'));
    }

    public function store(StoreConsultancyServiceRequest $request)
    {
        $service = $this->catalogueService->create($request->validated());

        ActivityLog::record(
            'consultancy_service.created',
            "Consultancy Service '{$service->display_name}' created.",
            $request->user(),
            $service,
        );

        return response()->json($service->load('appointmentType'), 201);
    }

    public function update(UpdateConsultancyServiceRequest $request, ConsultancyService $consultancyService)
    {
        $service = $this->catalogueService->update($consultancyService, $request->validated());

        ActivityLog::record(
            'consultancy_service.updated',
            "Consultancy Service '{$service->display_name}' updated.",
            $request->user(),
            $service,
        );

        return response()->json($service->load('appointmentType'));
    }

    public function destroy(Request $request, ConsultancyService $consultancyService)
    {
        $consultancyService->delete();

        ActivityLog::record(
            'consultancy_service.deleted',
            "Consultancy Service '{$consultancyService->display_name}' deleted.",
            $request->user(),
            $consultancyService,
        );

        return response()->json(null, 204);
    }
}
