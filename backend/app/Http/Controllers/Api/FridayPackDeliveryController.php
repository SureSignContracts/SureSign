<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FridayPack;
use App\Models\Project;
use App\Services\FridayPack\FridayPackDeliveryService;
use Illuminate\Http\Request;

/**
 * Automated Friday Pack, V1F — authenticated Send/Retry/list-of-deliveries
 * endpoints. Mirrors FridayPackController's own authorization structure
 * exactly; delegates all business logic to FridayPackDeliveryService —
 * this controller never creates a delivery row or dispatches a job
 * itself.
 */
class FridayPackDeliveryController extends Controller
{
    private function authorize(Request $request, Project|FridayPack $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeProjectFridayPack(Request $request, Project $project, FridayPack $fridayPack): void
    {
        $this->authorize($request, $fridayPack);
        if ($fridayPack->project_id !== $project->id) {
            abort(404, 'Friday Pack not found for this project.');
        }
    }

    public function index(Request $request, Project $project, FridayPack $fridayPack)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        return response()->json(
            $fridayPack->deliveries()
                ->with('initiatedBy:id,name')
                ->select(['id', 'friday_pack_id', 'recipient_name', 'recipient_email', 'status', 'attempt_count', 'last_attempted_at', 'sent_at', 'failed_at', 'failure_reason', 'initiated_by', 'created_at'])
                ->orderBy('recipient_email')
                ->get()
        );
    }

    public function send(Request $request, Project $project, FridayPack $fridayPack, FridayPackDeliveryService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $pack = $service->initiate($fridayPack, $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json([
            'friday_pack' => $pack->load(['generatedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name']),
            'deliveries'  => $pack->deliveries()->get(['id', 'recipient_name', 'recipient_email', 'status']),
        ], 201);
    }

    public function retryFailed(Request $request, Project $project, FridayPack $fridayPack, FridayPackDeliveryService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $count = $service->retryFailed($fridayPack, $request->user());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['retried' => $count]);
    }
}
