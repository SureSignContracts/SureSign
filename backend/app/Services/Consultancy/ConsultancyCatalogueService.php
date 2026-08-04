<?php

namespace App\Services\Consultancy;

use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use Illuminate\Support\Facades\DB;

/**
 * The sole place a consultancy_services row and its linked AppointmentType
 * are written together, in one transaction, so the two never drift out of
 * sync. Scheduling fields (duration, buffers, notice/advance windows,
 * meeting method, confirmation requirement, cancellation/reschedule notice)
 * live exclusively on AppointmentType; everything else (commercial +
 * catalogue presentation) lives exclusively on ConsultancyService. See
 * internal-docs/commercial/suresign-consultancy-specification-v1.md.
 *
 * Consultancy Live Booking Upgrade, Stage 1 — this class no longer accepts
 * or writes `assignment_mode`/`default_assigned_user_id` on the linked
 * AppointmentType at all. The Consultancy consultant is now resolved
 * exclusively via App\Services\Consultancy\ConsultancyConsultantResolver
 * (an operational, platform-wide setting), never a per-service field — see
 * that class's docblock for why storing it here too would create two
 * competing sources of truth.
 */
class ConsultancyCatalogueService
{
    private const APPOINTMENT_TYPE_FIELDS = [
        'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
        'min_notice_hours', 'max_advance_days',
        'requires_confirmation', 'meeting_method',
        'cancellation_notice_hours', 'reschedule_notice_hours',
    ];

    private const CATALOGUE_FIELDS = [
        'display_name', 'description', 'public_description', 'enabled',
        'publicly_bookable', 'available_to_existing_customers',
        'price_minor_units', 'currency', 'display_order', 'is_introductory',
        'max_bookings_per_day',
    ];

    public function create(array $data): ConsultancyService
    {
        return DB::transaction(function () use ($data) {
            $type = AppointmentType::create(array_merge(
                array_intersect_key($data, array_flip(self::APPOINTMENT_TYPE_FIELDS)),
                [
                    'name'                     => $data['display_name'],
                    'slug'                     => $data['code'],
                    'description'              => $data['description'] ?? null,
                    'public_title'             => $data['display_name'],
                    'public_description'      => $data['public_description'] ?? null,
                    'is_public'                => $data['publicly_bookable'] ?? false,
                    'is_active'                => $data['enabled'] ?? false,
                    'display_order'            => $data['display_order'] ?? 0,
                ]
            ));

            return ConsultancyService::create(array_merge(
                array_intersect_key($data, array_flip(self::CATALOGUE_FIELDS)),
                [
                    'code'                 => $data['code'],
                    'appointment_type_id' => $type->id,
                ]
            ));
        });
    }

    public function update(ConsultancyService $service, array $data): ConsultancyService
    {
        return DB::transaction(function () use ($service, $data) {
            $type = $service->appointmentType;

            $typeUpdate = array_intersect_key($data, array_flip(self::APPOINTMENT_TYPE_FIELDS));
            if (array_key_exists('display_name', $data)) {
                $typeUpdate['name']         = $data['display_name'];
                $typeUpdate['public_title'] = $data['display_name'];
            }
            if (array_key_exists('description', $data)) {
                $typeUpdate['description'] = $data['description'];
            }
            if (array_key_exists('public_description', $data)) {
                $typeUpdate['public_description'] = $data['public_description'];
            }
            if (array_key_exists('publicly_bookable', $data)) {
                $typeUpdate['is_public'] = $data['publicly_bookable'];
            }
            if (array_key_exists('enabled', $data)) {
                $typeUpdate['is_active'] = $data['enabled'];
            }
            if (array_key_exists('display_order', $data)) {
                $typeUpdate['display_order'] = $data['display_order'];
            }
            if (!empty($typeUpdate)) {
                $type->update($typeUpdate);
            }

            // 'code' is deliberately never accepted here — immutable after creation.
            $serviceUpdate = array_intersect_key($data, array_flip(self::CATALOGUE_FIELDS));
            if (!empty($serviceUpdate)) {
                $service->update($serviceUpdate);
            }

            return $service->fresh();
        });
    }
}
