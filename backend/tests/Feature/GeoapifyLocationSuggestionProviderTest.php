<?php

namespace Tests\Feature;

use App\Services\Geocoding\GeoapifyLocationSuggestionProvider;
use App\Services\Geocoding\GeocodingProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Global Address UX V3 — GeoapifyLocationSuggestionProvider unit tests.
 * Every test uses Http::fake(); none ever calls the real Geoapify API
 * (mirrors GeoapifyGeocodingProviderTest's own convention exactly).
 *
 * V3 closeout: `suggestCities()` no longer accepts `$region` at all (see
 * the class's own docblock for why) — every call below uses the current
 * 3-argument signature.
 */
class GeoapifyLocationSuggestionProviderTest extends TestCase
{
    private function provider(): GeoapifyLocationSuggestionProvider
    {
        return new GeoapifyLocationSuggestionProvider();
    }

    private function cityResult(array $overrides = []): array
    {
        return array_replace([
            'city' => 'Calapan',
            'state' => 'Oriental Mindoro',
            'country' => 'Philippines',
            'result_type' => 'city',
        ], $overrides);
    }

    public function test_missing_api_key_throws(): void
    {
        config(['services.geoapify.api_key' => null]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->suggestCities('Calap', null, 8);
    }

    public function test_correct_endpoint_and_query_with_country_filter(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->provider()->suggestCities('Calap', 'PH', 8);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/geocode/autocomplete')
                && $request['text'] === 'Calap'
                && $request['filter'] === 'countrycode:ph'
                && $request['format'] === 'json'
                && $request['limit'] === 8
                && $request['apiKey'] === 'secret-key';
        });
    }

    public function test_no_country_means_no_filter_param(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->provider()->suggestCities('Calap', null, 8);

        Http::assertSent(fn ($request) => !array_key_exists('filter', $request->data()));
    }

    public function test_connection_failure_throws(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->suggestCities('Calap', null, 8);
    }

    public function test_rate_limited_response_throws(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 429)]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->suggestCities('Calap', null, 8);
    }

    public function test_malformed_response_shape_throws(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['unexpected' => true])]);

        $this->expectException(GeocodingProviderException::class);
        $this->provider()->suggestCities('Calap', null, 8);
    }

    public function test_accepts_city_and_locality_result_types(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            $this->cityResult(['result_type' => 'city']),
            $this->cityResult(['city' => 'Baco', 'result_type' => 'locality']),
        ]])]);

        $result = $this->provider()->suggestCities('Ca', 'PH', 8);

        $this->assertCount(2, $result);
        $this->assertSame('Calapan', $result[0]['name']);
        $this->assertSame('Oriental Mindoro', $result[0]['region']);
        $this->assertSame('Philippines', $result[0]['country']);
        $this->assertSame('Baco', $result[1]['name']);
    }

    public function test_rejects_non_locality_result_types(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            $this->cityResult(['result_type' => 'street']),
            $this->cityResult(['result_type' => 'amenity']),
            $this->cityResult(['result_type' => 'building']),
            $this->cityResult(['result_type' => 'postcode']),
            $this->cityResult(['result_type' => 'state']),
            $this->cityResult(['result_type' => 'country']),
        ]])]);

        $result = $this->provider()->suggestCities('Ca', 'PH', 8);

        $this->assertSame([], $result);
    }

    public function test_uses_name_fallback_when_city_field_missing(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            ['name' => 'Some Locality', 'result_type' => 'locality'],
        ]])]);

        $result = $this->provider()->suggestCities('Some', null, 8);

        $this->assertSame('Some Locality', $result[0]['name']);
        $this->assertNull($result[0]['region']);
        $this->assertNull($result[0]['country']);
    }

    public function test_empty_results_returns_empty_array_not_exception(): void
    {
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $this->assertSame([], $this->provider()->suggestCities('Zzzznotarealplace', null, 8));
    }
}
