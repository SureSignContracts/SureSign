<?php

namespace App\Services\AI;

use App\Models\FileUpload;
use App\Models\TradePackageAiAnalysis;
use App\Services\TradePackages\TradePackageCatalogueService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sibling to ContractAnalysisService for Trade Package (subcontract) onboarding.
 *
 * Reuses ContractAnalysisService for the genuinely generic pieces (text extraction,
 * provider construction, enabled check, cost estimation) via composition rather
 * than inheritance, since ContractAnalysisService::analyse() and its private
 * parseJsonResponse() are hard-wired to ContractAiAnalysis/ContractAnalysisPrompt.
 */
class TradePackageAnalysisService
{
    public function __construct(private ContractAnalysisService $shared) {}

    public function isEnabled(): bool
    {
        return $this->shared->isEnabled();
    }

    /**
     * Run the AI analysis and return the parsed JSON as an array.
     */
    public function analyse(TradePackageAiAnalysis $analysis, FileUpload $fileUpload): array
    {
        $subcontractText = $this->shared->extractText($fileUpload);

        if (empty(trim($subcontractText))) {
            throw new RuntimeException('The subcontract file appears to be empty or could not be read.');
        }

        // Hash the extracted text so identical subcontract content can reuse a prior
        // completed analysis instead of paying Claude again.
        $hash = hash('sha256', $subcontractText);
        $analysis->update(['document_hash' => $hash]);

        $cached = TradePackageAiAnalysis::query()
            ->where('document_hash', $hash)
            ->where('model', $analysis->model)
            ->whereIn('status', ['completed', 'confirmed'])
            ->whereNotNull('raw_response_json')
            ->where('id', '!=', $analysis->id)
            ->latest()
            ->first();

        if ($cached) {
            Log::info('Reusing cached subcontract analysis', [
                'analysis_id' => $analysis->id,
                'reused_from' => $cached->id,
            ]);

            return [
                'data'          => $cached->raw_response_json,
                'tokens_input'  => 0,
                'tokens_output' => 0,
            ];
        }

        $provider = $this->shared->makeProvider();

        $catalogueNames = array_map(fn (array $pkg) => $pkg['name'], TradePackageCatalogueService::all());

        $result = $provider->complete(
            SubcontractAnalysisPrompt::system(),
            SubcontractAnalysisPrompt::user($subcontractText, $catalogueNames)
        );

        // Persist the raw response and usage immediately — BEFORE parsing — so that even if
        // parsing fails the (already paid-for) response is not lost and can be re-parsed later
        // without calling Claude again.
        $analysis->update([
            'raw_response_text' => $result['text'] ?? null,
            'stop_reason'       => $result['stop_reason'] ?? null,
            'tokens_input'      => $result['tokens_input'],
            'tokens_output'     => $result['tokens_output'],
            'estimated_cost'    => $this->shared->estimateCost($result['tokens_input'], $result['tokens_output']),
        ]);

        $decoded = $this->parseJsonResponse($result['text']);

        if ($decoded === null) {
            $truncated = ($result['stop_reason'] ?? null) === 'max_tokens';

            Log::error('Claude returned invalid JSON for subcontract analysis', [
                'analysis_id' => $analysis->id,
                'stop_reason' => $result['stop_reason'] ?? null,
                'raw'         => substr($result['text'], 0, 1000),
            ]);

            throw new RuntimeException(
                $truncated
                    ? 'The analysis was longer than the response limit and was cut off. The limit has been raised — please re-run.'
                    : 'AI returned a response that could not be read. You can re-parse the saved response without using more credits.'
            );
        }

        return [
            'data'          => $decoded,
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
        ];
    }

    /**
     * Re-attempt parsing of an analysis's already-stored raw response. Makes NO Claude call,
     * so it consumes no credits.
     */
    public function reparse(TradePackageAiAnalysis $analysis): ?array
    {
        if (empty($analysis->raw_response_text)) {
            return null;
        }

        return $this->parseJsonResponse($analysis->raw_response_text);
    }

    /**
     * Robustly parse JSON from a model response.
     * Identical logic to ContractAnalysisService::parseJsonResponse() — duplicated here
     * (rather than made public/shared) since it's the only piece not reachable via
     * composition, and it's small, generic, and has no Contract-specific behaviour.
     */
    private function parseJsonResponse(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $repaired = preg_replace_callback('/("(?:[^"\\\\]|\\\\.)*")/s', function ($m) {
            $inner = substr($m[1], 1, -1);
            $inner = str_replace(["\r\n", "\r", "\n", "\t"], ['\\n', '\\n', '\\n', '\\t'], $inner);
            return '"' . $inner . '"';
        }, $text);

        $decoded = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return null;
    }
}
