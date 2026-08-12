<?php

namespace App\Jobs;

use App\Services\InvitationEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Invitation & First-Time Account Setup phase — mirrors
 * SendEmailVerificationJob's exact queued/afterCommit contract. Dispatched
 * only from InvitationService::send(), never from the standard
 * self-registration verification path (EmailVerificationService).
 */
class SendInvitationEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $acceptUrl,
        public readonly ?string $organizationName,
        public readonly int $expiryDays,
    ) {
    }

    public function handle(InvitationEmailService $service): void
    {
        $sent = $service->send($this->email, $this->name, $this->acceptUrl, $this->organizationName, $this->expiryDays);

        if (!$sent) {
            Log::info("SendInvitationEmailJob: delivery failed for {$this->email} — see EmailNotificationService logs.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendInvitationEmailJob failed for {$this->email}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
