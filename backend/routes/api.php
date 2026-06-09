<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\RfiController;
use App\Http\Controllers\Api\VariationController;
use App\Http\Controllers\Api\PaymentApplicationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SiteDiaryController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\EotRequestController;
use App\Http\Controllers\Api\PayLessNoticeController;
use App\Http\Controllers\Api\SiteInstructionController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ProjectActivityController;
use App\Http\Controllers\Api\SuresignSettingController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\TradePackagePackageGenerationController;
use App\Http\Controllers\Api\SnagController;
use App\Http\Controllers\Api\QaReportController;
use App\Http\Controllers\Api\CloseoutController;
use App\Http\Controllers\Api\AdjudicationCaseController;
use App\Http\Controllers\Api\AdjudicationDocumentController;
use App\Http\Controllers\Api\AdjudicationDeadlineController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\CompaniesHouseController;
use App\Http\Controllers\Api\GenerateTradePackageFoldersController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DocumentRegisterController;
use App\Models\FileUpload;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SureSign API Routes
|--------------------------------------------------------------------------
*/

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'updatePassword']);
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Site settings (public read — all authenticated users)
    Route::get('/settings', [SuresignSettingController::class, 'publicShow']);

    // Projects
    Route::apiResource('projects', ProjectController::class);

    // Contracts (nested under projects)
    Route::apiResource('projects.contracts', ContractController::class)->shallow();

    // Commercial
    Route::apiResource('contracts.payment-applications', PaymentApplicationController::class)->shallow();
    Route::apiResource('contracts.variations', VariationController::class)->shallow();

    // Payment application workflow actions
    Route::post('/payment-applications/{paymentApplication}/submit',      [PaymentApplicationController::class, 'submit']);
    Route::post('/payment-applications/{paymentApplication}/certify',     [PaymentApplicationController::class, 'certify']);
    Route::post('/payment-applications/{paymentApplication}/mark-paid',   [PaymentApplicationController::class, 'markPaid']);
    Route::post('/payment-applications/{paymentApplication}/generate-pdf',[PaymentApplicationController::class, 'generatePdf']);

    // Site Administration
    Route::apiResource('projects.rfis', RfiController::class)->shallow();

    // Project sub-resources
    Route::prefix('projects/{project}')->group(function () {
        Route::get('/stats',      [ProjectController::class, 'stats']);
        Route::get('/activities', [ProjectActivityController::class, 'index']);
        Route::get('/payment-applications', [PaymentApplicationController::class, 'indexByProject']);
        Route::get('/variations', [VariationController::class, 'indexByProject']);
        Route::apiResource('site-diaries', SiteDiaryController::class)->shallow();
        Route::apiResource('meetings', MeetingMinutesController::class)->shallow();
        Route::apiResource('eot-requests', EotRequestController::class)->shallow();
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
    });

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

    // Users (admin)
    Route::middleware('role:Super Admin|Admin')->group(function () {
        Route::post('users/invite', [UserController::class, 'invite']);
        Route::apiResource('users', UserController::class)->except(['store']);
        Route::apiResource('organizations', OrganizationController::class)->except(['show', 'update']);

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
            Route::get('/support', [AdminController::class, 'support']);
            Route::get('/system-logs', [AdminController::class, 'systemLogs']);
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
            Route::post('/suresign-settings/letterhead-header',      [SuresignSettingController::class, 'uploadLetterheadHeader']);
            Route::post('/suresign-settings/letterhead-footer',      [SuresignSettingController::class, 'uploadLetterheadFooter']);
            Route::post('/suresign-settings/letterhead-pdf',         [SuresignSettingController::class, 'uploadLetterheadPdf']);
            Route::post('/suresign-settings/email-header',           [SuresignSettingController::class, 'uploadEmailHeader']);
            Route::post('/suresign-settings/email-footer',           [SuresignSettingController::class, 'uploadEmailFooter']);
            Route::delete('/suresign-settings/logo',                 [SuresignSettingController::class, 'removeLogo']);
            Route::delete('/suresign-settings/letterhead-header',    [SuresignSettingController::class, 'removeLetterheadHeader']);
            Route::delete('/suresign-settings/letterhead-footer',    [SuresignSettingController::class, 'removeLetterheadFooter']);
            Route::delete('/suresign-settings/letterhead-pdf',       [SuresignSettingController::class, 'removeLetterheadPdf']);
            Route::delete('/suresign-settings/email-header',         [SuresignSettingController::class, 'removeEmailHeader']);
            Route::delete('/suresign-settings/email-footer',         [SuresignSettingController::class, 'removeEmailFooter']);
            Route::post('/suresign-settings/test-pdf',               [SuresignSettingController::class, 'testPdf']);
            Route::post('/suresign-settings/test-email',             [SuresignSettingController::class, 'testEmail']);
            Route::post('/suresign-settings/sync-from-mirror',       [SuresignSettingController::class, 'syncFromMirror']);

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
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/clear-read', [NotificationController::class, 'clearRead']);
    Route::delete('/notifications/clear-selected', [NotificationController::class, 'clearSelected']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'clearOne']);

    // Prompt render (available to all authenticated users)
    Route::post('/prompts/{template}/render', [PromptController::class, 'render']);

    // Project-level prompt render (legacy route — kept for backward compatibility)
    Route::post('/projects/{project}/prompts/{template}/render', [PromptController::class, 'renderForProject']);
});
