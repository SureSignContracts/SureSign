<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Google Integration Foundation, Stage 4A — a real domain object for a
// long-lived OAuth connection to an external provider, deliberately NOT
// stored inside suresign_settings (a connection carries multiple encrypted
// secrets, a scope list, and health/lifecycle metadata — a real row shape,
// not a flat configuration value). See
// internal-docs/super-admin/google-integration.md for the full
// architecture rationale, including the documented (not yet implemented)
// future generalisation towards a provider-agnostic ExternalConnection
// concept.
//
// Only one row is ever active at a time in Stage 4A (the resolver always
// picks the most recent row with status='connected' for
// provider='google'/purpose='primary'), but the schema does not assume
// that: `provider` and `purpose` exist specifically so a future connection
// (a second Google account, a different consultant, or an entirely
// different provider such as Microsoft 365) is simply a new row — never a
// column addition or a redesign. Disconnecting never deletes a row; it
// marks it 'disconnected'/'revoked' and clears its live secrets, so the
// table doubles as its own connection history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 30)->default('google');
            // A light future-multi-connection hook — see class docblock.
            // Stage 4A always uses 'primary'.
            $table->string('purpose', 60)->default('primary');

            $table->enum('status', ['connected', 'disconnected', 'revoked'])->default('connected');

            // Google's own stable subject identifier for the connected
            // account (from the ID token) — lets a reconnect attempt
            // detect "the same account reconnecting" vs "a different
            // account now connected", without relying on the mutable email.
            $table->string('google_account_id')->nullable();
            $table->string('connected_email')->nullable();

            // Encrypted via Laravel's native `encrypted` Eloquent cast —
            // the same mechanism already used for
            // suresign_settings.brevo_api_key/anthropic_api_key. No new
            // encryption mechanism introduced.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Granted scopes, as actually returned by Google on the OAuth
            // callback — never assumed to equal what was requested (Google
            // may grant a narrower set than requested; this is what
            // App\Services\Google\GoogleHealthService's
            // PERMISSIONS_MISSING state checks against).
            $table->json('scopes')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_successful_call_at')->nullable();
            $table->timestamp('last_failed_call_at')->nullable();
            $table->string('last_failure_reason')->nullable();

            // Repeated refresh failures move the connection to the
            // 'refresh_failed' health state rather than retrying
            // indefinitely — see GoogleTokenRefreshService. Reset to 0 on
            // every successful refresh.
            $table->unsignedInteger('consecutive_refresh_failures')->default(0);

            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disconnected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['provider', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
