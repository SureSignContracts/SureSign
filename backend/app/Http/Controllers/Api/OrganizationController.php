<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationUrlSlugRequest;
use App\Services\Organizations\OrganizationUrlSlugService;
use App\Models\ActivityLog;
use App\Models\BrandingSetting;
use App\Models\Organization;
use App\Services\Admin\OrganizationSubscriptionAdminService;
use App\Services\FileSecurityService;
use App\Services\TimezoneResolver;
use App\Support\Organizations\BrandingCacheInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class OrganizationController extends Controller
{
    // ─── Onboarding ──────────────────────────────────────────────────────────

    /**
     * Step 1 – Update the authenticated user's profile (personal details + optional password).
     * Saves to the users table — no duplication into clients.
     */
    public function onboardProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'email'       => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone'       => 'nullable|string|max:50',
            'password'    => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'address'     => 'nullable|string|max:500',
            'city'        => 'nullable|string|max:100',
            'province'    => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country'     => 'nullable|string|max:100',
        ]);

        $data = [
            'first_name'  => $validated['first_name'],
            'last_name'   => $validated['last_name'],
            'name'        => $validated['first_name'] . ' ' . $validated['last_name'],
            'email'       => $validated['email'],
            'phone'       => $validated['phone']       ?? $user->phone,
            'address'     => $validated['address']     ?? null,
            'city'        => $validated['city']        ?? null,
            'province'    => $validated['province']    ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country'     => $validated['country']     ?? null,
        ];

        if (!empty($validated['password'])) {
            $data['password'] = $validated['password']; // Model casts to hashed
        }

        $user->update($data);

        return response()->json(['data' => $user->fresh(), 'message' => 'Profile saved.']);
    }

    /**
     * Step 2 – Save company identity & location to the organizations table.
     */
    public function onboardCompany(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'acn'      => 'nullable|string|max:50',
            'abn'      => 'nullable|string|max:50',
            'email'    => 'nullable|email|max:255',
            'phone'    => 'nullable|string|max:50',
            'website'  => 'nullable|url|max:255',
            'address'  => 'nullable|string|max:500',
            'city'     => 'nullable|string|max:100',
            'state'    => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:20',
            'country'  => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $org  = $user->organization;

        // Generate unique slug from company name
        $slug = Str::slug($validated['name']);
        $base = $slug;
        $i    = 1;
        while (Organization::where('slug', $slug)->when($org, fn($q) => $q->where('id', '!=', $org->id))->exists()) {
            $slug = $base . '-' . $i++;
        }

        if (!$org) {
            // New user with no org yet — create one and associate it
            $org = Organization::create(array_merge($validated, ['slug' => $slug]));
            $user->update(['organization_id' => $org->id]);
        } else {
            $org->update(array_merge($validated, ['slug' => $slug]));
        }

        // Ensure branding row exists (so step 3 can update it)
        BrandingSetting::firstOrCreate(
            ['organization_id' => $org->id],
            ['primary_color' => '#0a0a0a', 'company_display_name' => $validated['name']]
        );

        return response()->json(['data' => $org->fresh(), 'message' => 'Company details saved.']);
    }

    /**
     * Step 3 – Finalise onboarding: mark org as onboarded.
     * Image uploads use the existing /organization/logo etc. endpoints.
     */
    public function onboardFinalize(Request $request)
    {
        $org = $request->user()->organization;
        if (!$org) {
            return response()->json(['message' => 'No organization found for this user.'], 422);
        }
        $org->update(['is_onboarded' => true]);

        return response()->json(['message' => 'Onboarding complete.', 'organization' => $org->fresh()]);
    }

    /** Legacy single-step onboard (kept for backward compat) */
    public function onboard(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:50',
            'website'      => 'nullable|url|max:255',
            'address'      => 'nullable|string|max:500',
            'city'         => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'postcode'     => 'nullable|string|max:20',
            'country'      => 'nullable|string|max:100',
            'acn'          => 'nullable|string|max:50',
            'abn'          => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $org  = $user->organization;
        $slug = Str::slug($validated['name']);
        $base = $slug; $i = 1;
        while (Organization::where('slug', $slug)->when($org, fn($q) => $q->where('id', '!=', $org->id))->exists()) {
            $slug = $base . '-' . $i++;
        }
        $validated['slug']         = $slug;
        $validated['is_onboarded'] = true;
        if (!$org) {
            $org = Organization::create($validated);
            $user->update(['organization_id' => $org->id]);
        } else {
            $org->update($validated);
        }

        BrandingSetting::firstOrCreate(
            ['organization_id' => $org->id],
            ['primary_color' => '#0a0a0a', 'company_display_name' => $validated['name']]
        );

        return response()->json(['message' => 'Onboarding complete.', 'organization' => $org->fresh()]);
    }

    // ─── Organization CRUD ───────────────────────────────────────────────────

    public function show(Request $request)
    {
        $org = $request->user()
            ->organization()
            ->withCount(['users', 'projects'])
            ->with('branding')
            ->first();
        return response()->json(['data' => $org]);
    }

    public function showById(Request $request, $id)
    {
        $org = Organization::withCount(['users', 'projects'])
            ->with('branding:organization_id,logo_path')
            ->findOrFail($id);
        $org->logo_url = $org->branding?->logo_path
            ? url('storage/' . $org->branding->logo_path)
            : null;
        return response()->json(['data' => $org]);
    }

    /**
     * G4A — read-only Organisation Subscription Administration. Gated by
     * the same 'role:Super Admin|Admin' route group as showById() above;
     * organisation isolation is not a concern here (unlike a customer-
     * facing endpoint) because a platform operator is explicitly permitted
     * to view any organisation's subscription state — never a Client.
     */
    public function subscription(string $id, OrganizationSubscriptionAdminService $service)
    {
        $organization = Organization::findOrFail($id);

        return response()->json(['data' => $service->forOrganization($organization)]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // Super Admin / Admin are platform-wide, not a member of any single
        // organization — there is no "own org" for them to update here.
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return response()->json(['message' => 'Platform administrators have no organization to update here.'], 422);
        }

        $org = $user->organization;
        $validated = $request->validate([
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:50',
            'website'      => 'nullable|url|max:255',
            'address'      => 'nullable|string|max:500',
            'city'         => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'postcode'     => 'nullable|string|max:20',
            'country'      => 'nullable|string|max:100',
            'abn'          => 'nullable|string|max:50',
            // Organisation default currency — projects with no explicit
            // override inherit this (see CurrencyService::resolveCode).
            // Changing it never touches projects that already have their own
            // explicit currency; it only changes what un-overridden projects
            // resolve to going forward, since resolution reads live from
            // organization.currency rather than being copied onto projects.
            'currency'     => 'nullable|string|size:3',
            // 'sometimes' (not 'nullable') — timezone is a required, non-null
            // column, so a request may omit it to leave the current value
            // untouched, but if the key IS present it must be a real IANA
            // identifier (Laravel's built-in `timezone` rule rejects
            // "UTC+8"/"GMT+1"/raw offsets), never blank.
            'timezone'     => 'sometimes|required|timezone',
        ]);
        if (!empty($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }
        $org->update($validated);
        return response()->json($org->fresh());
    }

    // ─── URL Branding (Organisation URL Branding, Phase 1) ───────────────────

    /**
     * (Phase 2) Read-only slug history — Super Admin/Admin, matches the
     * general Organisation read-access convention elsewhere in this
     * controller. Never exposed to a Client-role/customer-facing surface.
     */
    public function urlSlugHistory(Organization $organization)
    {
        return response()->json([
            'data' => $organization->urlSlugHistory()->orderByDesc('released_at')->get(['url_slug', 'released_at']),
        ]);
    }

    /**
     * Super Admin ONLY (see routes/api.php) — set/change the organisation's
     * branded hostname slug. `url_slug` may be null to explicitly clear it
     * without a separate remove call from the same form. See
     * App\Http\Requests\UpdateOrganizationUrlSlugRequest for validation
     * (format, reserved names, uniqueness) and
     * App\Support\Organizations\UrlSlugValidator for the rules themselves.
     */
    public function updateUrlSlug(UpdateOrganizationUrlSlugRequest $request, Organization $organization, OrganizationUrlSlugService $service)
    {
        $organization = $service->apply(
            $organization,
            $request->normalizedUrlSlug(),
            'super_admin',
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json(['data' => $organization]);
    }

    /**
     * Explicit "Remove" action — functionally identical to updateUrlSlug()
     * with a null value, kept as its own endpoint/button per the brief's
     * Super Admin experience requirements (view/set/remove/preview as
     * distinct, always-confirmed actions).
     */
    public function removeUrlSlug(Request $request, Organization $organization, OrganizationUrlSlugService $service)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ]);

        $organization = $service->apply($organization, null, 'super_admin', $request->user(), $validated['reason']);

        return response()->json(['data' => $organization]);
    }

    // ─── Branding ────────────────────────────────────────────────────────────

    public function getBranding(Request $request)
    {
        $user = $request->user();

        // Super Admin and Admin both operate at the platform level, not as a
        // member of any single organization (per this app's role model —
        // only Client is org-scoped) — never resolve or apply a specific
        // org's branding for either role, whatever organization_id happens
        // to hold on the account. Otherwise the accent colour (applied
        // directly onto document.documentElement, which persists across
        // client-side navigation) can end up "inherited" from whichever
        // org's project was last viewed.
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return response()->json(['data' => $this->defaultBrandingResource()]);
        }

        $org      = $user->organization;
        $branding = BrandingSetting::firstOrCreate(
            ['organization_id' => $org->id],
            ['primary_color' => '#0a0a0a', 'company_display_name' => $org->name]
        );

        return response()->json(['data' => $this->brandingResource($org, $branding)]);
    }

    private function defaultBrandingResource(): array
    {
        return [
            'company_name'   => 'SureSign',
            'description'    => '',
            'tagline'        => '',
            'primary_color'  => '#0a0a0a',
            'accent_color'   => null,
            'email_footer'   => '',
            'logo_url'       => null,
            'cover_url'      => null,
            'header_url'     => null,
            'footer_url'     => null,
            'contact_email'  => '',
            'contact_phone'  => '',
            'website'        => '',
            'address'        => '',
            'city'           => '',
            'state'          => '',
            'postcode'       => '',
            'country'        => '',
            'vat_number'     => '',
            'timezone'       => TimezoneResolver::platformTimezone(),
            'currency'           => null,
            'effective_currency' => \App\Services\CurrencyService::platformCode(),
        ];
    }

    public function updateBranding(Request $request)
    {
        $user = $request->user();

        // Super Admin / Admin are platform-wide, not a member of any single
        // organization — there is no "own org" branding for them to update.
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return response()->json(['message' => 'Platform administrators have no organization branding to update.'], 422);
        }

        $validated = $request->validate([
            'company_name'  => 'nullable|string|max:255',
            'description'   => 'nullable|string|max:2000',
            'tagline'       => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:20',
            'accent_color'  => 'nullable|string|max:20',
            'email_footer'  => 'nullable|string|max:5000',
        ]);

        $org      = $user->organization;
        $branding = BrandingSetting::updateOrCreate(
            ['organization_id' => $org->id],
            array_filter([
                'company_display_name' => $validated['company_name'] ?? null,
                'description'          => $validated['description']  ?? null,
                'tagline'              => $validated['tagline']       ?? null,
                'primary_color'        => $validated['primary_color'] ?? null,
                'accent_color'         => $validated['accent_color']  ?? null,
                'email_footer'         => $validated['email_footer']  ?? null,
            ], fn($v) => $v !== null)
        );

        BrandingCacheInvalidator::forgetForOrganization($org);

        return response()->json(['data' => $this->brandingResource($org, $branding->fresh())]);
    }

    // ─── Logo / Image Uploads ────────────────────────────────────────────────

    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|file|max:2048']);
        $branding = $this->getOrCreateBranding($request);
        $file = $request->file('logo');

        $path = strtolower($file->getClientOriginalExtension()) === 'svg'
            ? $this->storeSanitizedSvg($file, 'logos/' . $request->user()->organization_id)
            : $this->storeValidatedImage($file, 'logos/' . $request->user()->organization_id);

        if ($branding->logo_path) \Storage::disk('public')->delete($branding->logo_path);
        $branding->update(['logo_path' => $path]);

        BrandingCacheInvalidator::forgetForOrganization($request->user()->organization);

        return response()->json(['logo_url' => url('storage/' . $path)]);
    }

    public function uploadCover(Request $request)
    {
        $request->validate(['cover' => 'required|file|max:5120']);
        $branding = $this->getOrCreateBranding($request);

        $path = $this->storeValidatedImage($request->file('cover'), 'covers/' . $request->user()->organization_id);

        if ($branding->cover_image_path) \Storage::disk('public')->delete($branding->cover_image_path);
        $branding->update(['cover_image_path' => $path]);

        return response()->json(['cover_url' => url('storage/' . $path)]);
    }

    public function uploadLetterheadHeader(Request $request)
    {
        $request->validate(['image' => 'required|file|max:5120']);
        $branding = $this->getOrCreateBranding($request);

        $path = $this->storeValidatedImage($request->file('image'), 'letterheads/' . $request->user()->organization_id);

        if ($branding->header_template_path) \Storage::disk('public')->delete($branding->header_template_path);
        $branding->update(['header_template_path' => $path]);

        return response()->json(['header_url' => url('storage/' . $path)]);
    }

    public function uploadLetterheadFooter(Request $request)
    {
        $request->validate(['image' => 'required|file|max:5120']);
        $branding = $this->getOrCreateBranding($request);

        $path = $this->storeValidatedImage($request->file('image'), 'letterheads/' . $request->user()->organization_id);

        if ($branding->footer_template_path) \Storage::disk('public')->delete($branding->footer_template_path);
        $branding->update(['footer_template_path' => $path]);

        return response()->json(['footer_url' => url('storage/' . $path)]);
    }

    /**
     * Validate (extension + MIME + magic bytes) and store a raster image
     * upload with a random filename. Shared by every branding image field.
     */
    private function storeValidatedImage(\Illuminate\Http\UploadedFile $file, string $directory): string
    {
        FileSecurityService::assertSafe($file, FileSecurityService::IMAGES);
        $storedName = FileSecurityService::randomStorageName($file);

        return $file->storeAs($directory, $storedName, 'public');
    }

    /**
     * Sanitise and store an SVG upload. Rejects anything that isn't
     * confidently parseable SVG rather than passing it through — see
     * FileSecurityService and SvgSanitizer for exactly what is stripped.
     */
    private function storeSanitizedSvg(\Illuminate\Http\UploadedFile $file, string $directory): string
    {
        return FileSecurityService::storeSanitizedSvg($file, $directory);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getOrCreateBranding(Request $request): BrandingSetting
    {
        $user = $request->user();

        // Super Admin / Admin are platform-wide, not a member of any single
        // organization — there is no "own org" logo/letterhead for them to
        // manage through this endpoint.
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            abort(422, 'Platform administrators have no organization branding assets to manage here.');
        }

        $org = $user->organization;
        return BrandingSetting::firstOrCreate(
            ['organization_id' => $org->id],
            ['primary_color' => '#000000', 'company_display_name' => $org->name]
        );
    }

    private function brandingResource(Organization $org, BrandingSetting $b): array
    {
        return [
            'company_name'   => $b->company_display_name ?? $org->name,
            'description'    => $b->description ?? '',
            'tagline'        => $b->tagline ?? '',
            'primary_color'  => $b->primary_color ?? '#0a0a0a',
            'accent_color'   => $b->accent_color  ?? null,
            'email_footer'   => $b->email_footer ?? '',
            'logo_url'       => $b->logo_path            ? url('storage/' . $b->logo_path)            : null,
            'cover_url'      => $b->cover_image_path     ? url('storage/' . $b->cover_image_path)     : null,
            'header_url'     => $b->header_template_path ? url('storage/' . $b->header_template_path) : null,
            'footer_url'     => $b->footer_template_path ? url('storage/' . $b->footer_template_path) : null,
            // Contact info (for Company Information tab)
            'contact_email'  => $org->email    ?? '',
            'contact_phone'  => $org->phone    ?? '',
            'website'        => $org->website  ?? '',
            'address'        => $org->address  ?? '',
            'city'           => $org->city     ?? '',
            'state'          => $org->state    ?? '',
            'postcode'       => $org->postcode ?? '',
            'country'        => $org->country  ?? '',
            'vat_number'     => $org->abn      ?? '',
            'timezone'       => $org->timezone,
            // `currency` is the raw override (null = inheriting the platform
            // default). `effective_currency` is what actually applies right
            // now — see CurrencyService::resolveOrganizationCode.
            'currency'           => $org->currency,
            'effective_currency' => \App\Services\CurrencyService::resolveOrganizationCode($org),
        ];
    }
}

