<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Organization extends Model {
    use HasFactory, SoftDeletes;
    protected $fillable = ['name','contact_name','slug','url_slug','email','phone','website','address','city','state','postcode','country','currency','timezone','registration_number','vat_number','abn','acn','is_active','is_onboarded'];
    protected $casts = ['is_active' => 'boolean', 'is_onboarded' => 'boolean'];
    public function users()           { return $this->hasMany(User::class); }
    public function projects()        { return $this->hasMany(Project::class); }
    public function fileUploads()     { return $this->hasMany(\App\Models\FileUpload::class); }
    public function branding()        { return $this->hasOne(BrandingSetting::class); }
    public function contracts()       { return $this->hasMany(Contract::class); }
    public function documents()       { return $this->hasMany(Document::class); }
    public function aiConversations() { return $this->hasMany(AiConversation::class); }
    public function activities()      { return $this->hasMany(ProjectActivity::class); }
    public function billingCustomer() { return $this->hasOne(BillingCustomer::class); }
    public function subscriptions()   { return $this->hasMany(Subscription::class); }
    public function urlSlugHistory()  { return $this->hasMany(OrganizationUrlSlugHistory::class); }
    public function domains()         { return $this->hasMany(OrganizationDomain::class); }

    /**
     * Organisation URL Branding, Phase 2 — the organisation's currently
     * active customer-owned domain, if any. Deliberately `active` only
     * (not `verified`) — a verified-but-not-yet-activated domain must
     * never be used for URL generation; see
     * App\Services\OrganisationUrlGenerator's priority order.
     */
    public function activeDomain()
    {
        return $this->hasOne(OrganizationDomain::class)->where('status', \App\Support\Organizations\DomainStatus::ACTIVE);
    }

    /**
     * The organisation's current pending/active subscription, if any — see
     * App\Support\Billing\SubscriptionStatus::LIVE for what counts as
     * "live". Convenience accessor; SubscriptionService (Phase 5+) is the
     * one place that decides whether a NEW one may be created.
     */
    public function liveSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', \App\Support\Billing\SubscriptionStatus::LIVE)
            ->latestOfMany();
    }
}
