<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hard block on migrate:fresh / migrate:refresh / db:wipe (etc.) in
        // production — these DROP every table. This is a second, independent
        // layer on top of the tests/TestCase.php connection guard: that one
        // stops test runs from hitting a real database, this one stops the
        // destructive artisan commands themselves from ever executing
        // against production, regardless of how they get invoked.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // General authenticated API traffic — generous enough for normal
        // dashboard use, polling, and document navigation, keyed per-user so
        // one busy user can't exhaust another's quota; falls back to IP for
        // the rare unauthenticated request that still hits an `api` middleware
        // group route (e.g. /guest-settings).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login: tight per email+IP bucket so guessing one account's password
        // doesn't get 120 tries a minute, plus a looser per-IP ceiling so an
        // attacker can't dodge the first bucket by rotating through many
        // emails from the same source. Both limits are keyed identically
        // whether or not the email corresponds to a real user — the response
        // itself (and this limiter) never differ based on account existence.
        RateLimiter::for('login', function (Request $request) {
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Forgot-password: the endpoint already returns an identical generic
        // message regardless of whether the email exists (AuthController::
        // forgotPassword) — these limits must not introduce a timing or
        // response-shape difference that would undo that.
        RateLimiter::for('forgot-password', function (Request $request) {
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinutes(15, 3)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 20)->by($request->ip()),
            ];
        });

        // Reset-password: throttles repeated token-guessing attempts against
        // a given email from a given IP, plus a wider per-IP ceiling. Valid
        // resets well under the threshold are unaffected.
        RateLimiter::for('reset-password', function (Request $request) {
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinutes(15, 5)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 20)->by($request->ip()),
            ];
        });

        // Email-verify link (token consumption) — a public, token-guessable
        // endpoint like reset-password, but kept as its own bucket so it can't
        // lock a user out of resetting their password or vice versa.
        RateLimiter::for('email-verify-link', function (Request $request) {
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinutes(15, 5)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 20)->by($request->ip()),
            ];
        });

        // Email verification resend — keyed per authenticated user (the route
        // requires auth:sanctum), since each user has their own mailbox to
        // spam regardless of source IP.
        RateLimiter::for('email-verification-resend', function (Request $request) {
            return Limit::perMinutes(15, 3)->by($request->user()?->id ?: $request->ip());
        });

        // Demo request (public marketing site form, no auth) — keyed per-IP
        // only, since there's no authenticated user or email-enumeration risk
        // to protect, just a public form that shouldn't be spammable.
        RateLimiter::for('demo-request', function (Request $request) {
            return Limit::perMinutes(15, 5)->by($request->ip());
        });

        // Forced password change — authenticated-only endpoint, so the risk is
        // limited to a compromised session hammering the endpoint rather than
        // credential guessing. A loose per-user limit avoids nuisance-blocking
        // a legitimate user correcting a validation mistake a few times.
        RateLimiter::for('force-password-change', function (Request $request) {
            return Limit::perMinutes(15, 10)->by($request->user()?->id ?: $request->ip());
        });

        // Self-service password change (PUT /auth/password) — requires
        // current_password, so this is the brute-force-guessing route a
        // stolen-but-not-fully-compromised token could be used for. Kept as
        // its own bucket, separate from login/force-password-change, since
        // it has a different threat model (already-authenticated attacker
        // guessing a password, not credential stuffing).
        RateLimiter::for('password-change', function (Request $request) {
            return [
                Limit::perMinutes(15, 5)->by($request->user()?->id ?: $request->ip()),
                Limit::perMinutes(15, 20)->by($request->ip()),
            ];
        });

        // AI analysis initiation (contract + trade package) — a single call
        // here dispatches a real, billed Anthropic API request, orders of
        // magnitude more expensive than a normal CRUD request, so it gets
        // its own bucket separate from the general 'api' limiter. Keyed
        // per-user rather than per-organization: Admin/Super Admin accounts
        // operate across every organization (see platform role model), so an
        // org-wide bucket would let one Admin's activity throttle unrelated
        // tenants, or let several Client users in the same org each dodge
        // the limit by being separate keys despite sharing the org's cost
        // exposure. A secondary, looser per-IP ceiling guards against the
        // self-registration path being used to spin up several throwaway
        // accounts from one source, each burning its own per-user budget.
        // Both existing-analysis reuse (document_hash) and the
        // one-active-analysis-per-contract/package guard already in
        // AiController/TradePackageAiController are unaffected — this only
        // throttles the rate of *new* analysis requests reaching those
        // checks. reparse/confirm/cancel/generate-brief make no AI call and
        // are intentionally not covered.
        RateLimiter::for('ai-analysis', function (Request $request) {
            $tooManyAttempts = function (Request $request, array $headers) {
                return response()->json(
                    ['message' => 'AI analysis rate limit exceeded. Please try again later.'],
                    429,
                    $headers
                );
            };

            return [
                Limit::perHour(10)->by($request->user()?->id ?: $request->ip())->response($tooManyAttempts),
                Limit::perHour(30)->by($request->ip())->response($tooManyAttempts),
            ];
        });
    }

    private static function normalizedEmail(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email')));
    }
}
