<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractAiAnalysis extends Model
{
    protected $fillable = [
        'contract_id',
        'organization_id',
        'project_id',
        'file_upload_id',
        'status',
        'provider',
        'model',
        'document_hash',
        'summary',
        'raw_response_json',
        'raw_response_text',
        'stop_reason',
        'confirmed_data_json',
        'error_message',
        'tokens_input',
        'tokens_output',
        'estimated_cost',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'raw_response_json'   => 'array',
        'confirmed_data_json' => 'array',
        'tokens_input'        => 'integer',
        'tokens_output'       => 'integer',
        'estimated_cost'      => 'float',
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',
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
