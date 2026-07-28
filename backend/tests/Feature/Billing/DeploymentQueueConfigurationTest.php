<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;

/**
 * Reads the ACTUAL deployed configuration source of truth —
 * `backend/docker/entrypoint.sh`'s `queue` branch, the exact command
 * `docker-compose.prod.yml`'s `queue` service (`command: ["queue"]`) runs
 * in production — rather than a duplicated test-only constant. This is
 * what prevents the specific regression this checkpoint exists to fix:
 * "billing jobs are dispatched correctly but no deployed worker consumes
 * their queue" would otherwise pass every application-level test forever
 * (`BillingWebhookQueueIntegrationTest` proves the JOB/WORKER MECHANICS
 * work when given the right `--queue` argument; this test proves the
 * REAL entrypoint script actually supplies that argument).
 *
 * A parsed-argument assertion (extracting the actual `queue:work` command
 * line for the `queue` branch and inspecting its `--queue` value) is used
 * rather than a plain substring search, so reordering unrelated flags or
 * adding new ones doesn't produce a false failure — only the specific,
 * meaningful property (does `--queue` list `billing-webhooks` before
 * `default`) is asserted.
 */
class DeploymentQueueConfigurationTest extends TestCase
{
    private function entrypointScript(): string
    {
        $path = base_path('docker/entrypoint.sh');
        $this->assertFileExists($path, 'backend/docker/entrypoint.sh is missing — deployment configuration cannot be verified.');

        return file_get_contents($path);
    }

    /**
     * Extracts the exact `queue:work ...` invocation inside the script's
     * `if [ "$1" = "queue" ]; then ... fi` branch — the one docker-compose
     * `queue` service actually runs (`command: ["queue"]`).
     */
    private function queueWorkCommandLine(): string
    {
        $script = $this->entrypointScript();

        // `fi` must be matched as its own line, not merely the literal
        // substring "fi" — which also appears inside ordinary English
        // words (e.g. "config", "fix") in the surrounding comments, and a
        // naive non-greedy match would stop at the first such occurrence
        // instead of the shell block's actual closing keyword.
        $this->assertMatchesRegularExpression(
            '/if \[ "\$1" = "queue" \]; then\n.*?\nfi\n/s',
            $script,
            'entrypoint.sh no longer has a "queue" branch at all.'
        );

        preg_match('/if \[ "\$1" = "queue" \]; then\n(.*?)\nfi\n/s', $script, $branchMatch);
        $branch = $branchMatch[1] ?? '';

        preg_match('/php artisan queue:work[^\n]*/', $branch, $commandMatch);
        $this->assertNotEmpty($commandMatch, 'No "php artisan queue:work" invocation found inside the "queue" branch.');

        return trim($commandMatch[0]);
    }

    private function parsedQueueArgument(string $commandLine): array
    {
        preg_match('/--queue[=\s]+(\S+)/', $commandLine, $match);
        $this->assertNotEmpty($match, "No --queue flag found in: {$commandLine}");

        return explode(',', $match[1]);
    }

    public function test_the_deployed_queue_worker_command_declares_a_queue_flag(): void
    {
        $commandLine = $this->queueWorkCommandLine();

        $this->assertStringContainsString('--queue', $commandLine);
    }

    public function test_the_deployed_queue_worker_consumes_billing_webhooks(): void
    {
        $queues = $this->parsedQueueArgument($this->queueWorkCommandLine());

        $this->assertContains('billing-webhooks', $queues);
    }

    public function test_billing_webhooks_is_listed_before_default_so_it_is_not_starved(): void
    {
        $queues = $this->parsedQueueArgument($this->queueWorkCommandLine());

        $billingPosition = array_search('billing-webhooks', $queues, true);
        $defaultPosition = array_search('default', $queues, true);

        $this->assertNotFalse($billingPosition);
        $this->assertNotFalse($defaultPosition);
        $this->assertLessThan(
            $defaultPosition,
            $billingPosition,
            '--queue must list billing-webhooks before default, or billing events can be delayed behind slower default-queue jobs.'
        );
    }

    public function test_worker_level_tries_and_timeout_flags_are_still_present_as_defaults(): void
    {
        // These remain the WORKER-LEVEL defaults for any job that doesn't
        // declare its own $tries/$timeout (see ProcessBillingWebhookEventJob's
        // own docblock for why its stricter settings still apply
        // regardless — Laravel's Worker prefers a job's own tries()/
        // timeout() over these). This test only confirms the flags are
        // still present at all — not stripped out by a future edit.
        $commandLine = $this->queueWorkCommandLine();

        $this->assertStringContainsString('--tries=', $commandLine);
        $this->assertStringContainsString('--timeout=', $commandLine);
    }

    public function test_the_docker_compose_queue_service_still_invokes_the_queue_entrypoint_branch(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.prod.yml'));

        $this->assertNotFalse($compose, 'docker-compose.prod.yml not found at the expected repository root path.');
        $this->assertMatchesRegularExpression(
            '/queue:\s*\n(?:.*\n)*?\s*command:\s*\["queue"\]/',
            $compose,
            'docker-compose.prod.yml\'s queue service no longer runs the entrypoint\'s "queue" branch.'
        );
    }
}
