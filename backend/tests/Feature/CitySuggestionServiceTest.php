<?php

namespace Tests\Feature;

use App\Services\Geocoding\CitySuggestionService;
use App\Services\Geocoding\GeocodingProviderException;
use App\Services\Geocoding\LocationSuggestionProviderInterface;
use Tests\TestCase;

/**
 * V3 closeout: `suggest()`/`suggestCities()` no longer accept `$region` —
 * every call below uses the current 2-argument `suggest()` signature.
 */
class CitySuggestionServiceTest extends TestCase
{
    private function fakeProvider(?callable $onCall = null): LocationSuggestionProviderInterface
    {
        return new class($onCall) implements LocationSuggestionProviderInterface {
            public function __construct(private $onCall)
            {
            }

            public function suggestCities(string $query, ?string $countryCode, int $limit): array
            {
                if ($this->onCall) {
                    return ($this->onCall)($query, $countryCode, $limit);
                }

                return [];
            }
        };
    }

    public function test_empty_query_never_calls_provider(): void
    {
        $called = false;
        $service = new CitySuggestionService($this->fakeProvider(function () use (&$called) {
            $called = true;
            return [];
        }));

        $this->assertSame([], $service->suggest('', null));
        $this->assertFalse($called);
    }

    public function test_query_below_minimum_length_never_calls_provider(): void
    {
        $called = false;
        $service = new CitySuggestionService($this->fakeProvider(function () use (&$called) {
            $called = true;
            return [];
        }));

        $this->assertSame([], $service->suggest('C', null));
        $this->assertFalse($called);
    }

    public function test_valid_query_calls_provider_with_context_and_returns_its_result(): void
    {
        $captured = [];
        $service = new CitySuggestionService($this->fakeProvider(function (...$args) use (&$captured) {
            $captured = $args;
            return [['name' => 'Calapan', 'region' => 'Oriental Mindoro', 'country' => 'Philippines']];
        }));

        $result = $service->suggest('Calap', 'PH');

        $this->assertSame(['Calap', 'PH', 8], $captured);
        $this->assertSame([['name' => 'Calapan', 'region' => 'Oriental Mindoro', 'country' => 'Philippines']], $result);
    }

    public function test_provider_failure_is_swallowed_into_empty_array(): void
    {
        $service = new CitySuggestionService($this->fakeProvider(function () {
            throw new GeocodingProviderException('provider down');
        }));

        $this->assertSame([], $service->suggest('Calap', null));
    }

    public function test_whitespace_only_query_never_calls_provider(): void
    {
        $called = false;
        $service = new CitySuggestionService($this->fakeProvider(function () use (&$called) {
            $called = true;
            return [];
        }));

        $this->assertSame([], $service->suggest('   ', null));
        $this->assertFalse($called);
    }
}
