<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Contract extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = ['project_id','organization_id','created_by','type','title','reference_number','form_of_contract','party_name','contract_sum','currency','retention_percentage','retention_cap_percentage','payment_terms_days','execution_date','commencement_date','completion_date','status','notes','key_dates','key_obligations'];
    protected $casts = ['contract_sum'=>'decimal:2','execution_date'=>'date','commencement_date'=>'date','completion_date'=>'date','key_dates'=>'array','key_obligations'=>'array'];
    public function project()              { return $this->belongsTo(Project::class); }
    public function organization()         { return $this->belongsTo(Organization::class); }
    public function creator()              { return $this->belongsTo(User::class,'created_by'); }
    public function paymentApplications()  { return $this->hasMany(PaymentApplication::class); }
    public function variations()           { return $this->hasMany(Variation::class); }
    public function eotRequests()          { return $this->hasMany(EotRequest::class); }
}
