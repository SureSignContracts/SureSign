<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per reference type (subscription/invoice/payment/checkout —
     * see App\Support\Billing\BillingReferenceType), each holding its own
     * atomically-incremented counter. Mirrors document_number_sequences'
     * lockForUpdate()+increment() pattern (App\Services\DocumentNumberService)
     * rather than inventing a new sequence-generation approach.
     */
    public function up(): void
    {
        Schema::create('billing_reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique();
            $table->unsignedInteger('current_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reference_sequences');
    }
};
