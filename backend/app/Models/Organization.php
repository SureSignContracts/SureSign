<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Organization extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = ['name','contact_name','slug','email','phone','website','address','city','state','postcode','country','timezone','registration_number','vat_number','abn','acn','is_active','is_onboarded'];
    protected $casts = ['is_active' => 'boolean', 'is_onboarded' => 'boolean'];
    public function users()           { return $this->hasMany(User::class); }
    public function projects()        { return $this->hasMany(Project::class); }
    public function fileUploads()     { return $this->hasMany(\App\Models\FileUpload::class); }
    public function branding()        { return $this->hasOne(BrandingSetting::class); }
    public function contracts()       { return $this->hasMany(Contract::class); }
    public function documents()       { return $this->hasMany(Document::class); }
    public function aiConversations() { return $this->hasMany(AiConversation::class); }
    public function activities()      { return $this->hasMany(ProjectActivity::class); }
}
