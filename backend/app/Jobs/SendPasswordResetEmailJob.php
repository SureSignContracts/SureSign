<?php

namespace App\Jobs;

use App\Services\AccountEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Communications Platform, Batch 4 — password reset previously sent
 * synchronously, inline, inside App\Models\User::sendPasswordResetNotification()
 * (Laravel's own password-broker hook), making the customer wait on a live
 * Brevo HTTP round-trip while sitting on a "check your email" screen. This
 * job gives it the same queued/->afterCommit() contract every other
 * communication family already has.
 *
 * Takes plain values (email/name/resetUrl), not a User id to re-fetch —
 * unlike SendAppointmentEmailJob's pattern, nothing here needs to be
 * re-read fresher than it was at dispatch time; the reset URL is already
 * fully resolved and doesn't change between dispatch and delivery.
 */
class SendPasswordResetEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $resetUrl,
    ) {
    }

    public function handle(AccountEmailService $service): void
    {
        $sent = $service->sendPasswordReset($this->email, $this->name, $this->resetUrl);

        if (!$sent) {
            Log::info("SendPasswordResetEmailJob: delivery failed for {$this->email} — see EmailNotificationService logs.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendPasswordResetEmailJob failed for {$this->email}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
