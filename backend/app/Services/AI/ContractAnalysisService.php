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
        $model  = $settings->ai_model ?? config('ai.anthropic.model', 'claude-3-5-sonnet-latest');

        return new ClaudeAiProvider($apiKey, $model);
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
     * Run the AI analysis and return the parsed JSON as an array.
     */
    public function analyse(ContractAiAnalysis $analysis, FileUpload $fileUpload): array
    {
        $contractText = $this->extractText($fileUpload);

        if (empty(trim($contractText))) {
            throw new RuntimeException('The contract file appears to be empty or could not be read.');
        }

        // Hash the extracted text so identical contract content can reuse a prior
        // completed analysis instead of paying Claude again.
        $hash = hash('sha256', $contractText);
        $analysis->update(['document_hash' => $hash]);

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

            // Reuse the stored result; zero token usage means zero additional cost.
            return [
                'data'          => $cached->raw_response_json,
                'tokens_input'  => 0,
                'tokens_output' => 0,
            ];
        }

        $provider = $this->makeProvider();

        $result = $provider->complete(
            ContractAnalysisPrompt::system(),
            ContractAnalysisPrompt::user($contractText)
        );

        // Persist the raw response and usage immediately — BEFORE parsing — so that even if
        // parsing fails the (already paid-for) response is not lost and can be re-parsed later
        // without calling Claude again.
        $analysis->update([
            'raw_response_text' => $result['text'] ?? null,
            'stop_reason'       => $result['stop_reason'] ?? null,
            'tokens_input'      => $result['tokens_input'],
            'tokens_output'     => $result['tokens_output'],
            'estimated_cost'    => $this->estimateCost($result['tokens_input'], $result['tokens_output']),
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
            'data'          => $decoded,
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
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

    /** Anthropic Sonnet pricing: $3/M input, $15/M output. */
    public function estimateCost(int $tokensInput, int $tokensOutput): float
    {
        return round(($tokensInput * 3 + $tokensOutput * 15) / 1_000_000, 6);
    }
}
