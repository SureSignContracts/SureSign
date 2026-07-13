<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Verifies the named limiter definitions themselves (thresholds, decay
 * windows, key composition) without going through the HTTP kernel or
 * touching the database — these run even when RefreshDatabase-based feature
 * tests are blocked by the unrelated SQLite migration issue affecting
 * payment_applications (see AuthRateLimitingTest's docblock).
 */
class RateLimiterConfigurationTest extends TestCase
{
    private function limitsFor(string $name, Request $request): array
    {
        $result = call_user_func(RateLimiter::limiter($name), $request);

        return is_array($result) ? $result : [$result];
    }

    private function requestWithEmail(string $email, string $ip = '203.0.113.1'): Request
    {
        $request = Request::create('/api/auth/login', 'POST', ['email' => $email]);
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    public function test_login_limiter_has_a_tight_per_email_ip_bucket_and_a_looser_per_ip_ceiling(): void
    {
        $limits = $this->limitsFor('login', $this->requestWithEmail('User@Example.com', '203.0.113.1'));

        $this->assertCount(2, $limits);
        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame('user@example.com|203.0.113.1', $limits[0]->key);

        $this->assertSame(20, $limits[1]->maxAttempts);
        $this->assertSame(60, $limits[1]->decaySeconds);
        $this->assertSame('203.0.113.1', $limits[1]->key);
    }

    public function test_login_limiter_key_normalises_email_case_and_whitespace(): void
    {
        $a = $this->limitsFor('login', $this->requestWithEmail(' Mixed@Case.com ', '1.2.3.4'));
        $b = $this->limitsFor('login', $this->requestWithEmail('mixed@case.com', '1.2.3.4'));

        $this->assertSame($a[0]->key, $b[0]->key);
    }

    public function test_forgot_password_limiter_is_three_per_fifteen_minutes(): void
    {
        $limits = $this->limitsFor('forgot-password', $this->requestWithEmail('a@example.com'));

        $this->assertSame(3, $limits[0]->maxAttempts);
        $this->assertSame(15 * 60, $limits[0]->decaySeconds);
        $this->assertSame(20, $limits[1]->maxAttempts);
    }

    public function test_reset_password_limiter_is_five_per_fifteen_minutes(): void
    {
        $limits = $this->limitsFor('reset-password', $this->requestWithEmail('a@example.com'));

        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(15 * 60, $limits[0]->decaySeconds);
    }

    public function test_email_verification_resend_limiter_is_three_per_fifteen_minutes_keyed_by_user(): void
    {
        $request = Request::create('/api/auth/email/verification-notification', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.1');

        $limits = $this->limitsFor('email-verification-resend', $request);

        $this->assertSame(3, $limits[0]->maxAttempts);
        $this->assertSame(15 * 60, $limits[0]->decaySeconds);
        // No authenticated user on a bare Request::create(), so it falls back to IP.
        $this->assertSame('203.0.113.1', $limits[0]->key);
    }

    public function test_general_api_limiter_is_120_per_minute(): void
    {
        $request = Request::create('/api/auth/me', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.1');

        $limits = $this->limitsFor('api', $request);

        $this->assertSame(120, $limits[0]->maxAttempts);
        $this->assertSame(60, $limits[0]->decaySeconds);
        $this->assertSame('203.0.113.1', $limits[0]->key);
    }

}
