<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentTypeRequest;
use App\Http\Requests\UpdateAppointmentTypeRequest;
use App\Models\ActivityLog;
use App\Models\AppointmentType;
use Illuminate\Http\Request;

/**
 * Appointment Types are platform-wide configuration — Super Admin only for
 * anything that mutates them (approved architecture decision). Admin users
 * still need to read the list/detail to create appointments against a type,
 * so only the mutating actions are gated, not index/show.
 */
class AppointmentTypeController extends Controller
{
    private function requireSuperAdmin(Request $request): void
    {
        if (!$request->user()->hasRole('Super Admin')) {
            abort(403, 'Only Super Admin can manage Appointment Types.');
        }
    }

    public function index(Request $request)
    {
        $query = AppointmentType::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $types = $query->orderBy('display_order')->orderBy('name')->get();

        return response()->json($types);
    }

    public function show(Request $request, AppointmentType $appointmentType)
    {
        return response()->json($appointmentType);
    }

    public function store(StoreAppointmentTypeRequest $request)
    {
        $this->requireSuperAdmin($request);

        $type = AppointmentType::create($request->validated());

        ActivityLog::record(
            'appointment_type.created',
            "Appointment Type '{$type->name}' created.",
            $request->user(),
            $type,
        );

        return response()->json($type, 201);
    }

    public function update(UpdateAppointmentTypeRequest $request, AppointmentType $appointmentType)
    {
        $this->requireSuperAdmin($request);

        $appointmentType->update($request->validated());

        ActivityLog::record(
            'appointment_type.updated',
            "Appointment Type '{$appointmentType->name}' updated.",
            $request->user(),
            $appointmentType,
        );

        return response()->json($appointmentType);
    }

    public function destroy(Request $request, AppointmentType $appointmentType)
    {
        $this->requireSuperAdmin($request);

        $appointmentType->delete();

        ActivityLog::record(
            'appointment_type.deleted',
            "Appointment Type '{$appointmentType->name}' deleted.",
            $request->user(),
            $appointmentType,
        );

        return response()->json(null, 204);
    }
}
