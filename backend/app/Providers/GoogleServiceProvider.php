<?php

namespace App\Providers;

use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Calendar\GoogleCalendarProvider;
use App\Services\Calendar\MeetingProviderInterface;
use App\Services\Google\FakeGoogleApiClient;
use App\Services\Google\GoogleApiClientInterface;
use App\Services\Google\GoogleClientAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * Binds GoogleApiClientInterface and CalendarProviderInterface/
 * MeetingProviderInterface to their fake implementations in the testing
 * environment, and to the real Google adapters everywhere else — the
 * exact same environment-based, boot-time-fixed decision
 * BillingServiceProvider makes for Stripe. No automated test may ever
 * construct a real \Google\Client or make a real HTTP call to Google.
 */
class GoogleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleApiClientInterface::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeGoogleApiClient();
            }

            return new GoogleClientAdapter();
        });

        // Also bind the concrete fake so tests can type-hint it directly
        // (e.g. to seed pendingCodes/refreshableTokens) without re-resolving/
        // casting the interface.
        $this->app->singleton(FakeGoogleApiClient::class, function ($app) {
            return $app->make(GoogleApiClientInterface::class);
        });

        $this->app->singleton(CalendarProviderInterface::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeCalendarProvider();
            }

            return $app->make(GoogleCalendarProvider::class);
        });

        $this->app->singleton(MeetingProviderInterface::class, function ($app) {
            return $app->make(CalendarProviderInterface::class);
        });

        $this->app->singleton(FakeCalendarProvider::class, function ($app) {
            return $app->make(CalendarProviderInterface::class);
        });
    }
}
