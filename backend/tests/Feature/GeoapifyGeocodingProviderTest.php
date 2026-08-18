<?php

namespace Tests\Feature;

use App\Services\Geocoding\GeoapifyGeocodingProvider;
use App\Services\Geocoding\GeocodingProviderException;
use App\Support\Geocoding\GeocodingMatchStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract-Assisted Project Location, Phase 2, Part 35 — GeoapifyGeocodingProvider
 * unit tests. Every test uses Http::fake(); none ever calls the real
 * Geoapify API. Uses Tests\TestCase (not a bare PHPUnit TestCase) since the
 * provider reads config() and Http::fake() needs the framework booted.
 */
class GeoapifyGeocodingProviderTest extends TestCase
{
    private function provider(): GeoapifyGeocodingProvider
    {
        return new GeoapifyGeocodingProvider();
    }

    private function components(array $overrides = []): array
    {
        return array_replace([
            'address'  => '25 Riverside Road',
            'city'     => 'Manchester',
            'state'    => null,
            'postcode' => 'M3 4AB',
            'country'  => 'United Kingdom',
        ], $overrides);
    }

    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'lat' => 53.4808,
            'lon' => -2.2426,
            'result_type' => 'building',
            'rank' => ['confidence' => 0.98, 'match_type' => 'full_match'],
        ], $overrides);
    }

    // 1. Correct endpoint/query is constructed.
    public function test_correct_endpoint_and_query_are_constructed(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->provider()->geocode($this->components());

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.geoapify.com/v1/geocode/search')
                && $request['text'] === '25 Riverside Road, Manchester, M3 4AB, United Kingdom'
                && (int) $request['limit'] === 3
                // Live-verified via a real smoke test (Part 39): omitting
                // this returns a GeoJSON FeatureCollection, not the flat
                // `results` shape this class parses — regression-guards
                // the real fix that live test caught.
                && $request['format'] === 'json';
        });
    }

    // 2. API key comes from config.
    public function test_api_key_comes_from_config(): void
    {
        config(['services.geoapify.api_key' => 'from-config-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->provider()->geocode($this->components());

        Http::assertSent(fn ($request) => $request['apiKey'] === 'from-config-key');
    }

    public function test_missing_api_key_throws_without_making_a_request(): void
    {
        config(['services.geoapify.api_key' => null]);
        Http::fake();

        try {
            $this->provider()->geocode($this->components());
            $this->fail('Expected GeocodingProviderException');
        } catch (GeocodingProviderException $e) {
            Http::assertNothingSent();
        }
    }

    // 3. API key is never returned through application response (the
    // exception message itself must never contain it).
    public function test_exception_message_never_contains_the_api_key(): void
    {
        config(['services.geoapify.api_key' => 'super-secret-key-123']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 401)]);

        try {
            $this->provider()->geocode($this->components());
            $this->fail('Expected GeocodingProviderException');
        } catch (GeocodingProviderException $e) {
            $this->assertStringNotContainsString('super-secret-key-123', $e->getMessage());
        }
    }

    // 4. High-confidence building match → matched coordinates.
    public function test_high_confidence_building_match_is_accepted(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['result_type' => 'building'])]])]);

        $outcome = $this->provider()->geocode($this->components());

        $this->assertSame(GeocodingMatchStatus::MATCHED, $outcome->status);
        $this->assertEquals(53.4808, $outcome->latitude);
        $this->assertEquals(-2.2426, $outcome->longitude);
    }

    // 5. High-confidence amenity match → matched coordinates.
    public function test_high_confidence_amenity_match_is_accepted(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['result_type' => 'amenity'])]])]);

        $this->assertTrue($this->provider()->geocode($this->components())->isMatched());
    }

    // 6. High-confidence street match → matched coordinates.
    public function test_high_confidence_street_match_is_accepted(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['result_type' => 'street'])]])]);

        $this->assertTrue($this->provider()->geocode($this->components())->isMatched());
    }

    // 7. Confidence below threshold → NO_RELIABLE_MATCH.
    public function test_confidence_below_threshold_is_no_reliable_match(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            $this->candidate(['rank' => ['confidence' => 0.5, 'match_type' => 'full_match']]),
        ]])]);

        $outcome = $this->provider()->geocode($this->components());

        $this->assertFalse($outcome->isMatched());
        $this->assertSame(GeocodingMatchStatus::NO_RELIABLE_MATCH, $outcome->status);
    }

    // 8. City result → NO_RELIABLE_MATCH.
    public function test_city_result_type_is_no_reliable_match(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['result_type' => 'city'])]])]);

        $this->assertFalse($this->provider()->geocode($this->components())->isMatched());
    }

    // 9. State/country/other coarse result types → NO_RELIABLE_MATCH.
    public function test_coarse_result_types_are_no_reliable_match(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        foreach (['state', 'country', 'county', 'postcode', 'suburb', 'district'] as $type) {
            Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['result_type' => $type])]])]);
            $this->assertFalse($this->provider()->geocode($this->components())->isMatched(), "result_type={$type} must not be accepted");
        }
    }

    // 10. Empty results → NO_RELIABLE_MATCH.
    public function test_empty_results_is_no_reliable_match(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->assertFalse($this->provider()->geocode($this->components())->isMatched());
    }

    // 11. Invalid latitude → rejected.
    public function test_invalid_latitude_is_rejected(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['lat' => 999])]])]);

        $this->assertFalse($this->provider()->geocode($this->components())->isMatched());
    }

    // 12. Invalid longitude → rejected.
    public function test_invalid_longitude_is_rejected(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [$this->candidate(['lon' => -999])]])]);

        $this->assertFalse($this->provider()->geocode($this->components())->isMatched());
    }

    // 13. 401/403 → provider/config failure.
    public function test_401_is_a_provider_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 401)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    public function test_403_is_a_provider_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 403)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    // 14. 429 → provider unavailable/rate-limited.
    public function test_429_is_a_provider_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 429)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    // 15. 5xx → provider unavailable.
    public function test_5xx_is_a_provider_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 503)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    // 16. Timeout/connection exception → provider unavailable.
    public function test_connection_exception_is_a_provider_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    // 17. Malformed successful payload → safe failure, never silently
    // treated as "no candidates."
    public function test_malformed_successful_payload_is_a_safe_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['unexpected' => 'shape'], 200)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }

    public function test_non_array_results_field_is_a_safe_failure(): void
    {
        config(['services.geoapify.api_key' => 'key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => 'not-an-array'], 200)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->geocode($this->components());
    }
}
