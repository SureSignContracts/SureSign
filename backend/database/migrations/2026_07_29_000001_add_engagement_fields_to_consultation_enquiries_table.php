<?php

use App\Services\Consultancy\EngagementLifecycleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Consultancy Phase C2, Batch 1 — the engagement-status representation and
// every Consultancy-owned operational field this phase introduces. All new
// columns live on consultation_enquiries (Consultancy-owned), never on
// appointments (Appointments-owned) — see
// internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
// §1 for the full reasoning behind each placement decision.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_enquiries', function (Blueprint $table) {
            // Plain string, not a DB enum — an additional future value never
            // requires a migration, matching how Appointment::STATUSES is a
            // PHP-level constant over a plain column, not a DB-level enum.
            $table->string('engagement_status')->nullable()->after('submitted_by');

            $table->text('internal_notes')->nullable()->after('engagement_status');

            $table->text('customer_summary_draft')->nullable()->after('internal_notes');
            $table->text('customer_summary_published')->nullable()->after('customer_summary_draft');
            $table->timestamp('customer_summary_published_at')->nullable()->after('customer_summary_published');
            $table->foreignId('customer_summary_published_by')->nullable()->after('customer_summary_published_at')->constrained('users')->nullOnDelete();
            $table->boolean('customer_summary_needs_republish')->default(false)->after('customer_summary_published_by');
        });

        // Backfill: every C1 row predates this column entirely (C1 shipped
        // with no notes/summary/engagement-status UI at all), so there is no
        // existing engagement_status value to preserve — only a derivation
        // from each row's already-existing linked Appointment status. Uses
        // the exact same pure function the service itself exposes, so the
        // migration and the service can never define this rule differently.
        foreach (
            DB::table('consultation_enquiries')
                ->join('appointments', 'appointments.id', '=', 'consultation_enquiries.appointment_id')
                ->select('consultation_enquiries.id as consultation_enquiry_id', 'appointments.status as appointment_status')
                ->get() as $row
        ) {
            DB::table('consultation_enquiries')
                ->where('id', $row->consultation_enquiry_id)
                ->update(['engagement_status' => EngagementLifecycleService::deriveInitialStatusFromAppointmentStatus($row->appointment_status)]);
        }

        // Only after backfilling existing rows does the column become
        // NOT NULL with a real default — every row now has a value, and
        // every future row gets 'awaiting_consultant' automatically.
        Schema::table('consultation_enquiries', function (Blueprint $table) {
            $table->string('engagement_status')->nullable(false)->default('awaiting_consultant')->change();
        });
    }

    public function down(): void
    {
        Schema::table('consultation_enquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_summary_published_by');
            $table->dropColumn([
                'engagement_status',
                'internal_notes',
                'customer_summary_draft',
                'customer_summary_published',
                'customer_summary_published_at',
                'customer_summary_needs_republish',
            ]);
        });
    }
};
