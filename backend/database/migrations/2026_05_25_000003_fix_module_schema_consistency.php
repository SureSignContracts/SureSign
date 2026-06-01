<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix schema consistency across all operational modules.
 *
 * Root causes fixed:
 * 1. Missing organization_id on rfis, variations, meeting_minutes,
 *    site_diaries, eot_requests, site_instructions, pay_less_notices
 * 2. Column name mismatches (rfis, variations, site_diaries, site_instructions, pay_less_notices)
 * 3. NOT NULL constraints blocking inserts (eot_requests, site_instructions, pay_less_notices)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── rfis ──────────────────────────────────────────────────────────────
        // Controllers/models use: description, raised_date, response_due_date, organization_id
        // Existing DB has: query, date_raised, response_required_by (no organization_id)
        Schema::table('rfis', function (Blueprint $table) {
            if (!Schema::hasColumn('rfis', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            if (!Schema::hasColumn('rfis', 'description')) {
                $table->text('description')->nullable()->after('subject');
            }
            if (!Schema::hasColumn('rfis', 'raised_date')) {
                $table->date('raised_date')->nullable()->after('description');
            }
            if (!Schema::hasColumn('rfis', 'response_due_date')) {
                $table->date('response_due_date')->nullable()->after('raised_date');
            }
            if (!Schema::hasColumn('rfis', 'response')) {
                $table->text('response')->nullable()->after('response_due_date');
            }
        });

        // ── variations ────────────────────────────────────────────────────────
        // Controllers/models use: type, variation_date, organization_id, programme_impact_days
        // Existing DB has: instruction_type (NOT NULL), instruction_date (no organization_id, no type, no variation_date)
        Schema::table('variations', function (Blueprint $table) {
            if (!Schema::hasColumn('variations', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            if (!Schema::hasColumn('variations', 'type')) {
                $table->string('type')->nullable()->after('title');
            }
            if (!Schema::hasColumn('variations', 'variation_date')) {
                $table->date('variation_date')->nullable()->after('agreed_amount');
            }
            // instruction_type is NOT NULL — make nullable so inserts without it succeed
            $table->string('instruction_type')->nullable()->change();
        });

        // ── meeting_minutes ───────────────────────────────────────────────────
        Schema::table('meeting_minutes', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_minutes', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
        });

        // ── site_diaries ──────────────────────────────────────────────────────
        // Controllers/models use: issues, temperature, organization_id
        // Existing DB has: delays_and_disruptions, health_safety_observations (no issues, no temperature, no org_id)
        Schema::table('site_diaries', function (Blueprint $table) {
            if (!Schema::hasColumn('site_diaries', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            if (!Schema::hasColumn('site_diaries', 'issues')) {
                $table->text('issues')->nullable()->after('materials_delivered');
            }
            if (!Schema::hasColumn('site_diaries', 'temperature')) {
                $table->decimal('temperature', 5, 1)->nullable()->after('weather');
            }
        });

        // ── eot_requests ──────────────────────────────────────────────────────
        // DB has contract_id (NOT NULL FK), grounds (NOT NULL), days_claimed (NOT NULL), event_date (NOT NULL)
        // Controllers don't provide contract_id or event_date, and grounds/days_claimed are optional
        Schema::table('eot_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('eot_requests', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            // Drop FK constraint before modifying contract_id column
            $table->dropForeign(['contract_id']);
            $table->unsignedBigInteger('contract_id')->nullable()->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();

            $table->text('grounds')->nullable()->change();
            $table->integer('days_claimed')->nullable()->change();

            if (!Schema::hasColumn('eot_requests', 'event_date')) {
                // Already exists in DB as NOT NULL — only add if missing
            } else {
                // Make event_date nullable so it can be omitted
                $table->date('event_date')->nullable()->change();
            }
        });

        // Also ensure event_date is nullable (it exists in migration as NOT NULL)
        Schema::table('eot_requests', function (Blueprint $table) {
            if (Schema::hasColumn('eot_requests', 'event_date')) {
                $table->date('event_date')->nullable()->change();
            }
        });

        // ── site_instructions ─────────────────────────────────────────────────
        // Controller validates 'subject' but DB/frontend uses 'title'
        // DB has description (NOT NULL), type (NOT NULL) — must be made nullable
        Schema::table('site_instructions', function (Blueprint $table) {
            if (!Schema::hasColumn('site_instructions', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            // description and type are NOT NULL in original migration — relax constraints
            $table->text('description')->nullable()->change();
            $table->string('type')->nullable()->default('general')->change();
            // issued_to may already exist from original migration
            if (!Schema::hasColumn('site_instructions', 'issued_to')) {
                $table->string('issued_to')->nullable()->after('description');
            }
        });

        // ── pay_less_notices ──────────────────────────────────────────────────
        // DB has: payment_application_id (NOT NULL FK), notified_sum (NOT NULL), basis_of_difference (NOT NULL)
        // Controller/model uses: amount, reason, reference, organization_id (no payment_application_id)
        Schema::table('pay_less_notices', function (Blueprint $table) {
            if (!Schema::hasColumn('pay_less_notices', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->after('project_id');
            }
            // Add model-aligned columns
            if (!Schema::hasColumn('pay_less_notices', 'amount')) {
                $table->decimal('amount', 15, 2)->nullable()->after('notice_date');
            }
            if (!Schema::hasColumn('pay_less_notices', 'reason')) {
                $table->text('reason')->nullable()->after('amount');
            }
            if (!Schema::hasColumn('pay_less_notices', 'reference')) {
                $table->string('reference', 100)->nullable()->after('reason');
            }
            // Drop FK before modifying payment_application_id
            $table->dropForeign(['payment_application_id']);
            $table->unsignedBigInteger('payment_application_id')->nullable()->change();
            $table->foreign('payment_application_id')->references('id')->on('payment_applications')->nullOnDelete();

            // Relax NOT NULL on legacy columns
            $table->decimal('notified_sum', 15, 2)->nullable()->change();
            $table->text('basis_of_difference')->nullable()->change();
        });
    }

    public function down(): void
    {
        // These are additive/relaxing changes — full reversal is complex and not needed for dev
    }
};
