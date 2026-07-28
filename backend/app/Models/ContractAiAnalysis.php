<?php

namespace App\Models;

use App\Support\AI\AiTelemetryIntegrityGuard;
use Illuminate\Database\Eloquent\Model;

class ContractAiAnalysis extends Model
{
    /**
     * Phase G4C.2C-2 — once this row's status is already terminal
     * (completed/confirmed/failed/cancelled), its execution-telemetry
     * fields (AiTelemetryIntegrityGuard::PROTECTED_FIELDS) are treated as
     * immutable historical evidence. See that class's own docblock for
     * exactly what is and isn't protected, and why this is a guard rather
     * than a full immutable rebuild of this model.
     */
    protected static function booted(): void
    {
        static::updating(fn (self $analysis) => AiTelemetryIntegrityGuard::assertMutable($analysis));
    }

    protected $fillable = [
        'contract_id',
        'organization_id',
        'project_id',
        'file_upload_id',
        'status',
        'provider',
        'model',
        'workflow',
        'telemetry_schema_version',
        'document_hash',
        'document_char_count',
        'document_file_type',
        'summary',
        'raw_response_json',
        'raw_response_text',
        'stop_reason',
        'provider_called',
        'confirmed_data_json',
        'error_message',
        'failure_category',
        'tokens_input',
        'tokens_output',
        'estimated_cost',
        'started_at',
        'completed_at',
        'duration_ms',
        'queue_attempt',
        'is_final_attempt',
        'created_by',
        'credit_reservation_amount',
        'shadow_enforcement_result',
    ];

    protected $casts = [
        'raw_response_json'   => 'array',
        'confirmed_data_json' => 'array',
        'tokens_input'        => 'integer',
        'tokens_output'       => 'integer',
        'estimated_cost'      => 'float',
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',
        'provider_called'     => 'boolean',
        'document_char_count' => 'integer',
        'telemetry_schema_version' => 'integer',
        'duration_ms'         => 'integer',
        'queue_attempt'       => 'integer',
        'is_final_attempt'    => 'boolean',
        'credit_reservation_amount' => 'float',
    ];

    public function contract()    { return $this->belongsTo(Contract::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function project()     { return $this->belongsTo(Project::class); }
    public function fileUpload()  { return $this->belongsTo(FileUpload::class); }
    public function creator()     { return $this->belongsTo(User::class, 'created_by'); }

    public function isPending():    bool { return $this->status === 'pending'; }
    public function isProcessing(): bool { return $this->status === 'processing'; }
    public function isCompleted():  bool { return $this->status === 'completed'; }
    public function isFailed():     bool { return $this->status === 'failed'; }
    public function isConfirmed():  bool { return $this->status === 'confirmed'; }
}
