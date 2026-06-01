<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class BrandingSetting extends Model {
    use HasFactory;
    protected $fillable = ['organization_id','logo_path','cover_image_path','letterhead_path','header_template_path','footer_template_path','logo_dark_path','favicon_path','primary_color','secondary_color','accent_color','font_family','company_display_name','tagline','description','email_footer','signature_path','custom_css'];
    protected $casts = ['custom_css'=>'array'];
    public function organization() { return $this->belongsTo(Organization::class); }
}
