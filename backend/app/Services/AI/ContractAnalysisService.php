<?php

namespace App\Services\AI;

use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ContractAnalysisService
{
    private AiPricingSchedule $pricingSchedule;

    public function __construct(?AiPricingSchedule $pricingSchedule = null)
    {
        $this->pricingSchedule = $pricingSchedule ?? new AiPricingSchedule();
    }

    public function isEnabled(): bool
    {
        if (!config('ai.enabled', false)) {
            // Env var hard-disables it regardless of DB setting
            $settings = SuresignSetting::instance();
            return (bool) ($settings->ai_enabled ?? false);
        }
        return true;
    }

    public function makeProvider(): AiProviderInterface
    {
        $settings = SuresignSetting::instance();

        // Prefer DB-stored key; fall back to env
        $apiKey = $settings->anthropic_api_key ?? config('ai.anthropic.api_key', '');
        $model  = $settings->ai_model ?? config('ai.anthropic.model', 'claude-sonnet-5');
        $effort = $settings->ai_effort ?: 'high';

        return new ClaudeAiProvider($apiKey, $model, $effort);
    }

    /**
     * Extract plain text from a stored file.
     *
     * @throws RuntimeException for unsupported file types
     */
    public function extractText(FileUpload $fileUpload): string
    {
        $extension = strtolower(pathinfo($fileUpload->original_name ?? '', PATHINFO_EXTENSION));
        $storagePath = $fileUpload->file_path ?? $fileUpload->path ?? null;

        if (!$storagePath || !Storage::disk('local')->exists($storagePath)) {
            throw new RuntimeException('Contract file not found in storage.');
        }

        $absolutePath = Storage::disk('local')->path($storagePath);

        return match ($extension) {
            'txt'        => Storage::disk('local')->get($storagePath),
            'docx'       => $this->extractFromDocx($absolutePath),
            'pdf'        => $this->extractFromPdf($absolutePath),
            default      => throw new RuntimeException("File type '.{$extension}' is not supported for AI analysis. Please upload a PDF, DOCX, or TXT file."),
        };
    }

    /** Lowercase file extension of the analysed upload (e.g. 'pdf'), for document-metrics telemetry only. */
    public function fileExtension(FileUpload $fileUpload): ?string
    {
        $extension = strtolower(pathinfo($fileUpload->original_name ?? '', PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    private function extractFromDocx(string $absolutePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($absolutePath);

        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractTextFromElement($element);
            }
        }

        return $text;
    }

    private function extractTextFromElement($element): string
    {
        $text = '';

        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun
            || $element instanceof \PhpOffice\PhpWord\Element\Paragraph
        ) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
            $text .= "\n";
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            $text .= $element->getText();
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $text .= $this->extractTextFromElement($cellElement) . ' | ';
                    }
                }
                $text .= "\n";
            }
        }

        return $text;
    }

    private function extractFromPdf(string $absolutePath): string
    {
        // Try pdftotext CLI tool (available on most Linux servers)
        if (function_exists('exec')) {
            $escaped = escapeshellarg($absolutePath);
            $output  = [];
            exec("pdftotext {$escaped} - 2>/dev/null", $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // Fallback: inform user that PDF extraction is unavailable
        throw new RuntimeException(
            'PDF text extraction is not available on this server. Please convert the contract to DOCX or TXT and re-upload for AI analysis.'
        );
    }

    /**
     * Robustly parse JSON from a model response.
     * Handles markdown fences, literal newlines inside strings, and encoding issues.
     */
    private function parseJsonResponse(string $raw): ?array
    {
        // 1. Strip markdown code fences
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        // 2. Extract from first { to last } in case there is any surrounding commentary
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        // 3. Ensure valid UTF-8 (DOCX extraction may produce non-UTF-8 bytes)
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // 4. Try direct decode first
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // 5. Repair: replace literal unescaped newlines/tabs/CRs inside JSON string values
        //    This handles the case where Claude puts a real newline inside a quoted string.
        $repaired = preg_replace_callback('/("(?:[^"\\\\]|\\\\.)*")/s', function ($m) {
            // Re-escape any literal control characters inside the matched string token
            $inner = substr($m[1], 1, -1); // strip outer quotes
            $inner = str_replace(["\r\n", "\r", "\n", "\t"], ['\\n', '\\n', '\\n', '\\t'], $inner);
            return '"' . $inner . '"';
        }, $text);

        $decoded = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return null;
    }

    /**
     * Phase G4C.3BC — extracted from analyse()'s own top, unchanged, so the
     * job can resolve/reserve a shadow credit amount BEFORE the provider is
     * ever called, using the real normalized input size rather than a
     * second, separate extraction. analyse() calls this itself when no
     * $prepared array is passed in, so every existing caller/test keeps
     * working with zero behaviour change.
     *
     * @return array{text: string, normalized_input_char_count: int}
     */
    public function extractAndRecordDocumentMetrics(ContractAiAnalysis $analysis, FileUpload $fileUpload): array
    {
        $contractText = $this->extractText($fileUpload);

        if (empty(trim($contractText))) {
            throw new RuntimeException('The contract file appears to be empty or could not be read.');
        }

        // Hash the extracted text so identical contract content can reuse a prior
        // completed analysis instead of paying Claude again. Document metrics are
        // recorded here too (single authoritative write point for both) — this is
        // the earliest point in the pipeline that has the extracted text.
        $hash = hash('sha256', $contractText);
        $analysis->update([
            'document_hash'       => $hash,
            'document_char_count' => mb_strlen($contractText),
            'document_file_type'  => $this->fileExtension($fileUpload),
        ]);

        // Phase G4C.2C-2 — computed once here, from the same $contractText already
        // in memory, so AI Credit simulation (a purely internal, non-enforcing
        // read of this value — see App\Services\AI\AiCreditSimulator) never needs
        // a second, costly text extraction. Present on both the cache-hit and
        // real-call return paths below since the underlying document content is
        // identical either way.
        $normalizedInputCharCount = AiInputNormalizer::normalizedCharCount($contractText);

        return ['text' => $contractText, 'normalized_input_char_count' => $normalizedInputCharCount];
    }

    /**
     * Run the AI analysis and return the parsed JSON as an array.
     *
     * @param array{text: string, normalized_input_char_count: int}|null $prepared Pass the
     *   result of a prior extractAndRecordDocumentMetrics() call to avoid extracting the
     *   document text twice (e.g. once early to resolve a shadow credit amount, once here).
     *   Omit for the original, self-contained behaviour.
     */
    public function analyse(ContractAiAnalysis $analysis, FileUpload $fileUpload, ?array $prepared = null): array
    {
        $prepared ??= $this->extractAndRecordDocumentMetrics($analysis, $fileUpload);
        $contractText = $prepared['text'];
        $normalizedInputCharCount = $prepared['normalized_input_char_count'];
        $hash = $analysis->document_hash;

        $cached = ContractAiAnalysis::query()
            ->where('document_hash', $hash)
            ->where('model', $analysis->model)
            ->whereIn('status', ['completed', 'confirmed'])
            ->whereNotNull('raw_response_json')
            ->where('id', '!=', $analysis->id)
            ->latest()
            ->first();

        if ($cached) {
            Log::info('Reusing cached contract analysis', [
                'analysis_id' => $analysis->id,
                'reused_from' => $cached->id,
            ]);

            // provider_called = false is the authoritative record of a cache hit;
            // estimated_cost is 0 here for the same reason it's 0 anywhere else in
            // this file — zero tokens were spent — so this is the single place that
            // value is decided for the cache-reuse path.
            $analysis->update([
                'provider_called' => false,
                'estimated_cost'  => 0.0,
            ]);

            // Reuse the stored result; zero token usage means zero additional cost.
            return [
                'data'                        => $cached->raw_response_json,
                'tokens_input'                => 0,
                'tokens_output'               => 0,
                'normalized_input_char_count' => $normalizedInputCharCount,
            ];
        }

        $provider = $this->makeProvider();

        // Recorded immediately before the call so a subsequent provider failure
        // still correctly shows the call was actually attempted, not skipped.
        $analysis->update(['provider_called' => true]);

        $result = $provider->complete(
            ContractAnalysisPrompt::system(),
            ContractAnalysisPrompt::user($contractText)
        );

        // Persist the raw response and usage immediately — BEFORE parsing — so that even if
        // parsing fails the (already paid-for) response is not lost and can be re-parsed later
        // without calling Claude again. estimated_cost is computed and persisted here ONLY —
        // this is the single authoritative place it is calculated; nothing downstream
        // (AnalyseContractWithAiJob) recomputes it. now() here IS the provider-call
        // timestamp passed to estimateCost() — captured once, right after the real
        // call returns, so pricing is resolved against when the call actually
        // happened, not whenever this row might be re-read later.
        $calledAt = now();

        $analysis->update([
            'raw_response_text' => $result['text'] ?? null,
            'stop_reason'       => $result['stop_reason'] ?? null,
            'tokens_input'      => $result['tokens_input'],
            'tokens_output'     => $result['tokens_output'],
            'estimated_cost'    => $this->estimateCost($result['tokens_input'], $result['tokens_output'], $analysis->model, $calledAt),
        ]);

        $decoded = $this->parseJsonResponse($result['text']);

        if ($decoded === null) {
            $truncated = ($result['stop_reason'] ?? null) === 'max_tokens';

            Log::error('Claude returned invalid JSON for contract analysis', [
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
            'data'                        => $decoded,
            'tokens_input'                => $result['tokens_input'],
            'tokens_output'               => $result['tokens_output'],
            'normalized_input_char_count' => $normalizedInputCharCount,
        ];
    }

    /**
     * Re-attempt parsing of an analysis's already-stored raw response. Makes NO Claude call,
     * so it consumes no credits. Returns the decoded array on success, or null if the stored
     * text still cannot be parsed (e.g. it was genuinely truncated).
     */
    public function reparse(ContractAiAnalysis $analysis): ?array
    {
        if (empty($analysis->raw_response_text)) {
            return null;
        }

        return $this->parseJsonResponse($analysis->raw_response_text);
    }

    /**
     * The single authoritative cost calculation (see G4C.1/G4C.1A/G4C.1A.1).
     * Pricing is effective-dated per model — see config/ai_pricing.php and
     * AiPricingSchedule — and resolved against $at (the provider-call
     * timestamp), NEVER the current date, so a historical analysis's cost
     * never silently changes when pricing changes later. Returns null (never
     * a guessed rate) when $model has no configured schedule, or no period
     * in it covers $at — callers must leave estimated_cost null in that case
     * rather than persist a fabricated number.
     */
    public function estimateCost(int $tokensInput, int $tokensOutput, string $model, \DateTimeInterface $at): ?float
    {
        $rate = $this->pricingSchedule->rateFor($model, $at);

        if ($rate === null) {
            return null;
        }

        return round(
            ($tokensInput * $rate['input_per_million'] + $tokensOutput * $rate['output_per_million']) / 1_000_000,
            6
        );
    }
}
