<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectPortfolioController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\RfiController;
use App\Http\Controllers\Api\VariationController;
use App\Http\Controllers\Api\PaymentApplicationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\OrganisationDocumentController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CommercialOverviewController;
use App\Http\Controllers\Api\SiteAdministrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\OrganizationBrandingUrlController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationDomainController;
use App\Http\Controllers\Api\OrganizationSubscriptionAssignmentController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SiteDiaryController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\EotRequestController;
use App\Http\Controllers\Api\DelayEventController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\DeliveryDocumentController;
use App\Http\Controllers\Api\DrawingController;
use App\Http\Controllers\Api\DrawingHotspotController;
use App\Http\Controllers\Api\DrawingHotspotLinkController;
use App\Http\Controllers\Api\DrawingRecordLinksController;
use App\Http\Controllers\Api\DrawingRevisionController;
use App\Http\Controllers\Api\LossAndExpenseClaimController;
use App\Http\Controllers\Api\PayLessNoticeController;
use App\Http\Controllers\Api\SiteInstructionController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ProjectActivityController;
use App\Http\Controllers\Api\SuresignSettingController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\TradePackageController;
use App\Http\Controllers\Api\TradePackagePackageGenerationController;
use App\Http\Controllers\Api\SnagController;
use App\Http\Controllers\Api\QaReportController;
use App\Http\Controllers\Api\CloseoutController;
use App\Http\Controllers\Api\AdjudicationCaseController;
use App\Http\Controllers\Api\AdjudicationDocumentController;
use App\Http\Controllers\Api\AdjudicationDeadlineController;
use App\Http\Controllers\Api\ProgrammeMilestoneController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\CompaniesHouseController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\MarketingContactController;
use App\Http\Controllers\Api\AccountAccessEnquiryController;
use App\Http\Controllers\Api\GenerateTradePackageFoldersController;
use App\Http\Controllers\Api\TradePackageCatalogueController;
use App\Http\Controllers\Api\TradePackageAiController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DocumentRegisterController;
use App\Http\Controllers\Api\PaymentNoticeController;
use App\Http\Controllers\Api\RetentionReleaseController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\FinalAccountController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SupportTicketMessageController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\SystemStatusController;
use App\Http\Controllers\Api\PlatformAnnouncementController;
use App\Http\Controllers\Api\TourMilestoneController;
use App\Http\Controllers\Api\ApplicationMonitoringController;
use App\Http\Controllers\Api\AiCreditsGrantController;
use App\Http\Controllers\Api\AiCreditsOperationsController;
use App\Http\Controllers\Api\AiTelemetryReportingController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\AiCreditUsageController;
use App\Http\Controllers\Api\SubscriptionIntelligenceController;
use App\Http\Controllers\Api\BillingPortalController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PlanChangeController;
use App\Http\Controllers\Api\SubscriptionCancellationController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentTypeController;
use App\Http\Controllers\Api\ConsultancyAvailabilityController;
use App\Http\Controllers\Api\ConsultancyOperationsController;
use App\Http\Controllers\Api\ConsultancyServiceController;
use App\Http\Controllers\Api\ConsultancySettingsController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\ConsultationReservationController;
use App\Http\Controllers\Api\GoogleCalendarSyncController;
use App\Http\Controllers\Api\GoogleIntegrationController;
use App\Http\Controllers\Api\PublicAppointmentActionController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\PublicAppointmentController;
use App\Http\Controllers\Api\PublicOrganisationBrandingController;
use App\Http\Controllers\Api\PublicConsultancyReservationController;
use App\Http\Controllers\Api\PublicConsultationController;
use App\Http\Controllers\Api\PublicConsultationViewController;
use App\Models\FileUpload;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SureSign API Routes
|--------------------------------------------------------------------------
*/

// Public auth routes — each carries its own named limiter (defined in
// AppServiceProvider::configureRateLimiters) on top of the general `api`
// group throttle, since brute-force/enumeration/abuse risk differs per
// endpoint and a single shared bucket would either be too loose for login
// or too tight for normal dashboard traffic.
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');
    Route::post('/email/verify', [AuthController::class, 'verifyEmailLink'])->middleware('throttle:email-verify-link');
});

Route::get('/guest-settings', [SuresignSettingController::class, 'guestShow']);

// Public marketing-site Pricing page — no auth. Only plans/features/FAQs/items
// that are active, visible, and published are ever returned; see
// PricingManagementService::publicPayload() and
// internal-docs/super-admin/pricing-management.md.
Route::get('/pricing', [PricingController::class, 'publicShow']);

// Public Stripe webhook endpoint — no auth (Stripe's servers cannot
// authenticate as a SureSign user), no CSRF (this app's `api` middleware
// group is never subject to CSRF at all — confirmed in bootstrap/app.php;
// this route needs no explicit exemption). Trust comes entirely from
// signature verification inside WebhookIngestionService. See
// internal-docs/super-admin/subscription-billing.md.
//
// Deliberately EXCLUDES the generic 'api' group throttle
// (`Illuminate\Routing\Middleware\ThrottleRequests:api`, wired in via
// bootstrap/app.php's `throttleApi()`) rather than stacking it alongside
// `billing-webhooks` — confirmed via `php artisan route:list -v` that both
// were otherwise present. The generic 'api' limiter keys unauthenticated
// requests by IP, exactly like `billing-webhooks` does, so stacking both
// would mean two IDENTICAL-key buckets gating the same traffic (redundant,
// not additional protection) while adding needless confusion about which
// limit actually governs Stripe's deliveries. `billing-webhooks` alone is
// the intentional, tuned-for-Stripe limit; this route is fully isolated
// from whatever quota customer-facing authenticated API traffic consumes.
Route::post('/billing/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:billing-webhooks');

// Public marketing-site lead capture (suresigncontracts.app/book-a-demo) — no auth.
// Superseded by the public Appointments booking flow below (Phase 3) for
// the marketing site's own CTA, but left in place/unused rather than
// removed — see internal-docs/super-admin/appointments.md.
Route::post('/demo-requests', [DemoRequestController::class, 'store'])->middleware('throttle:demo-request');
Route::post('/marketing-contact', [MarketingContactController::class, 'store'])->middleware('throttle:marketing-contact');
Route::post('/account-access-enquiry', [AccountAccessEnquiryController::class, 'store'])->middleware('throttle:account-access-enquiry');

// Organisation URL Branding (Phase 1, upgraded Phase 2) — public raw-
// hostname-to-branding resolution the marketing site calls before any
// login/token exists (accepts a branded subdomain OR a verified customer
// domain, dots and all — see OrganisationHostResolver). Branding-safe
// fields only — see PublicOrganisationBrandingController.
Route::get('/public/organisation-branding/{host}', [PublicOrganisationBrandingController::class, 'show'])
    ->where('host', '[A-Za-z0-9.-]+')
    ->middleware('throttle:public-booking-read');

// Public Appointments booking (suresigncontracts.app/book/{slug}) — no
// auth. Only Appointment Types with is_public=true and is_active=true are
// ever exposed; every field is treated as untrusted input server-side.
Route::prefix('public')->middleware('throttle:public-booking-read')->group(function () {
    Route::get('/appointment-types/{slug}', [PublicAppointmentController::class, 'showType']);
    Route::get('/appointment-types/{slug}/slots', [PublicAppointmentController::class, 'slots']);
    Route::get('/appointment-types/{slug}/availability', [PublicAppointmentController::class, 'availability']);
});
Route::post('/public/appointment-types/{slug}/book', [PublicAppointmentController::class, 'store'])
    ->middleware('throttle:public-booking');

// Public Consultancy booking (suresigncontracts.app/consultancy) — no auth.
// A separate controller from PublicAppointmentController (see
// PublicConsultationController's docblock) — only Consultancy Services
// flagged enabled+publicly_bookable are ever exposed. Same rate-limit
// buckets as public Appointments booking; not a new limiter.
Route::prefix('public')->middleware('throttle:public-booking-read')->group(function () {
    Route::get('/consultancy-services', [PublicConsultationController::class, 'index']);
    Route::get('/consultancy-services/{code}', [PublicConsultationController::class, 'show']);
    Route::get('/consultancy-services/{code}/slots', [PublicConsultationController::class, 'slots']);
    Route::get('/consultancy-services/{code}/availability', [PublicConsultationController::class, 'availability']);
});
Route::post('/public/consultancy-services/{code}/book', [PublicConsultationController::class, 'store'])
    ->middleware('throttle:public-booking');

// Consultancy Live Booking Upgrade, Stage 2 — temporary slot reservation
// (a hold only, never a payment or a confirmed booking). Same rate-limit
// buckets as the rest of public Consultancy booking; token-based ownership
// (see PublicConsultancyReservationController), not a new access model.
Route::post('/public/consultancy-services/{code}/reservations', [PublicConsultancyReservationController::class, 'store'])
    ->middleware('throttle:public-booking');
Route::prefix('public')->middleware('throttle:public-booking-read')->group(function () {
    Route::get('/consultancy-reservations/{token}', [PublicConsultancyReservationController::class, 'show']);
    Route::get('/consultancy-reservations/{token}/payment', [PublicConsultancyReservationController::class, 'paymentStatus']);
});
Route::post('/public/consultancy-reservations/{token}/cancel', [PublicConsultancyReservationController::class, 'cancel'])
    ->middleware('throttle:public-booking');
Route::post('/public/consultancy-reservations/{token}/checkout', [PublicConsultancyReservationController::class, 'checkout'])
    ->middleware('throttle:public-booking');

// Signed public appointment actions (Phase 4) — cancel/reschedule links
// sent in appointment emails. GET (show confirmation details) and POST
// (perform the action) share the same signed URL — Laravel's `signed`
// middleware validates the URL, not the HTTP verb, so one link a visitor
// clicks serves both requests the marketing confirmation page makes.
Route::middleware(['signed', 'throttle:public-booking-read'])->group(function () {
    Route::get('/public/appointments/{token}/cancel', [PublicAppointmentActionController::class, 'showCancel'])->name('public.appointments.cancel');
    Route::get('/public/appointments/{token}/reschedule', [PublicAppointmentActionController::class, 'showReschedule'])->name('public.appointments.reschedule');
});
Route::middleware(['signed', 'throttle:public-booking'])->group(function () {
    Route::post('/public/appointments/{token}/cancel', [PublicAppointmentActionController::class, 'cancel']);
    Route::post('/public/appointments/{token}/reschedule', [PublicAppointmentActionController::class, 'reschedule']);
});

// Invitation & First-Time Account Setup phase — the public, signed-URL
// "Accept Invitation & Set Up Account" link (UserController::invite() /
// InvitationLinkService). Same GET/POST-share-one-signed-URL pattern as
// the appointment routes above; the show() route never mutates anything,
// so it's safe to load repeatedly before accept() is actually submitted.
Route::middleware(['signed', 'throttle:invitation-view'])->group(function () {
    Route::get('/public/invitations/{user}', [InvitationController::class, 'show'])->name('invitations.show');
});
Route::middleware(['signed', 'throttle:invitation-accept'])->group(function () {
    Route::post('/public/invitations/{user}', [InvitationController::class, 'accept']);
});
// Signed via `signed:date,timezone` — Laravel validates the full signature
// except the `date`/`timezone` keys, which are excluded from the hash on
// both generation and validation (Laravel's own built-in mechanism for a
// signed URL that must tolerate legitimately-varying parameters, the same
// feature intended for stripping third-party tracking params like
// `fbclid`). The frontend takes the base signed URL (no date/timezone)
// from showReschedule()'s response and appends `&date=...&timezone=...`
// freely as the visitor browses — no per-date/per-timezone signature
// needed, and the endpoint is never unsigned. `timezone` only affects
// which timezone returned slot labels are displayed in (see
// AppointmentSchedulingService::generateAvailableSlots()) — it never
// changes what's actually bookable, so excluding it from the signature
// carries the same "purely a varying, non-security-relevant parameter"
// justification as `date` already had.
Route::get('/public/appointments/{token}/reschedule/slots', [PublicAppointmentActionController::class, 'rescheduleSlots'])
    ->middleware(['signed:date,timezone', 'throttle:public-booking-read'])
    ->name('public.appointments.reschedule.slots');

// Consultancy Communications & Global Email Experience Upgrade, Batch 3 —
// read-only public (no-account) consultation pages. Signed exactly like
// every other public Appointment route above (same `public_token`, same
// `signed` middleware) — see AppointmentPublicLinkService's own docblock
// for why these use their own TTL setting instead of the cancel/reschedule
// one. GET-only: no mutation exists on either page this batch.
Route::middleware(['signed', 'throttle:public-booking-read'])->group(function () {
    Route::get('/public/consultations/{token}/view', [PublicConsultationViewController::class, 'show'])->name('public.consultations.view');
    Route::get('/public/consultations/{token}/view/ics', [PublicConsultationViewController::class, 'ics'])->name('public.consultations.view.ics');
    Route::get('/public/consultations/{token}/summary', [PublicConsultationViewController::class, 'summary'])->name('public.consultations.summary');
});

// Authenticated routes — account.status re-checks is_active/banned_at on
// every request (auth:sanctum only proves the token was valid at issuance,
// not that the account is still allowed to use it); password.current blocks
// everything except the handful of routes named below while a forced
// password change is pending. Order matters: an inactive/banned account is
// blocked before the password-change gate is even considered.
Route::middleware(['auth:sanctum', 'account.status', 'password.current', 'track.usage'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('/workspace-context', [AuthController::class, 'workspaceContext'])->name('auth.workspace-context');
        Route::put('/password', [AuthController::class, 'updatePassword'])->middleware('throttle:password-change');
        Route::put('/timezone', [AuthController::class, 'updateTimezone']);
        Route::put('/force-password-change', [AuthController::class, 'forcePasswordChange'])->middleware('throttle:force-password-change')->name('auth.force-password-change');
        Route::post('/email/verification-notification', [AuthController::class, 'sendEmailVerification'])->middleware('throttle:email-verification-resend');
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/action-centre', [DashboardController::class, 'actionCentre']);

    // Support tickets — any authenticated user can submit one for their org
    Route::post('/support-tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-ticket');
    // "My requests" — the authenticated user's own tickets only, not the
    // admin-wide list (see /admin/support-tickets below).
    Route::get('/support-tickets', [SupportTicketController::class, 'myTickets']);
    // Registered before the {supportTicket} wildcard below so this literal
    // path is matched first, not treated as a ticket id.
    Route::get('/support-tickets/recent-activity-preview', [SupportTicketController::class, 'recentActivityPreview']);
    Route::get('/support-tickets/{supportTicket}', [SupportTicketController::class, 'show']);
    // Screenshot preview — ticket owner or platform operator only (not general
    // same-organization access — a support screenshot may contain more
    // personal/sensitive on-screen content than an ordinary project document).
    Route::get('/support-tickets/{supportTicket}/screenshot', [SupportTicketController::class, 'screenshot']);

    // Threaded conversation — shared route for both the Client and platform
    // operators (SupportTicketMessageController branches internally on
    // role, same pattern as show()/screenshot() above). The reply endpoint
    // gets its own rate-limit bucket, separate from ticket creation.
    Route::get('/support-tickets/{supportTicket}/messages', [SupportTicketMessageController::class, 'index']);
    Route::post('/support-tickets/{supportTicket}/messages', [SupportTicketMessageController::class, 'store'])->middleware('throttle:support-ticket-reply');
    Route::get('/support-ticket-messages/{message}/screenshot', [SupportTicketMessageController::class, 'screenshot']);

    // Help Center — Knowledge Base index, System Status, active platform banner.
    Route::get('/knowledge-base', [KnowledgeBaseController::class, 'index']);
    Route::get('/system-status', [SystemStatusController::class, 'index']);
    Route::get('/platform-announcements/active', [PlatformAnnouncementController::class, 'active']);

    // Guided Tours — personal milestone notifications only (see TourMilestoneController).
    Route::post('/tour-milestones', [TourMilestoneController::class, 'store']);

    // Consultancy — authenticated customer-facing surface (Phase C1). Every
    // query is scoped strictly to the caller's own organisation, a
    // deliberately new authorization boundary separate from
    // AppointmentController (which Client users have no access to at all).
    // Deliberately NOT "/consultancy-services" — that exact method+URI is
    // already used by the Super-Admin/Admin-only catalogue apiResource
    // below; Laravel's route collection keys routes by method+URI, so a
    // second route registered later at the identical method+URI silently
    // replaces the first one in the lookup table rather than layering
    // alongside it (confirmed via route:list while building this phase) —
    // hence the distinct "/consultations/bookable-services" path here.
    Route::get('/consultations/bookable-services', [ConsultationController::class, 'bookableServices']);
    // Scheduling info + fixed-mode slot generation for a single service —
    // scoped under /consultations/services/{code} (two segments), which
    // shares no exact method+URI with any admin-catalogue or {appointment}
    // route, so this doesn't hit the same route-collision class of bug
    // documented in consultancy.md.
    Route::get('/consultations/services/{code}', [ConsultationController::class, 'serviceDetail']);
    Route::get('/consultations/services/{code}/slots', [ConsultationController::class, 'serviceSlots']);
    Route::get('/consultations/services/{code}/availability', [ConsultationController::class, 'serviceAvailability']);
    Route::post('/consultations/services/{code}/reservations', [ConsultationReservationController::class, 'store']);
    Route::get('/consultations/reservations/{token}', [ConsultationReservationController::class, 'show']);
    Route::post('/consultations/reservations/{token}/cancel', [ConsultationReservationController::class, 'cancel']);
    Route::post('/consultations/reservations/{token}/checkout', [ConsultationReservationController::class, 'checkout']);
    Route::get('/consultations/reservations/{token}/payment', [ConsultationReservationController::class, 'paymentStatus']);
    Route::get('/consultations', [ConsultationController::class, 'index']);
    Route::post('/consultations', [ConsultationController::class, 'store']);
    Route::get('/consultations/{appointment}', [ConsultationController::class, 'show']);
    Route::post('/consultations/{appointment}/cancel', [ConsultationController::class, 'cancel']);

    // Cross-project reports
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/commercial-summary', [ReportController::class, 'commercialSummary']);
    Route::get('/reports/commercial-summary-report', [ReportController::class, 'commercialSummaryReport']);
    Route::get('/reports/commercial-summary-report/export/pdf', [ReportController::class, 'exportCommercialSummaryPdf']);
    Route::get('/reports/commercial-summary-report/export/excel', [ReportController::class, 'exportCommercialSummaryExcel']);

    // Global Commercial — organisation-wide commercial monitoring/triage (read-only)
    Route::get('/commercial/overview', [CommercialOverviewController::class, 'overview']);

    // Billing — organisation-facing Subscription & Billing data (read-only —
    // Stripe Test Mode Integration checkpoint, Slice A). Checkout, Portal,
    // upgrade/downgrade, and cancellation endpoints are deliberately not
    // exposed yet — see BillingController's docblock.
    Route::prefix('billing')->group(function () {
        Route::get('/overview', [BillingController::class, 'overview']);
        Route::get('/subscription', [BillingController::class, 'subscription']);
        Route::get('/plans', [BillingController::class, 'plans']);
        Route::get('/pending-plan-change', [BillingController::class, 'pendingPlanChange']);
        Route::get('/invoices', [BillingController::class, 'invoices']);
        Route::get('/invoices/{invoice}', [BillingController::class, 'invoice']);
        Route::get('/payments', [BillingController::class, 'payments']);

        // Phase G3 — Subscription Intelligence Centre. Read-only, single
        // composed payload; see SubscriptionIntelligenceService.
        Route::get('/intelligence', [SubscriptionIntelligenceController::class, 'index']);

        // Phase G4C.3E — customer-facing "Monthly AI Usage" meter. Same
        // tenancy contract as /intelligence above (org derived only from
        // the authenticated user). See AiCreditUsageService.
        Route::get('/ai-credit-usage', [AiCreditUsageController::class, 'index']);

        // Every mutating Billing endpoint below actually creates/changes a
        // real Stripe-backed commercial relationship, so all of them are
        // additionally gated by 'billing.enabled' (App\Http\Middleware\
        // EnsureBillingIsEnabled) — see that class's docblock for why this
        // gate was needed at all. Read-only endpoints above are deliberately
        // left ungated.
        Route::middleware('billing.enabled')->group(function () {
            // First-subscription Checkout initiation only — the sole
            // mutating Billing endpoint this slice adds. Rate-limited
            // separately from the read endpoints since it triggers a real
            // outbound Stripe API call.
            Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1');

            // Phase E4 — explicit customer-initiated abandonment of an
            // unfinished Checkout attempt. Only valid while pending_payment;
            // see CheckoutController::cancelPending()'s docblock.
            Route::post('/checkout/cancel-pending', [CheckoutController::class, 'cancelPending'])->middleware('throttle:10,1');

            // Existing-subscription plan changes only — Enterprise/first-
            // subscription Checkout are deliberately out of scope here.
            Route::post('/plan-change', [PlanChangeController::class, 'store'])->middleware('throttle:10,1');
            Route::post('/plan-change/{planChange}/cancel', [PlanChangeController::class, 'cancel'])->middleware('throttle:10,1');

            // First-party subscription cancellation — SureSign owns this
            // workflow entirely; Stripe Customer Portal cancellation is never
            // exposed (see SubscriptionCancellationController's docblock).
            Route::post('/subscription/cancel', [SubscriptionCancellationController::class, 'cancel'])->middleware('throttle:10,1');
            Route::post('/subscription/resume', [SubscriptionCancellationController::class, 'resume'])->middleware('throttle:10,1');

            // Restricted Stripe Customer Portal (Slice E2) — payment methods,
            // billing details, and invoice history only. Plan changes and
            // cancellation stay on SureSign's own endpoints above; the Portal
            // configuration this creates/reuses has both of those capabilities
            // disabled (see BillingPortalService). Empty body only.
            Route::post('/portal', [BillingPortalController::class, 'store'])->middleware('throttle:10,1');
        });
    });

    // Site Admin — organisation-wide RFI/Site Instruction/Site Diary/Meeting/EOT monitoring (read-only)
    Route::get('/site-administration/overview', [SiteAdministrationController::class, 'overview']);

    // Site settings (public read — all authenticated users)
    Route::get('/settings', [SuresignSettingController::class, 'publicShow']);

    // Projects
    // Registered before the apiResource below so this literal path is
    // matched first, not swallowed by the {project} wildcard binding.
    Route::get('/projects/portfolio', [ProjectPortfolioController::class, 'index']);
    Route::apiResource('projects', ProjectController::class);

    // Contracts (nested under projects)
    Route::apiResource('projects.contracts', ContractController::class)->shallow();

    // Commercial
    Route::apiResource('contracts.payment-applications', PaymentApplicationController::class)->shallow();
    Route::apiResource('contracts.variations', VariationController::class)->shallow();
    Route::post('variations/{variation}/generate-pdf', [VariationController::class, 'generatePdf']);
    // Variation approval state machine
    Route::post('variations/{variation}/submit',    [VariationController::class, 'submit']);
    Route::post('variations/{variation}/instruct',  [VariationController::class, 'instruct']);
    Route::post('variations/{variation}/quote',     [VariationController::class, 'quote']);
    Route::post('variations/{variation}/assess',    [VariationController::class, 'assess']);
    Route::post('variations/{variation}/approve',   [VariationController::class, 'approve']);
    Route::post('variations/{variation}/reject',    [VariationController::class, 'reject']);
    Route::post('variations/{variation}/resubmit',  [VariationController::class, 'resubmit']);

    // Final Account (contract-scoped)
    Route::get('contracts/{contract}/final-account',  [FinalAccountController::class, 'showForContract']);
    Route::post('contracts/{contract}/final-account', [FinalAccountController::class, 'storeForContract']);

    // Final Account (trade-package-scoped) — uses project-scoped prefix defined below
    // POST /projects/{project}/trade-packages/{tradePackage}/final-account
    // GET  /projects/{project}/trade-packages/{tradePackage}/final-account

    // Programme milestones
    Route::get('contracts/{contract}/programme', [ProgrammeMilestoneController::class, 'index']);
    Route::post('contracts/{contract}/programme', [ProgrammeMilestoneController::class, 'store']);
    Route::post('contracts/{contract}/programme/seed-from-analysis', [ProgrammeMilestoneController::class, 'seedFromAnalysis']);
    Route::put('programme/{milestone}', [ProgrammeMilestoneController::class, 'update']);
    Route::delete('programme/{milestone}', [ProgrammeMilestoneController::class, 'destroy']);

    // Payment application workflow actions
    Route::post('/payment-applications/{paymentApplication}/submit',              [PaymentApplicationController::class, 'submit']);
    Route::post('/payment-applications/{paymentApplication}/certify',             [PaymentApplicationController::class, 'certify']);
    Route::post('/payment-applications/{paymentApplication}/mark-paid',           [PaymentApplicationController::class, 'markPaid']);
    Route::post('/payment-applications/{paymentApplication}/cancel',              [PaymentApplicationController::class, 'cancel']);
    Route::post('/payment-applications/{paymentApplication}/withdraw',            [PaymentApplicationController::class, 'withdraw']);
    Route::post('/payment-applications/{paymentApplication}/generate-pdf',        [PaymentApplicationController::class, 'generatePdf']);
    Route::post('/payment-applications/{paymentApplication}/generate-certificate',[PaymentApplicationController::class, 'generateCertificate']);
    Route::post('/payment-applications/{paymentApplication}/pay-less-notice',     [PaymentApplicationController::class, 'createPayLessNotice']);
    Route::post('/payment-applications/{paymentApplication}/payment-notice',      [PaymentApplicationController::class, 'createPaymentNotice']);
    Route::get('/payment-applications/{paymentApplication}/previous-values',       [PaymentApplicationController::class, 'previousValues']);
    Route::post('/payment-applications/{paymentApplication}/breakdown',            [PaymentApplicationController::class, 'updateBreakdown']);
    Route::post('/payment-applications/{paymentApplication}/generate-excel',       [PaymentApplicationController::class, 'generateExcel']);
    Route::get('/payment-applications/{paymentApplication}/eligible-variations',   [PaymentApplicationController::class, 'eligibleVariations']);
    Route::post('/payment-applications/{paymentApplication}/sync-variations',      [PaymentApplicationController::class, 'syncLinkedVariations']);
    Route::delete('/payment-applications/{paymentApplication}',                   [PaymentApplicationController::class, 'destroy']);

    // Payment notices (standalone)
    Route::get('/payment-notices/{paymentNotice}', [PaymentNoticeController::class, 'show']);
    Route::delete('/payment-notices/{paymentNotice}', [PaymentNoticeController::class, 'destroy']);

    // Retention releases (standalone)
    Route::delete('/retention-releases/{retentionRelease}', [RetentionReleaseController::class, 'destroy']);

    // Final Accounts
    Route::get('/final-accounts/{finalAccount}',                    [FinalAccountController::class, 'show']);
    Route::put('/final-accounts/{finalAccount}',                    [FinalAccountController::class, 'update']);
    Route::get('/final-accounts/{finalAccount}/totals',             [FinalAccountController::class, 'totals']);
    Route::post('/final-accounts/{finalAccount}/submit',            [FinalAccountController::class, 'submit']);
    Route::post('/final-accounts/{finalAccount}/start-review',      [FinalAccountController::class, 'startReview']);
    Route::post('/final-accounts/{finalAccount}/revise',            [FinalAccountController::class, 'revise']);
    Route::post('/final-accounts/{finalAccount}/agree',             [FinalAccountController::class, 'agree']);
    Route::post('/final-accounts/{finalAccount}/sign',              [FinalAccountController::class, 'sign']);
    Route::post('/final-accounts/{finalAccount}/issue-certificate', [FinalAccountController::class, 'issueFinalCertificate']);
    Route::post('/final-accounts/{finalAccount}/close',             [FinalAccountController::class, 'close']);
    Route::post('/final-accounts/{finalAccount}/generate-statement',   [FinalAccountController::class, 'generateStatement']);
    Route::post('/final-accounts/{finalAccount}/generate-certificate', [FinalAccountController::class, 'generateCertificate']);
    Route::post('/final-accounts/{finalAccount}/items',             [FinalAccountController::class, 'storeItem']);
    Route::put('/final-accounts/{finalAccount}/items/{item}',       [FinalAccountController::class, 'updateItem']);
    Route::delete('/final-accounts/{finalAccount}/items/{item}',    [FinalAccountController::class, 'destroyItem']);

    // Site Administration
    Route::apiResource('projects.rfis', RfiController::class)->shallow();
    // Evidence attachments (Phase 0) — flat/shallow, matching show/update/destroy above.
    Route::get('/rfis/{rfi}/attachments',    [RfiController::class, 'attachments']);
    Route::post('/rfis/{rfi}/attachments',   [RfiController::class, 'uploadAttachment']);
    Route::delete('/rfis/{rfi}/attachments/{fileUpload}', [RfiController::class, 'deleteAttachment']);

    // Project sub-resources
    Route::prefix('projects/{project}')->group(function () {
        Route::get('/stats',                [ProjectController::class, 'stats']);
        Route::get('/dashboard-intelligence', [ProjectController::class, 'dashboardIntelligence']);
        Route::get('/activities', [ProjectActivityController::class, 'index']);
        Route::get('/payment-applications', [PaymentApplicationController::class, 'indexByProject']);
        Route::get('/payment-application-defaults', [PaymentApplicationController::class, 'applicationDefaults']);
        Route::post('/trade-packages/{tradePackage}/payment-applications', [PaymentApplicationController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/final-account',  [FinalAccountController::class, 'showForTradePackage']);
        Route::post('/trade-packages/{tradePackage}/final-account', [FinalAccountController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/programme',  [ProgrammeMilestoneController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/programme', [ProgrammeMilestoneController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/delay-events',  [DelayEventController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/delay-events', [DelayEventController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/eot-requests',  [EotRequestController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/eot-requests', [EotRequestController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/loss-and-expense-claims',  [LossAndExpenseClaimController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/loss-and-expense-claims', [LossAndExpenseClaimController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/risks',  [RiskController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/risks', [RiskController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/delivery-documents',  [DeliveryDocumentController::class, 'indexByTradePackage']);
        Route::post('/trade-packages/{tradePackage}/delivery-documents', [DeliveryDocumentController::class, 'storeForTradePackage']);
        Route::get('/trade-packages/{tradePackage}/delivery-documents/available-documents', [DeliveryDocumentController::class, 'availableDocuments']);
        // Trade package (subcontract) workspace + tenant-scoped update
        Route::get('/trade-packages/{tradePackage}/workspace', [TradePackageController::class, 'workspace']);
        Route::get('/trade-packages/{tradePackage}/activities', [TradePackageController::class, 'activities']);
        Route::put('/trade-packages/{tradePackage}', [TradePackageController::class, 'updateForProject']);
        Route::get('/payment-notices', [PaymentNoticeController::class, 'index']);
        Route::get('/retention-releases', [RetentionReleaseController::class, 'index']);
        Route::post('/retention-releases', [RetentionReleaseController::class, 'store']);
        Route::get('/variations', [VariationController::class, 'indexByProject']);
        Route::get('/final-accounts', [FinalAccountController::class, 'indexByProject']);
            Route::get('/programme', [ProgrammeMilestoneController::class, 'indexByProject']);
        Route::apiResource('site-diaries', SiteDiaryController::class)->shallow();
        Route::apiResource('meetings', MeetingMinutesController::class)->shallow();
        Route::apiResource('eot-requests', EotRequestController::class)->shallow();
        Route::post('/eot-requests/{eotRequest}/decide', [EotRequestController::class, 'decide']);
        Route::post('/eot-requests/{eotRequest}/generate-decision-notice', [EotRequestController::class, 'generateDecisionNotice']);
        Route::apiResource('delay-events', DelayEventController::class)->shallow();
        Route::post('/delay-events/{delayEvent}/generate-notice', [DelayEventController::class, 'generateNotice']);
        Route::apiResource('loss-and-expense-claims', LossAndExpenseClaimController::class)->shallow();
        Route::post('/loss-and-expense-claims/{lossAndExpenseClaim}/decide', [LossAndExpenseClaimController::class, 'decide']);
        Route::get('/risks', [RiskController::class, 'indexForProject']);
        Route::post('/risks', [RiskController::class, 'storeForProject']);
        Route::put('/risks/{risk}', [RiskController::class, 'update']);
        Route::delete('/risks/{risk}', [RiskController::class, 'destroy']);
        Route::get('/delivery-documents', [DeliveryDocumentController::class, 'indexForProject']);
        Route::post('/delivery-documents', [DeliveryDocumentController::class, 'storeForProject']);
        Route::put('/delivery-documents/{deliveryDocument}', [DeliveryDocumentController::class, 'update']);
        Route::delete('/delivery-documents/{deliveryDocument}', [DeliveryDocumentController::class, 'destroy']);

        // Drawing Register — eligible-documents lookup registered before the
        // apiResource below so this literal path is matched first, not
        // swallowed by the {drawing} wildcard binding (same precedent as
        // Global Documents above).
        Route::get('/drawings/eligible-documents', [DrawingController::class, 'eligibleDocuments']);
        Route::apiResource('drawings', DrawingController::class)->shallow();

        // Drawing Revision history (Phase 4) — explicit nested routes,
        // matching this codebase's existing convention for sub-resources
        // (e.g. adjudication-deadlines) rather than a chained apiResource.
        Route::get('/drawings/{drawing}/eligible-revision-documents', [DrawingController::class, 'eligibleRevisionDocuments']);
        Route::get('/drawings/{drawing}/revisions', [DrawingRevisionController::class, 'index']);
        Route::post('/drawings/{drawing}/revisions', [DrawingRevisionController::class, 'store']);
        Route::get('/drawings/{drawing}/revisions/{revision}', [DrawingRevisionController::class, 'show']);
        Route::put('/drawings/{drawing}/revisions/{revision}', [DrawingRevisionController::class, 'update']);

        // Drawing Hotspot authoring (Phase 6A) — current-revision-only, see
        // DrawingHotspotController's own docblock. index() remains available
        // for historical revisions (read-only).
        Route::get('/drawings/{drawing}/revisions/{revision}/hotspots', [DrawingHotspotController::class, 'index']);
        Route::post('/drawings/{drawing}/revisions/{revision}/hotspots', [DrawingHotspotController::class, 'store']);
        Route::put('/drawings/{drawing}/revisions/{revision}/hotspots/{hotspot}', [DrawingHotspotController::class, 'update']);
        Route::delete('/drawings/{drawing}/revisions/{revision}/hotspots/{hotspot}', [DrawingHotspotController::class, 'destroy']);

        // Drawing Hotspot <-> construction record linking (Phase 6B).
        Route::get('/drawings/{drawing}/revisions/{revision}/hotspots/{hotspot}/links', [DrawingHotspotLinkController::class, 'index']);
        Route::post('/drawings/{drawing}/revisions/{revision}/hotspots/{hotspot}/links', [DrawingHotspotLinkController::class, 'store']);
        Route::delete('/drawings/{drawing}/revisions/{revision}/hotspots/{hotspot}/links/{link}', [DrawingHotspotLinkController::class, 'destroy']);

        // Record-centric drawing-link endpoints (Phase 6B Part U/Y) —
        // registered before the eligible-documents-style literal routes
        // above aren't relevant here (no {drawing} wildcard collision).
        Route::get('/drawing-linkable-records', [DrawingRecordLinksController::class, 'linkableRecords']);
        Route::get('/drawing-locations', [DrawingRecordLinksController::class, 'forRecord']);

        Route::apiResource('pay-less-notices', PayLessNoticeController::class)->shallow();

        Route::apiResource('site-instructions', SiteInstructionController::class)->shallow();

        // Snagging
        Route::apiResource('snagging', SnagController::class)->shallow();
        // Evidence attachments (Phase 0)
        Route::get('/snagging/{snagging}/attachments',    [SnagController::class, 'attachments']);
        Route::post('/snagging/{snagging}/attachments',   [SnagController::class, 'uploadAttachment']);
        Route::delete('/snagging/{snagging}/attachments/{fileUpload}', [SnagController::class, 'deleteAttachment']);

        // QA Reports
        Route::apiResource('qa-reports', QaReportController::class)->shallow();
        // Evidence attachments (Phase 0)
        Route::get('/qa-reports/{qaReport}/attachments',    [QaReportController::class, 'attachments']);
        Route::post('/qa-reports/{qaReport}/attachments',   [QaReportController::class, 'uploadAttachment']);
        Route::delete('/qa-reports/{qaReport}/attachments/{fileUpload}', [QaReportController::class, 'deleteAttachment']);

        // Closeout
        Route::get('/closeout',              [CloseoutController::class, 'show']);
        Route::put('/closeout',              [CloseoutController::class, 'update']);
        Route::post('/closeout/items',       [CloseoutController::class, 'addItem']);
        Route::put('/closeout/items/{item}', [CloseoutController::class, 'updateItem']);

        // Adjudication
        Route::get('/adjudication-cases',                                        [AdjudicationCaseController::class, 'index']);
        Route::post('/adjudication-cases',                                       [AdjudicationCaseController::class, 'store']);
        Route::get('/adjudication-cases/{adjudicationCase}',                     [AdjudicationCaseController::class, 'show']);
        Route::put('/adjudication-cases/{adjudicationCase}',                     [AdjudicationCaseController::class, 'update']);
        Route::delete('/adjudication-cases/{adjudicationCase}',                  [AdjudicationCaseController::class, 'destroy']);
        Route::post('/adjudication-cases/{adjudicationCase}/advance-step',       [AdjudicationCaseController::class, 'advanceStep']);
        Route::post('/adjudication-cases/{adjudicationCase}/update-status',      [AdjudicationCaseController::class, 'updateStatus']);
        Route::post('/adjudication-cases/{adjudicationCase}/archive',            [AdjudicationCaseController::class, 'archive']);
        Route::get('/adjudication-cases/{adjudicationCase}/documents',           [AdjudicationDocumentController::class, 'index']);
        Route::post('/adjudication-cases/{adjudicationCase}/documents',          [AdjudicationDocumentController::class, 'store']);
        Route::delete('/adjudication-documents/{adjudicationDocument}',          [AdjudicationDocumentController::class, 'destroy']);
        Route::get('/adjudication-cases/{adjudicationCase}/deadlines',           [AdjudicationDeadlineController::class, 'index']);
        Route::post('/adjudication-cases/{adjudicationCase}/deadlines',          [AdjudicationDeadlineController::class, 'store']);
        Route::put('/adjudication-deadlines/{adjudicationDeadline}',             [AdjudicationDeadlineController::class, 'update']);
        Route::post('/adjudication-deadlines/{adjudicationDeadline}/complete',   [AdjudicationDeadlineController::class, 'markComplete']);
        Route::delete('/adjudication-deadlines/{adjudicationDeadline}',          [AdjudicationDeadlineController::class, 'destroy']);
    });

    // Global Documents — organisation-wide document search (read-only)
    // Registered before the apiResource below so this literal path is
    // matched first, not swallowed by the {document} wildcard binding.
    Route::get('/documents/portfolio', [OrganisationDocumentController::class, 'index']);

    // Documents (generated)
    Route::apiResource('projects.documents', DocumentController::class)->shallow();
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('/documents/{document}/preview',  [DocumentController::class, 'previewDocument']);

    // Clients (Companies)
    Route::apiResource('clients', ClientController::class);
    Route::get('/clients/{client}/projects', [ClientController::class, 'projects']);

    // Project file management (folder-based uploads)
    Route::get('/projects/{project}/folders', [ProjectController::class, 'folders']);
    Route::get('/projects/{project}/files', [DocumentController::class, 'indexFiles']);
    Route::post('/projects/{project}/files', [DocumentController::class, 'uploadFile']);
    Route::get('/file-uploads/{fileUpload}/download', [DocumentController::class, 'downloadFile']);
    Route::get('/file-uploads/{fileUpload}/preview',  [DocumentController::class, 'previewFile']);
    Route::delete('/file-uploads/{fileUpload}', [DocumentController::class, 'destroyFile']);

    // Project document module explorer
    Route::get('/projects/{project}/documents/explorer', [DocumentController::class, 'projectExplorer']);
    Route::get('/projects/{project}/documents/module/{moduleKey}', [DocumentController::class, 'projectModuleFiles'])->where('moduleKey', '.+');
    Route::get('/templates', [DocumentTemplateController::class, 'index']);
    Route::post('/trade-packages/{tradePackage}/generate-package', [TradePackagePackageGenerationController::class, 'generate']);
    Route::post('/projects/{project}/subcontracts/generate-trade-packages', [GenerateTradePackageFoldersController::class, 'store']);
    Route::get('/trade-packages/catalogue', [TradePackageCatalogueController::class, 'index']);
    Route::post('/trade-packages/{tradePackage}/upload', [TradePackageController::class, 'uploadFile']);

    // Trade Package AI onboarding (Sprint 6B Stage 1)
    Route::post('/trade-packages/{tradePackage}/ai-analysis',  [TradePackageAiController::class, 'startAnalysis'])->middleware('throttle:ai-analysis');
    Route::get('/trade-packages/{tradePackage}/ai-analysis',   [TradePackageAiController::class, 'getLatestAnalysis']);
    Route::get('/trade-packages/{tradePackage}/ai-analyses',   [TradePackageAiController::class, 'listAnalyses']);
    Route::get('/trade-package-ai-analyses/{analysis}',        [TradePackageAiController::class, 'showAnalysis']);
    Route::post('/trade-package-ai-analyses/{analysis}/reparse', [TradePackageAiController::class, 'reparseAnalysis']);
    Route::post('/trade-package-ai-analyses/{analysis}/confirm', [TradePackageAiController::class, 'confirmAnalysis']);
    Route::post('/trade-package-ai-analyses/{analysis}/cancel',  [TradePackageAiController::class, 'cancelAnalysis']);

    // Document Register & Number types
    Route::get('/document-types', [DocumentRegisterController::class, 'types']);
    Route::get('/projects/{project}/document-register', [DocumentRegisterController::class, 'index'])
         ->where('project', '[0-9]+');

    // AI
    Route::prefix('ai')->group(function () {
        Route::post('/conversations', [AiController::class, 'startConversation']);
        Route::get('/conversations', [AiController::class, 'listConversations']);
        Route::post('/conversations/{conversation}/messages', [AiController::class, 'sendMessage']);
        Route::get('/conversations/{conversation}/messages', [AiController::class, 'getMessages']);
        Route::post('/summarize', [AiController::class, 'summarize']);
        Route::post('/draft-document', [AiController::class, 'draftDocument']);

        // Contract AI analysis
        Route::get('/status', [AiController::class, 'status']);
        Route::get('/analyses/{analysis}', [AiController::class, 'showAnalysis']);
        Route::post('/analyses/{analysis}/confirm', [AiController::class, 'confirmAnalysis']);
        Route::post('/analyses/{analysis}/cancel',          [AiController::class, 'cancelAnalysis']);
        Route::post('/analyses/{analysis}/reparse',         [AiController::class, 'reparseAnalysis']);
        Route::post('/analyses/{analysis}/generate-brief',  [AiController::class, 'generateBrief']);
    });

    // Contract AI analysis (contract-scoped)
    Route::post('/contracts/{contract}/ai-analysis',   [AiController::class, 'startAnalysis'])->middleware('throttle:ai-analysis');
    Route::get('/contracts/{contract}/ai-analysis',    [AiController::class, 'getLatestAnalysis']);
    Route::get('/contracts/{contract}/ai-analyses',    [AiController::class, 'listAnalyses']);
    Route::get('/projects/{project}/ai-analyses',      [AiController::class, 'listForProject']);
    Route::get('/projects/{project}/calendar-events',  [CalendarController::class, 'events']);
    Route::post('/contracts/{contract}/attach-file',   [ContractController::class, 'attachFile']);
    Route::post('/contracts/{contract}/archive',       [ContractController::class, 'archive']);
    Route::post('/contracts/{contract}/restore',       [ContractController::class, 'restore']);

    // Organization & Branding
    Route::post('/organization/onboard',             [OrganizationController::class, 'onboard']);
    Route::post('/organization/onboard/profile',     [OrganizationController::class, 'onboardProfile']);
    Route::post('/organization/onboard/company',     [OrganizationController::class, 'onboardCompany']);
    Route::post('/organization/onboard/finalize',    [OrganizationController::class, 'onboardFinalize']);
    Route::get('/organization',                      [OrganizationController::class, 'show']);
    Route::put('/organization',                      [OrganizationController::class, 'update']);
    Route::get('/organization/branding',             [OrganizationController::class, 'getBranding']);
    Route::post('/organization/branding',            [OrganizationController::class, 'updateBranding']);
    Route::put('/organization/branding',             [OrganizationController::class, 'updateBranding']);

    // Organisation URL Branding — customer self-service Custom URL
    // section (Company Branding). Entitlement (Feature::CUSTOM_BRANDED_SUBDOMAIN)
    // and "no org for Super Admin/Admin" are enforced inside the
    // controller itself, matching the branding routes' own precedent.
    Route::get('/organization/url-slug',    [OrganizationBrandingUrlController::class, 'show']);
    Route::put('/organization/url-slug',    [OrganizationBrandingUrlController::class, 'update']);
    Route::delete('/organization/url-slug', [OrganizationBrandingUrlController::class, 'destroy']);
    Route::post('/organization/logo',                [OrganizationController::class, 'uploadLogo']);
    Route::post('/organization/cover',               [OrganizationController::class, 'uploadCover']);
    Route::post('/organization/letterhead-header',   [OrganizationController::class, 'uploadLetterheadHeader']);
    Route::post('/organization/letterhead-footer',   [OrganizationController::class, 'uploadLetterheadFooter']);

    // User management — Super Admin only. Tightened from the previous
    // 'Super Admin|Admin' gating: verification, bans, password/token
    // controls and role changes are sensitive enough that regular Admins
    // should not be able to call them (or see them rendered client-side).
    // Throttled on top of the normal auth:sanctum rate limit — a compromised
    // or careless Super Admin session shouldn't be able to mass-ban/mass-reset
    // faster than a human clicking through the Users page ever would.
    Route::middleware(['role:Super Admin', 'throttle:30,1'])->group(function () {
        Route::post('users/invite', [UserController::class, 'invite']);
        Route::apiResource('users', UserController::class)->except(['store']);
        Route::post('users/{id}/verify-email',         [UserController::class, 'verifyEmail']);
        Route::post('users/{id}/unverify-email',       [UserController::class, 'unverifyEmail']);
        Route::post('users/{id}/ban',                  [UserController::class, 'ban']);
        Route::post('users/{id}/unban',                [UserController::class, 'unban']);
        Route::post('users/{id}/force-password-reset', [UserController::class, 'forcePasswordReset']);
        Route::post('users/{id}/set-password',         [UserController::class, 'setPassword']);
        Route::post('users/{id}/revoke-tokens',        [UserController::class, 'revokeTokens']);
        Route::post('users/{id}/reset-tours',          [UserController::class, 'resetTours']);
        // G4A — read-only inherited organisation subscription detail (see
        // internal-docs/super-admin/subscription-billing.md).
        Route::get('users/{id}/subscription',          [UserController::class, 'subscription']);

        // Application Monitoring — cross-organization presence/usage/operational
        // data. Super Admin only; deliberately not in the 'Super Admin|Admin'
        // group below (see internal-docs/super-admin/application-monitoring.md).
        Route::get('/admin/application-monitoring', [ApplicationMonitoringController::class, 'index']);

    });

    // Pricing Management — controls the public marketing Pricing page and
    // (since Phase G2) Subscription Plan entitlement defaults. Widened from
    // Super-Admin-only to 'Super Admin|Admin' per the approved Phase G0
    // decision: both are platform-wide roles (organization_id = null) in
    // this app's role model, so this carries no customer-org exposure risk
    // (see internal-docs/super-admin/pricing-management.md and
    // internal-docs/super-admin/subscription-billing.md's Phase G0/G2
    // sections).
    Route::middleware('role:Super Admin|Admin')->prefix('admin/pricing')->group(function () {
        Route::get('/settings', [PricingController::class, 'showSettings']);
        Route::put('/settings', [PricingController::class, 'updateSettings']);

        Route::get('/plans',                 [PricingController::class, 'indexPlans']);
        Route::post('/plans',                [PricingController::class, 'storePlan']);
        Route::put('/plans/reorder',         [PricingController::class, 'reorderPlans']);
        Route::put('/plans/{plan}',          [PricingController::class, 'updatePlan']);
        Route::post('/plans/{plan}/publish', [PricingController::class, 'publishPlan']);
        Route::post('/plans/{plan}/archive', [PricingController::class, 'archivePlan']);
        Route::delete('/plans/{plan}',       [PricingController::class, 'destroyPlan']);
        Route::post('/plans/{plan}/copy',    [PricingController::class, 'copyPlan']);

        // Phase G2 — Subscription Plan entitlement defaults editor. Reads/writes
        // pricing_plan_entitlements only; never touches FeatureGate, snapshots,
        // or Stripe. Editing here only affects future entitlement snapshots.
        Route::get('/plans/{plan}/entitlements', [PricingController::class, 'showEntitlements']);
        Route::put('/plans/{plan}/entitlements', [PricingController::class, 'updateEntitlements']);

        Route::get('/feature-sections',                 [PricingController::class, 'indexFeatureSections']);
        Route::post('/feature-sections',                [PricingController::class, 'storeFeatureSection']);
        Route::put('/feature-sections/reorder',         [PricingController::class, 'reorderFeatureSections']);
        Route::put('/feature-sections/{section}',       [PricingController::class, 'updateFeatureSection']);
        Route::delete('/feature-sections/{section}',    [PricingController::class, 'destroyFeatureSection']);

        Route::get('/features',              [PricingController::class, 'indexFeatures']);
        Route::post('/features',             [PricingController::class, 'storeFeature']);
        Route::put('/features/reorder',      [PricingController::class, 'reorderFeatures']);
        Route::put('/features/{feature}',    [PricingController::class, 'updateFeature']);
        Route::delete('/features/{feature}', [PricingController::class, 'destroyFeature']);

        Route::get('/matrix', [PricingController::class, 'indexMatrix']);
        Route::put('/matrix', [PricingController::class, 'updateMatrix']);

        Route::get('/faqs',              [PricingController::class, 'indexFaqs']);
        Route::post('/faqs',             [PricingController::class, 'storeFaq']);
        Route::put('/faqs/reorder',      [PricingController::class, 'reorderFaqs']);
        Route::put('/faqs/{faq}',        [PricingController::class, 'updateFaq']);
        Route::delete('/faqs/{faq}',     [PricingController::class, 'destroyFaq']);

        Route::get('/included-items',              [PricingController::class, 'indexIncludedItems']);
        Route::post('/included-items',             [PricingController::class, 'storeIncludedItem']);
        Route::put('/included-items/reorder',      [PricingController::class, 'reorderIncludedItems']);
        Route::put('/included-items/{item}',       [PricingController::class, 'updateIncludedItem']);
        Route::delete('/included-items/{item}',    [PricingController::class, 'destroyIncludedItem']);
    });

    // Internal AI execution / non-enforcing AI Credit simulation reporting
    // (Phase G4C.2C-2). 'Super Admin|Admin' — matches the Pricing
    // Management precedent above, since both roles are platform-wide (not
    // customer-org scoped) in this codebase's role model. Every response
    // is built exclusively from AiAnalysisPresenter's internal*() methods —
    // see AiTelemetryReportingController's own docblock. Read-only.
    Route::middleware('role:Super Admin|Admin')->prefix('admin/ai-telemetry')->group(function () {
        Route::get('/summary', [AiTelemetryReportingController::class, 'summary']);
        Route::get('/detail',  [AiTelemetryReportingController::class, 'detail']);
        Route::get('/export',  [AiTelemetryReportingController::class, 'export']);
        // Phase G4C.2D — telemetry maturity health checks (read-only).
        Route::get('/health',  [AiTelemetryReportingController::class, 'health']);
    });

    // Phase G4C.3D-1 — AI Credits Operations Dashboard (read-only). Same
    // 'Super Admin|Admin' gate as ai-telemetry above, for the same reason
    // (both roles are platform-wide, not customer-org scoped).
    Route::middleware('role:Super Admin|Admin')->prefix('admin/ai-credits')->group(function () {
        Route::get('/summary', [AiCreditsOperationsController::class, 'summary']);
        Route::get('/organizations', [AiCreditsOperationsController::class, 'organizations']);
        Route::get('/organizations/{id}', [AiCreditsOperationsController::class, 'organizationDetail']);
        Route::get('/transactions', [AiCreditsOperationsController::class, 'transactions']);
        Route::get('/shadow-activity', [AiCreditsOperationsController::class, 'shadowActivity']);
        Route::get('/operating-mode', [AiCreditsOperationsController::class, 'operatingModeSettings']);
    });

    // Phase G4C.3H — AI Credits grants/adjustments/expiry. Deliberately
    // 'role:Super Admin' ONLY (unlike the read-only group above) — Admin
    // must never reach these, mirroring the G4B.2 subscription-assignment
    // precedent exactly, including its throttle.
    Route::middleware(['role:Super Admin', 'throttle:30,1'])->prefix('admin/ai-credits')->group(function () {
        Route::post('/organizations/{organization}/grant', [AiCreditsGrantController::class, 'grant']);
        Route::post('/organizations/{organization}/adjust-credit', [AiCreditsGrantController::class, 'adjustCredit']);
        Route::post('/organizations/{organization}/adjust-debit', [AiCreditsGrantController::class, 'adjustDebit']);
        Route::post('/organizations/{organization}/expire', [AiCreditsGrantController::class, 'expire']);
        Route::put('/operating-mode', [AiCreditsGrantController::class, 'updateOperatingMode']);
    });

    // Google Integration Foundation (Stage 4A) — platform-level, not
    // Consultancy-specific. diagnostics() is read-only (Super Admin OR
    // Admin, matching the ai-telemetry/ai-credits read-only precedent);
    // every mutating/live-call action is Super Admin ONLY, matching the
    // ai-credits grant/adjust/expire precedent for high-consequence
    // platform actions. See GoogleIntegrationController's own docblock.
    Route::middleware('role:Super Admin|Admin')->prefix('admin/google')->group(function () {
        Route::get('/diagnostics', [GoogleIntegrationController::class, 'diagnostics']);
    });

    // Stage 4B.1 (Google Calendar Event Synchronisation) — Admin
    // diagnostics + authorised retry/reconcile. Grouped under the same
    // admin/google prefix as the Stage 4A diagnostics above. Read AND
    // retry/reconcile are both Super Admin OR Admin — a safe, idempotent,
    // non-destructive action, mirroring
    // ConsultancySettingsController::retryConversion()'s risk profile, not
    // the stricter Super-Admin-only gate reserved for OAuth connect/
    // disconnect above. See GoogleCalendarSyncController's own docblock.
    Route::middleware('role:Super Admin|Admin')->prefix('admin/google/calendar-syncs')->group(function () {
        Route::get('/', [GoogleCalendarSyncController::class, 'index']);
        Route::get('/{sync}', [GoogleCalendarSyncController::class, 'show']);
        Route::post('/{sync}/retry', [GoogleCalendarSyncController::class, 'retry']);
        Route::post('/{sync}/reconcile', [GoogleCalendarSyncController::class, 'reconcile']);
    });

    Route::middleware(['role:Super Admin', 'throttle:30,1'])->prefix('admin/google')->group(function () {
        Route::post('/oauth/connect',    [GoogleIntegrationController::class, 'connect']);
        Route::post('/oauth/callback',   [GoogleIntegrationController::class, 'callback']);
        Route::post('/disconnect',       [GoogleIntegrationController::class, 'disconnect']);
        Route::post('/test-connection',  [GoogleIntegrationController::class, 'testConnection']);
    });

    Route::middleware('role:Super Admin|Admin')->group(function () {
        Route::apiResource('organizations', OrganizationController::class)->except(['show', 'update']);

        // Consultancy Service catalogue (Phase C1) — Super Admin OR Admin,
        // matching the Pricing Management precedent (both platform-wide
        // roles), not the stricter Appointment-Type-only rule below.
        Route::apiResource('consultancy-services', ConsultancyServiceController::class);

        // Consultancy operator surface (Phase C2, Batch 3) — read-only:
        // queue + operator detail. Every Super Admin/Admin may read any
        // consultation platform-wide (confirmed, Consultancy-specific
        // visibility rule — see ConsultancyOperationsController's own
        // docblock); write actions arrive in Batch 4.
        Route::get('/admin/consultancy/consultations', [ConsultancyOperationsController::class, 'index']);
        Route::get('/admin/consultancy/consultations/{appointment}', [ConsultancyOperationsController::class, 'show']);

        // Operator dashboard (Phase C2, Batch 6A) — read-only, aggregate-only.
        Route::get('/admin/consultancy/dashboard', [ConsultancyOperationsController::class, 'dashboardSummary']);

        // Lightweight sidebar badge count — mirrors
        // SupportTicketController::counts()'s exact shape/purpose: a cheap,
        // dedicated endpoint safe to poll every page load, so the sidebar
        // doesn't pull in dashboardSummary()'s heavier ageing-bucket work
        // just to show a number.
        Route::get('/admin/consultancy/counts', [ConsultancyOperationsController::class, 'counts']);

        // Operational write actions (Phase C2, Batch 4) — one explicit-intent
        // route per business action, never a generic "set status" endpoint.
        // Write access is narrower than read (authorizeOperatorManage(),
        // re-checked independently inside each controller method) — Super
        // Admin or the specific assigned Admin only.
        Route::put('/admin/consultancy/consultations/{appointment}/notes', [ConsultancyOperationsController::class, 'updateNotes']);
        Route::put('/admin/consultancy/consultations/{appointment}/summary', [ConsultancyOperationsController::class, 'updateSummaryDraft']);
        Route::post('/admin/consultancy/consultations/{appointment}/summary/publish', [ConsultancyOperationsController::class, 'publishSummary']);
        Route::post('/admin/consultancy/consultations/{appointment}/status/awaiting-customer', [ConsultancyOperationsController::class, 'markAwaitingCustomer']);
        Route::post('/admin/consultancy/consultations/{appointment}/status/awaiting-consultant', [ConsultancyOperationsController::class, 'markAwaitingConsultant']);
        Route::post('/admin/consultancy/consultations/{appointment}/status/complete', [ConsultancyOperationsController::class, 'markCompleted']);
        Route::post('/admin/consultancy/consultations/{appointment}/reopen', [ConsultancyOperationsController::class, 'reopen']);

        // Project linkage (Phase C2, Batch 5) — link/change share one PUT
        // (see linkProject()'s own docblock for why); unlink is a separate
        // DELETE. Both gated by authorizeOperatorManage() inside the
        // controller, same as every other write action above.
        Route::put('/admin/consultancy/consultations/{appointment}/project', [ConsultancyOperationsController::class, 'linkProject']);
        Route::delete('/admin/consultancy/consultations/{appointment}/project', [ConsultancyOperationsController::class, 'unlinkProject']);

        // The Project-side read view — Consultancy-owned, read-only,
        // platform-wide (authorizeOperatorAccess(), not the narrower
        // authorizeOperatorManage()). Deliberately not touching
        // ProjectController::show() at all.
        Route::get('/admin/consultancy/projects/{project}/consultations', [ConsultancyOperationsController::class, 'projectConsultations']);

        // Consultancy Live Booking Upgrade, Stage 1 — consultant
        // configuration (read: Super Admin or Admin; write: Super Admin
        // only, enforced inside ConsultancySettingsController) and the
        // Stage 1 readiness check (no Stripe/Google — see that service's
        // own docblock).
        Route::get('/admin/consultancy/settings/consultant', [ConsultancySettingsController::class, 'show']);
        Route::put('/admin/consultancy/settings/consultant', [ConsultancySettingsController::class, 'update']);
        Route::get('/admin/consultancy/settings/eligible-consultants', [ConsultancySettingsController::class, 'eligibleCandidates']);
        Route::get('/admin/consultancy/settings/readiness', [ConsultancySettingsController::class, 'readiness']);
        Route::get('/admin/consultancy/settings/notifications', [ConsultancySettingsController::class, 'notificationSettings']);
        Route::put('/admin/consultancy/settings/notifications', [ConsultancySettingsController::class, 'updateNotificationSettings']);

        // Consultancy Live Booking Upgrade, Stage 2 — minimal Admin
        // reservation diagnostics (counts + bounded recent list) and a
        // safe operator cancellation action. No payment controls exist
        // yet — none are exposed here.
        Route::get('/admin/consultancy/reservations', [ConsultancySettingsController::class, 'reservations']);
        Route::post('/admin/consultancy/reservations/{reservation}/cancel', [ConsultancySettingsController::class, 'cancelReservation']);

        // Consultancy Live Booking Upgrade, Stage 3 — payment recovery
        // visibility (paid-awaiting-conversion / manual review) and a safe
        // conversion retry. No refund/payment-amendment action exists.
        Route::get('/admin/consultancy/payments', [ConsultancySettingsController::class, 'payments']);
        Route::post('/admin/consultancy/payments/{payment}/retry-conversion', [ConsultancySettingsController::class, 'retryConversion']);

        // Consultancy Live Booking Upgrade, Stage 1 — dedicated Consultancy
        // Availability admin surface. No {user}/me selection — always
        // operates on whichever consultant is currently configured (see
        // ConsultancyAvailabilityController's own docblock for why this
        // differs from AppointmentAvailabilityController's self/staff
        // selection model).
        Route::get('/admin/consultancy/availability',                              [ConsultancyAvailabilityController::class, 'showWeekly']);
        Route::put('/admin/consultancy/availability',                              [ConsultancyAvailabilityController::class, 'updateWeekly']);
        Route::get('/admin/consultancy/availability/overrides',                    [ConsultancyAvailabilityController::class, 'indexOverrides']);
        Route::post('/admin/consultancy/availability/overrides',                   [ConsultancyAvailabilityController::class, 'storeOverride']);
        Route::put('/admin/consultancy/availability/overrides/{override}',         [ConsultancyAvailabilityController::class, 'updateOverride']);
        Route::delete('/admin/consultancy/availability/overrides/{override}',      [ConsultancyAvailabilityController::class, 'destroyOverride']);
        Route::get('/admin/consultancy/availability/blocked-periods',                    [ConsultancyAvailabilityController::class, 'indexBlockedPeriods']);
        Route::post('/admin/consultancy/availability/blocked-periods',                   [ConsultancyAvailabilityController::class, 'storeBlockedPeriod']);
        Route::put('/admin/consultancy/availability/blocked-periods/{blockedPeriod}',    [ConsultancyAvailabilityController::class, 'updateBlockedPeriod']);
        Route::delete('/admin/consultancy/availability/blocked-periods/{blockedPeriod}', [ConsultancyAvailabilityController::class, 'destroyBlockedPeriod']);

        // Appointments & Scheduling — Phase 1 (Foundation). Index/show +
        // ordinary CRUD are open to both roles (finer-grained view/manage
        // rules are enforced inside AppointmentController); Appointment
        // Type mutation is Super-Admin-only (enforced inside
        // AppointmentTypeController) per the approved architecture.
        Route::apiResource('appointment-types', AppointmentTypeController::class);

        // Registered before the apiResource below so this literal path
        // ("check-availability") isn't swallowed by the {appointment}
        // route-model-binding wildcard.
        Route::post('/appointments/check-availability', [AppointmentController::class, 'checkAvailability']);
        Route::apiResource('appointments', AppointmentController::class);
        Route::post('/appointments/{appointment}/assign',    [AppointmentController::class, 'assign']);
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);
        Route::post('/appointments/{appointment}/confirm',   [AppointmentController::class, 'confirm']);
        Route::post('/appointments/{appointment}/decline',   [AppointmentController::class, 'decline']);
        Route::post('/appointments/{appointment}/cancel',    [AppointmentController::class, 'cancel']);
        Route::post('/appointments/{appointment}/complete',  [AppointmentController::class, 'complete']);
        Route::post('/appointments/{appointment}/no-show',   [AppointmentController::class, 'noShow']);

        // Appointment Availability — Phase 2. "/me" routes MUST be
        // registered before their "/{user}" counterparts, or the literal
        // segment "me" gets swallowed by the {user} route-model-binding
        // wildcard (the same collision risk as check-availability above).
        // Both variants share the same controller actions — $user is
        // simply null on the "/me" routes, and the controller resolves
        // that to the acting user itself.
        Route::get('/appointment-availability/me',                       [AppointmentAvailabilityController::class, 'showWeekly']);
        Route::put('/appointment-availability/me',                       [AppointmentAvailabilityController::class, 'updateWeekly']);
        Route::get('/appointment-availability/me/overrides',              [AppointmentAvailabilityController::class, 'indexOverrides']);
        Route::post('/appointment-availability/me/overrides',             [AppointmentAvailabilityController::class, 'storeOverride']);
        Route::put('/appointment-availability/me/overrides/{override}',   [AppointmentAvailabilityController::class, 'updateOverride']);
        Route::delete('/appointment-availability/me/overrides/{override}', [AppointmentAvailabilityController::class, 'destroyOverride']);
        Route::get('/appointment-availability/me/blocked-periods',                    [AppointmentAvailabilityController::class, 'indexBlockedPeriods']);
        Route::post('/appointment-availability/me/blocked-periods',                   [AppointmentAvailabilityController::class, 'storeBlockedPeriod']);
        Route::put('/appointment-availability/me/blocked-periods/{blockedPeriod}',    [AppointmentAvailabilityController::class, 'updateBlockedPeriod']);
        Route::delete('/appointment-availability/me/blocked-periods/{blockedPeriod}', [AppointmentAvailabilityController::class, 'destroyBlockedPeriod']);

        Route::get('/appointment-availability/{user}',                       [AppointmentAvailabilityController::class, 'showWeekly']);
        Route::put('/appointment-availability/{user}',                       [AppointmentAvailabilityController::class, 'updateWeekly']);
        Route::get('/appointment-availability/{user}/overrides',              [AppointmentAvailabilityController::class, 'indexOverrides']);
        Route::post('/appointment-availability/{user}/overrides',             [AppointmentAvailabilityController::class, 'storeOverride']);
        Route::put('/appointment-availability/{user}/overrides/{override}',   [AppointmentAvailabilityController::class, 'updateOverride']);
        Route::delete('/appointment-availability/{user}/overrides/{override}', [AppointmentAvailabilityController::class, 'destroyOverride']);
        Route::get('/appointment-availability/{user}/blocked-periods',                    [AppointmentAvailabilityController::class, 'indexBlockedPeriods']);
        Route::post('/appointment-availability/{user}/blocked-periods',                   [AppointmentAvailabilityController::class, 'storeBlockedPeriod']);
        Route::put('/appointment-availability/{user}/blocked-periods/{blockedPeriod}',    [AppointmentAvailabilityController::class, 'updateBlockedPeriod']);
        Route::delete('/appointment-availability/{user}/blocked-periods/{blockedPeriod}', [AppointmentAvailabilityController::class, 'destroyBlockedPeriod']);

        // Super Admin dashboard & management
        Route::prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/organizations', [AdminController::class, 'organizations']);
            Route::get('/projects', [AdminController::class, 'projects']);
            Route::get('/documents', [AdminController::class, 'documents']);
            Route::get('/storage', [AdminController::class, 'storage']);

            // Document Templates CRUD
            Route::apiResource('templates', DocumentTemplateController::class);
            Route::get('/templates/{template}/preview', [DocumentTemplateController::class, 'preview']);
            Route::get('/support-tickets', [SupportTicketController::class, 'index']);
            // Registered before the {supportTicket} wildcard below so this
            // literal path is matched first, not treated as a ticket id.
            Route::get('/support-tickets/counts', [SupportTicketController::class, 'counts']);
            Route::get('/support-tickets/{supportTicket}', [SupportTicketController::class, 'adminShow']);
            Route::put('/support-tickets/{id}', [SupportTicketController::class, 'updateStatus']);

            // Platform-wide emergency / known-issue banner management
            Route::apiResource('platform-announcements', PlatformAnnouncementController::class)->except(['show']);
            Route::get('/system-logs', [AdminController::class, 'systemLogs']);
            Route::get('/audit-log', [AdminController::class, 'auditLog']);
            Route::get('/settings', [AdminController::class, 'settings']);
            Route::put('/settings', [AdminController::class, 'updateSettings']);

            // Admin: create project on behalf of a company
            Route::post('/companies/{organization}/projects', [ProjectController::class, 'storeForCompany']);

            // Document Explorer
            Route::get('/documents/explorer', [AdminController::class, 'explorerCompanies']);
            Route::get('/documents/explorer/company/{organization}', [AdminController::class, 'explorerProjects']);
            Route::get('/documents/explorer/project/{project}', [AdminController::class, 'explorerModules']);
            Route::get('/documents/explorer/project/{project}/module/{moduleKey}', [AdminController::class, 'explorerModuleFiles'])->where('moduleKey', '.+');

            // SureSign platform settings
            Route::get('/suresign-settings',                         [SuresignSettingController::class, 'show']);
            Route::put('/suresign-settings',                         [SuresignSettingController::class, 'update']);
            Route::put('/suresign-settings/email',                   [SuresignSettingController::class, 'updateEmail']);
            Route::put('/suresign-settings/site',                    [SuresignSettingController::class, 'updateSite']);
            Route::post('/suresign-settings/logo',                   [SuresignSettingController::class, 'uploadLogo']);
            Route::post('/suresign-settings/favicon',                [SuresignSettingController::class, 'uploadFavicon']);
            Route::post('/suresign-settings/letterhead-header',      [SuresignSettingController::class, 'uploadLetterheadHeader']);
            Route::post('/suresign-settings/letterhead-footer',      [SuresignSettingController::class, 'uploadLetterheadFooter']);
            Route::post('/suresign-settings/letterhead-pdf',         [SuresignSettingController::class, 'uploadLetterheadPdf']);
            Route::post('/suresign-settings/email-header',           [SuresignSettingController::class, 'uploadEmailHeader']);
            Route::post('/suresign-settings/email-footer',           [SuresignSettingController::class, 'uploadEmailFooter']);
            Route::delete('/suresign-settings/logo',                 [SuresignSettingController::class, 'removeLogo']);
            Route::delete('/suresign-settings/favicon',              [SuresignSettingController::class, 'removeFavicon']);
            Route::delete('/suresign-settings/letterhead-header',    [SuresignSettingController::class, 'removeLetterheadHeader']);
            Route::delete('/suresign-settings/letterhead-footer',    [SuresignSettingController::class, 'removeLetterheadFooter']);
            Route::delete('/suresign-settings/letterhead-pdf',       [SuresignSettingController::class, 'removeLetterheadPdf']);
            Route::delete('/suresign-settings/email-header',         [SuresignSettingController::class, 'removeEmailHeader']);
            Route::delete('/suresign-settings/email-footer',         [SuresignSettingController::class, 'removeEmailFooter']);
            Route::post('/suresign-settings/test-pdf',               [SuresignSettingController::class, 'testPdf']);
            Route::post('/suresign-settings/test-email',             [SuresignSettingController::class, 'testEmail']);
            Route::put('/suresign-settings/ai',                      [SuresignSettingController::class, 'updateAi']);
            Route::put('/suresign-settings/notifications',           [SuresignSettingController::class, 'updateNotifications']);
            Route::put('/suresign-settings/appointments',            [SuresignSettingController::class, 'updateAppointments']);

            // Prompt Library
            Route::prefix('prompts')->group(function () {
                Route::get('/categories',              [PromptController::class, 'indexCategories']);
                Route::post('/categories',             [PromptController::class, 'storeCategory']);
                Route::put('/categories/{category}',   [PromptController::class, 'updateCategory']);
                Route::delete('/categories/{category}',[PromptController::class, 'destroyCategory']);

                Route::get('/templates',               [PromptController::class, 'indexTemplates']);
                Route::post('/templates',              [PromptController::class, 'storeTemplate']);
                Route::get('/templates/{template}',    [PromptController::class, 'showTemplate']);
                Route::put('/templates/{template}',    [PromptController::class, 'updateTemplate']);
                Route::delete('/templates/{template}', [PromptController::class, 'destroyTemplate']);

                Route::post('/templates/{template}/render',    [PromptController::class, 'render']);
                Route::post('/templates/{template}/copy',      [PromptController::class, 'copyTemplate']);
                Route::post('/templates/{template}/favorite',  [PromptController::class, 'favoriteTemplate']);
                Route::delete('/templates/{template}/favorite',[PromptController::class, 'unfavoriteTemplate']);
                Route::get('/favorites',                       [PromptController::class, 'myFavorites']);
            });

            Route::post('/trade-packages/{tradePackage}/generate-package', [TradePackagePackageGenerationController::class, 'generate']);

            // Bulk trade package folder generation
            Route::post('/projects/{project}/subcontracts/generate-trade-packages', [GenerateTradePackageFoldersController::class, 'store']);

            // Document Register (admin)
            Route::get('/document-register',          [DocumentRegisterController::class, 'adminIndex']);
            Route::get('/document-register/projects', [DocumentRegisterController::class, 'adminProjects']);

            // Companies House (UK) lookup
            Route::get('/companies-house/search', [CompaniesHouseController::class, 'search']);
            Route::get('/companies-house/{companyNumber}/officers', [CompaniesHouseController::class, 'officers']);
            Route::get('/companies-house/{companyNumber}', [CompaniesHouseController::class, 'show']);

        });

        // Show individual organization by ID (admin)
        Route::get('/organizations/{id}', [OrganizationController::class, 'showById']);
        // G4A — read-only Organisation Subscription Administration (see
        // internal-docs/super-admin/subscription-billing.md).
        Route::get('/organizations/{id}/subscription', [OrganizationController::class, 'subscription']);
    });

    // G4B.2 — Manual & Complimentary subscription assignment/termination.
    // Deliberately 'role:Super Admin' ONLY — Admin keeps read-only access
    // via the group above and must never reach these mutation endpoints
    // (see internal-docs/super-admin/subscription-billing.md's G4B.2
    // section). Throttled like the other Super-Admin-only mutation group
    // (Users) for the same reason: a careless/compromised session
    // shouldn't be able to mass-assign faster than a human clicking
    // through the admin UI ever would.
    Route::middleware(['role:Super Admin', 'throttle:30,1'])->group(function () {
        Route::post('/organizations/{organization}/subscriptions/assign-manual', [OrganizationSubscriptionAssignmentController::class, 'assignManual']);
        Route::post('/organizations/{organization}/subscriptions/assign-complimentary', [OrganizationSubscriptionAssignmentController::class, 'assignComplimentary']);
        Route::post('/organizations/{organization}/subscriptions/{subscription}/terminate', [OrganizationSubscriptionAssignmentController::class, 'terminate']);
    });

    // Organisation URL Branding, Phase 1 — Super Admin ONLY (mirrors the
    // manual/complimentary subscription group above): changing a customer's
    // public hostname is a deliberate production-infra action, not a routine
    // settings edit. Admin keeps read access via the organizations/{id} show
    // endpoint (url_slug is included in that payload).
    Route::middleware(['role:Super Admin', 'throttle:30,1'])->group(function () {
        Route::put('/organizations/{organization}/url-slug', [OrganizationController::class, 'updateUrlSlug']);
        Route::delete('/organizations/{organization}/url-slug', [OrganizationController::class, 'removeUrlSlug']);
    });
    Route::get('/organizations/{organization}/url-slug-history', [OrganizationController::class, 'urlSlugHistory']);

    // Organisation URL Branding, Phase 2 — customer-owned domains. Read
    // access (index) is available to both Super Admin and Admin (matches
    // the platform-wide-role precedent everywhere else in this file);
    // every mutation is Super Admin ONLY, same reasoning as url-slug above.
    Route::get('/organizations/{organization}/domains', [OrganizationDomainController::class, 'index']);
    Route::middleware(['role:Super Admin', 'throttle:30,1'])->group(function () {
        Route::post('/organizations/{organization}/domains', [OrganizationDomainController::class, 'store']);
        Route::post('/organizations/{organization}/domains/{domain}/verify', [OrganizationDomainController::class, 'verify']);
        Route::post('/organizations/{organization}/domains/{domain}/activate', [OrganizationDomainController::class, 'activate']);
        Route::post('/organizations/{organization}/domains/{domain}/disable', [OrganizationDomainController::class, 'disable']);
        Route::post('/organizations/{organization}/domains/{domain}/remove', [OrganizationDomainController::class, 'remove']);
    });

    // Notifications
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read',    [NotificationController::class, 'markRead']);
    Route::patch('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
    Route::delete('/notifications/clear-read', [NotificationController::class, 'clearRead']);
    Route::delete('/notifications/clear-selected', [NotificationController::class, 'clearSelected']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'clearOne']);

    // Prompt render (available to all authenticated users)
    Route::post('/prompts/{template}/render', [PromptController::class, 'render']);

    // Project-level prompt render (legacy route — kept for backward compatibility)
    Route::post('/projects/{project}/prompts/{template}/render', [PromptController::class, 'renderForProject']);
});
