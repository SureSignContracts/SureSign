<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkout_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans');
            $table->foreignId('initiated_by_user_id')->constrained('users');

            $table->string('provider', 30);
            $table->string('provider_checkout_session_id')->nullable();

            // Human-readable operator-facing reference, e.g. CHK-000001.
            $table->string('internal_reference')->unique();

            $table->string('status', 20)->default('created'); // created|open|completed|expired|cancelled
            $table->string('billing_interval', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount'); // minor units

            // Validated against App\Rules\SafeUrl before being persisted here
            // — same rule Pricing Management's CTA/link fields already use.
            $table->string('success_url');
            $table->string('cancel_url');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_checkout_session_id'], 'billing_checkout_provider_session_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_checkout_sessions');
    }
};
