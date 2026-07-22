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
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SiteDiaryController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\EotRequestController;
use App\Http\Controllers\Api\DelayEventController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\DeliveryDocumentController;
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
use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentTypeController;
use App\Http\Controllers\Api\PublicAppointmentActionController;
use App\Http\Controllers\Api\PublicAppointmentController;
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

// Public marketing-site lead capture (suresigncontracts.app/book-a-demo) — no auth.
// Superseded by the public Appointments booking flow below (Phase 3) for
// the marketing site's own CTA, but left in place/unused rather than
// removed — see internal-docs/super-admin/appointments.md.
Route::post('/demo-requests', [DemoRequestController::class, 'store'])->middleware('throttle:demo-request');

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

    // Cross-project reports
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/commercial-summary', [ReportController::class, 'commercialSummary']);
    Route::get('/reports/commercial-summary-report', [ReportController::class, 'commercialSummaryReport']);
    Route::get('/reports/commercial-summary-report/export/pdf', [ReportController::class, 'exportCommercialSummaryPdf']);
    Route::get('/reports/commercial-summary-report/export/excel', [ReportController::class, 'exportCommercialSummaryExcel']);

    // Global Commercial — organisation-wide commercial monitoring/triage (read-only)
    Route::get('/commercial/overview', [CommercialOverviewController::class, 'overview']);

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
        Route::apiResource('pay-less-notices', PayLessNoticeController::class)->shallow();

        Route::apiResource('site-instructions', SiteInstructionController::class)->shallow();

        // Snagging
        Route::apiResource('snagging', SnagController::class)->shallow();

        // QA Reports
        Route::apiResource('qa-reports', QaReportController::class)->shallow();

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

        // Application Monitoring — cross-organization presence/usage/operational
        // data. Super Admin only; deliberately not in the 'Super Admin|Admin'
        // group below (see internal-docs/super-admin/application-monitoring.md).
        Route::get('/admin/application-monitoring', [ApplicationMonitoringController::class, 'index']);
    });

    Route::middleware('role:Super Admin|Admin')->group(function () {
        Route::apiResource('organizations', OrganizationController::class)->except(['show', 'update']);

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
