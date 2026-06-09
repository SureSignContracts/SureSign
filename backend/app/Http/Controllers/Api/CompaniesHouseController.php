<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy for the UK Companies House Public Data API.
 * Keeps the API key server-side and avoids CORS issues.
 *
 * API key can be obtained free at:
 *   https://developer.company-information.service.gov.uk/manage-applications
 *
 * Set COMPANIES_HOUSE_API_KEY in your .env / .env.docker.
 */
class CompaniesHouseController extends Controller
{
    private const BASE_URL = 'https://api.company-information.service.gov.uk';

    private function apiKey(): ?string
    {
        return config('services.companies_house.api_key')
            ?: env('COMPANIES_HOUSE_API_KEY');
    }

    private function client()
    {
        $key = $this->apiKey();

        return Http::withBasicAuth($key ?? '', '')
            ->acceptJson()
            ->timeout(10);
    }

    /**
     * GET /api/admin/companies-house/search?q={query}&limit={n}&start_index={n}
     */
    public function search(Request $request)
    {
        $request->validate([
            'q'           => 'required|string|min:2|max:100',
            'limit'       => 'nullable|integer|min:1|max:50',
            'start_index' => 'nullable|integer|min:0',
        ]);

        if (!$this->apiKey()) {
            return response()->json([
                'error'   => 'no_api_key',
                'message' => 'Companies House API key is not configured. Add COMPANIES_HOUSE_API_KEY to your .env file.',
            ], 503);
        }

        try {
            $response = $this->client()->get(self::BASE_URL . '/search/companies', [
                'q'              => $request->q,
                'items_per_page' => $request->input('limit', 20),
                'start_index'    => $request->input('start_index', 0),
            ]);

            if ($response->failed()) {
                Log::warning('[CompaniesHouse] Search failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                $outStatus = match(true) {
                    $response->status() === 401 => 503,
                    $response->status() === 403 => 503,
                    $response->status() >= 500  => 502,
                    default                     => 422,
                };

                $message = $response->status() === 401
                    ? 'Companies House rejected the API key. Check COMPANIES_HOUSE_API_KEY in .env.docker.'
                    : 'Companies House API returned an error.';

                return response()->json([
                    'error'   => $response->status() === 401 ? 'invalid_api_key' : 'api_error',
                    'message' => $message,
                ], $outStatus);
            }

            $data  = $response->json();
            $items = collect($data['items'] ?? [])->map(fn ($item) => $this->formatCompany($item));

            return response()->json([
                'total_results' => $data['total_results'] ?? count($items),
                'items_per_page'=> (int) ($data['items_per_page'] ?? 20),
                'start_index'   => (int) ($data['start_index'] ?? 0),
                'items'         => $items,
            ]);

        } catch (\Throwable $e) {
            Log::error('[CompaniesHouse] Search exception: ' . $e->getMessage());
            return response()->json([
                'error'   => 'connection_error',
                'message' => 'Could not reach Companies House API.',
            ], 502);
        }
    }

    /**
     * GET /api/admin/companies-house/{companyNumber}/officers
     */
    public function officers(string $companyNumber)
    {
        if (!preg_match('/^[A-Z0-9]{2,10}$/i', $companyNumber)) {
            return response()->json(['message' => 'Invalid company number format.'], 422);
        }

        if (!$this->apiKey()) {
            return response()->json(['error' => 'no_api_key', 'message' => 'API key not configured.'], 503);
        }

        try {
            $response = $this->client()->get(
                self::BASE_URL . '/company/' . strtoupper($companyNumber) . '/officers',
                ['items_per_page' => 50]
            );

            if ($response->status() === 401 || $response->status() === 403) {
                return response()->json(['error' => 'invalid_api_key', 'message' => 'Companies House rejected the API key.'], 503);
            }

            if ($response->status() === 404) {
                return response()->json(['total_results' => 0, 'items' => []]);
            }

            if ($response->failed()) {
                return response()->json(['message' => 'Companies House API error.'], 502);
            }

            $data = $response->json();
            $items = collect($data['items'] ?? [])
                ->map(fn ($o) => [
                    'name'         => $o['name'] ?? '',
                    'officer_role' => $o['officer_role'] ?? '',
                    'appointed_on' => $o['appointed_on'] ?? null,
                    'resigned_on'  => $o['resigned_on'] ?? null,
                    'nationality'  => $o['nationality'] ?? null,
                    'occupation'   => $o['occupation'] ?? null,
                    'country_of_residence' => $o['country_of_residence'] ?? null,
                    'address'      => $o['address'] ?? [],
                ]);

            return response()->json([
                'total_results' => $data['total_results'] ?? count($items),
                'items'         => $items,
            ]);

        } catch (\Throwable $e) {
            Log::error('[CompaniesHouse] Officers exception: ' . $e->getMessage());
            return response()->json(['message' => 'Could not reach Companies House API.'], 502);
        }
    }

    /**
     * GET /api/admin/companies-house/{companyNumber}
     */
    public function show(string $companyNumber)
    {
        if (!preg_match('/^[A-Z0-9]{2,10}$/i', $companyNumber)) {
            return response()->json(['message' => 'Invalid company number format.'], 422);
        }

        if (!$this->apiKey()) {
            return response()->json([
                'error'   => 'no_api_key',
                'message' => 'Companies House API key is not configured.',
            ], 503);
        }

        try {
            $response = $this->client()->get(self::BASE_URL . '/company/' . strtoupper($companyNumber));

            if ($response->status() === 404) {
                return response()->json(['message' => 'Company not found.'], 404);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return response()->json([
                    'error'   => 'invalid_api_key',
                    'message' => 'Companies House rejected the API key.',
                ], 503);
            }

            if ($response->failed()) {
                return response()->json(['message' => 'Companies House API error.'], 502);
            }

            return response()->json($this->formatCompanyDetail($response->json()));

        } catch (\Throwable $e) {
            Log::error('[CompaniesHouse] Show exception: ' . $e->getMessage());
            return response()->json(['message' => 'Could not reach Companies House API.'], 502);
        }
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatCompany(array $item): array
    {
        return [
            'company_number'   => $item['company_number'] ?? '',
            'title'            => $item['title'] ?? '',
            'company_status'   => $item['company_status'] ?? '',
            'company_type'     => $item['company_type'] ?? '',
            'date_of_creation' => $item['date_of_creation'] ?? null,
            'address_snippet'  => $item['address_snippet'] ?? '',
            'address'          => $item['registered_office_address'] ?? ($item['address'] ?? []),
            'description'      => $item['description'] ?? null,
            'sic_codes'        => $item['sic_codes'] ?? [],
        ];
    }

    private function formatCompanyDetail(array $data): array
    {
        $addr = $data['registered_office_address'] ?? [];
        $addressParts = array_filter([
            $addr['address_line_1'] ?? null,
            $addr['address_line_2'] ?? null,
            $addr['locality'] ?? null,
            $addr['region'] ?? null,
            $addr['postal_code'] ?? null,
        ]);

        return [
            'company_number'           => $data['company_number'] ?? '',
            'company_name'             => $data['company_name'] ?? '',
            'company_status'           => $data['company_status'] ?? '',
            'company_type'             => $data['company_type'] ?? '',
            'date_of_creation'         => $data['date_of_creation'] ?? null,
            'registered_office_address'=> $addr,
            'address_formatted'        => implode(', ', $addressParts),
            'sic_codes'                => $data['sic_codes'] ?? [],
            'accounts'                 => $data['accounts'] ?? null,
            'confirmation_statement'   => $data['confirmation_statement'] ?? null,
            'has_insolvency_history'   => $data['has_insolvency_history'] ?? false,
            'jurisdiction'             => $data['jurisdiction'] ?? null,
        ];
    }
}
