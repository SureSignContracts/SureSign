<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model {
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['user_id','organization_id','event','auditable_type','auditable_id','old_values','new_values','ip_address','user_agent','url'];
    protected $casts = ['old_values'=>'array','new_values'=>'array','created_at'=>'datetime'];
    public function user()         { return $this->belongsTo(User::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function auditable()    { return $this->morphTo(); }
}
