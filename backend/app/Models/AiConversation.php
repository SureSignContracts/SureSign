<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class AiConversation extends Model {
    use HasFactory;
    protected $fillable = ['user_id','project_id','organization_id','contextable_id','contextable_type','title','type','status','token_count'];
    public function user()         { return $this->belongsTo(User::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function messages()     { return $this->hasMany(AiMessage::class); }
    public function outputs()      { return $this->hasMany(AiOutput::class); }
    public function contextable()  { return $this->morphTo(); }
}
