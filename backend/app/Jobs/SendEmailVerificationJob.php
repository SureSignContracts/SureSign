<?php

namespace App\Jobs;

use App\Services\AccountEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Communications Platform, Batch 4 — mirrors SendPasswordResetEmailJob's
 * exact contract for email verification, previously sent synchronously
 * inside App\Services\EmailVerificationService::sendVerificationLink().
 */
class SendEmailVerificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $verifyUrl,
    ) {
    }

    public function handle(AccountEmailService $service): void
    {
        $sent = $service->sendEmailVerification($this->email, $this->name, $this->verifyUrl);

        if (!$sent) {
            Log::info("SendEmailVerificationJob: delivery failed for {$this->email} — see EmailNotificationService logs.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendEmailVerificationJob failed for {$this->email}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
