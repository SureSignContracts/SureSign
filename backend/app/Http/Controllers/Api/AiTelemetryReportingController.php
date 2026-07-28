<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\AiTelemetryReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase G4C.2C-2 — internal AI execution / non-enforcing AI Credit
 * simulation reporting. Gated by the `role:Super Admin|Admin` route
 * middleware group (see routes/api.php) — matches the Pricing Management
 * precedent, since both roles are platform-wide (not customer-org scoped)
 * in this codebase's role model. Client users and organisation-scoped
 * roles never reach this controller.
 *
 * Every response here is built exclusively from
 * App\Support\AI\AiAnalysisPresenter's internal*() methods — never the
 * customerFacing*() ones — via AiTelemetryReportingService. Read-only:
 * this controller has no mutating action.
 */
class AiTelemetryReportingController extends Controller
{
    public function __construct(private readonly AiTelemetryReportingService $service)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->service->summary($this->filters($request)));
    }

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->service->telemetryHealth($this->filters($request)));
    }

    public function detail(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));

        return response()->json($this->service->detail($this->filters($request), $perPage, $page));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->service->exportRows($this->filters($request));

        $columns = ['id', 'workflow', 'organization_id', 'organization_name', 'status', 'provider', 'model',
            'document_char_count', 'document_file_type', 'provider_called', 'tokens_input', 'tokens_output',
            'estimated_cost', 'failure_category', 'duration_ms', 'started_at', 'completed_at', 'created_at'];

        $candidateKeys = $rows
            ->flatMap(fn ($row) => collect($row['simulations'])->pluck('candidate_policy_key'))
            ->unique()
            ->values()
            ->all();

        foreach ($candidateKeys as $key) {
            $columns[] = "simulation_{$key}_status";
            $columns[] = "simulation_{$key}_credits";
        }

        return Response::streamDownload(function () use ($rows, $columns, $candidateKeys) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                $simulationsByKey = collect($row['simulations'])->keyBy('candidate_policy_key');

                $line = [
                    $row['id'], $row['workflow'], $row['organization_id'], $row['organization_name'],
                    $row['status'], $row['provider'], $row['model'],
                    $row['document_char_count'], $row['document_file_type'], $row['provider_called'],
                    $row['tokens_input'], $row['tokens_output'], $row['estimated_cost'],
                    $row['failure_category'], $row['duration_ms'], $row['started_at'],
                    $row['completed_at'], $row['created_at'],
                ];

                foreach ($candidateKeys as $key) {
                    $sim = $simulationsByKey->get($key);
                    $line[] = $sim['simulation_status'] ?? null;
                    $line[] = $sim['hypothetical_credits'] ?? null;
                }

                fputcsv($handle, $line);
            }

            fclose($handle);
        }, 'ai-telemetry-export-' . now()->format('Y-m-d_His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'organization_id'  => $request->input('organization_id') ? (int) $request->input('organization_id') : null,
            'workflow'         => $request->input('workflow'),
            'status'           => $request->input('status'),
            'provider_called'  => $request->has('provider_called') ? $request->boolean('provider_called') : null,
            'failure_category' => $request->input('failure_category'),
            'date_from'        => $request->input('date_from'),
            'date_to'          => $request->input('date_to'),
        ];
    }
}
