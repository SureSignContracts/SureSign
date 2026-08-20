<?php

namespace App\Jobs;

use App\Services\AccountEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Unified Password Security Hardening — one job for all three post-
 * mutation security notifications (self-service/forced change, reset,
 * admin-set), mirroring SendPasswordResetEmailJob's exact queued/
 * afterCommit/tries/backoff contract. One job rather than three
 * near-identical classes, since all three share construction/retry
 * shape and differ only in which AccountEmailService method to call.
 *
 * Takes plain values (email/name/occurredAtDisplay), not a User id to
 * re-fetch — same reasoning as SendPasswordResetEmailJob: nothing here
 * needs to be re-read fresher than it was at dispatch time.
 */
class SendPasswordSecurityNotificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $type,
        public readonly string $occurredAtDisplay,
    ) {
    }

    public function handle(AccountEmailService $service): void
    {
        $sent = match ($this->type) {
            'changed'       => $service->sendPasswordChanged($this->email, $this->name, $this->occurredAtDisplay),
            'reset'         => $service->sendPasswordResetSecurityNotification($this->email, $this->name, $this->occurredAtDisplay),
            'admin_changed' => $service->sendPasswordChangedByAdmin($this->email, $this->name, $this->occurredAtDisplay),
            default         => false,
        };

        if (!$sent) {
            Log::info("SendPasswordSecurityNotificationJob: delivery failed for {$this->email} (type={$this->type}) — see EmailNotificationService logs.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendPasswordSecurityNotificationJob failed for {$this->email} (type={$this->type})", [
            'error' => $exception->getMessage(),
        ]);
    }
}
