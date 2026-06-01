<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Project extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = ['organization_id','client_id','created_by','name','code','description','status','type','contract_type','contract_value','currency','retention_percentage','retention_cap_percentage','payment_terms_days','start_date','end_date','practical_completion_date','address','city','state','postcode','country','metadata'];
    protected $casts = ['contract_value'=>'decimal:2','retention_percentage'=>'decimal:2','start_date'=>'date','end_date'=>'date','practical_completion_date'=>'date','metadata'=>'array'];
    public function organization()    { return $this->belongsTo(Organization::class); }
    public function client()          { return $this->belongsTo(Client::class); }
    public function creator()         { return $this->belongsTo(User::class, 'created_by'); }
    public function users()           { return $this->belongsToMany(User::class, 'project_users')->withPivot('role')->withTimestamps(); }
    public function contacts()        { return $this->hasMany(ProjectContact::class); }
    public function contracts()       { return $this->hasMany(Contract::class); }
    public function rfis()            { return $this->hasMany(Rfi::class); }
    public function siteInstructions(){ return $this->hasMany(SiteInstruction::class); }
    public function siteDiaries()     { return $this->hasMany(SiteDiary::class); }
    public function meetingMinutes()  { return $this->hasMany(MeetingMinutes::class); }
    public function eotRequests()     { return $this->hasMany(EotRequest::class); }
    public function documents()       { return $this->hasMany(Document::class); }
    public function fileUploads()     { return $this->hasMany(FileUpload::class); }
    public function workflows()       { return $this->hasMany(WorkflowInstance::class); }
    public function reports()         { return $this->hasMany(Report::class); }
    public function folders()         { return $this->hasMany(ProjectFolder::class); }
    public function aiConversations() { return $this->hasMany(AiConversation::class); }
    public function paymentApplications(){ return $this->hasMany(PaymentApplication::class); }
    public function variations()      { return $this->hasMany(Variation::class); }
    public function activities()      { return $this->hasMany(ProjectActivity::class); }
    public function snags()           { return $this->hasMany(Snag::class); }
    public function qaReports()       { return $this->hasMany(QaReport::class); }
    public function closeouts()          { return $this->hasMany(Closeout::class); }
    public function adjudicationCases()  { return $this->hasMany(AdjudicationCase::class); }
}
