<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FilterController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\CustomFieldController;

use App\Modules\Api\V1\Activity\Controllers\ActivityController;
use App\Modules\Api\V1\AISearch\Controllers\AIAlterQueryController;
use App\Modules\Api\V1\AISearch\Controllers\AIQueryController;
use App\Modules\Api\V1\Asset\Controllers\AssetController;
use App\Modules\Api\V1\AuditLog\Controllers\AuditLogController;
use App\Modules\Api\V1\Comment\Controllers\CommentController;
use App\Modules\Api\V1\GlobalSearchIndex\Controllers\GlobalSearchIndexController;
use App\Modules\Api\V1\Lead\Controllers\LeadController;
use App\Modules\Api\V1\RelatedRecords\Controllers\RelatedRecords;
use App\Modules\Api\V1\Organization\Controllers\OrganizationController;
use App\Modules\Api\V1\User\Controllers\UserController;
use App\Modules\Api\V1\Quotation\Controllers\QuotationController;
use App\Modules\Api\V1\Invoice\Controllers\InvoiceController;

#WHATSAPP
use App\Modules\Api\V1\WhatsApp\Controllers\AccountController;
use App\Modules\Api\V1\WhatsApp\Controllers\ConversationController;
use App\Modules\Api\V1\WhatsApp\Controllers\MessageController;
use App\Modules\Api\V1\WhatsApp\Controllers\TemplateController;
use App\Modules\Api\V1\WhatsApp\Controllers\WebhookController;
use App\Modules\Api\V1\WhatsApp\Controllers\SecurityController;
use App\Modules\Api\V1\WhatsApp\Controllers\InteractiveController;
use App\Modules\Api\V1\WhatsApp\Controllers\FlowController;
use App\Modules\Api\V1\WhatsApp\Controllers\MediaController;

#MAIL
use App\Modules\Api\V1\Mail\Controllers\MailServerController;
use App\Modules\Api\V1\Mail\Controllers\MailSendController;
use App\Modules\Api\V1\Mail\Controllers\MailImapController;

use App\Modules\Api\V1\Zapier\Controllers\ZapierSettingsController;
use App\Modules\Api\V1\Zapier\Controllers\ZapierImportLogController;
use App\Modules\Api\V1\Zapier\Controllers\ZapierWebhookController;
use App\Modules\Api\V1\Zapier\Controllers\ZapierCachedImportController;

#MAILBOX
use App\Modules\Api\V1\Mailbox\Controllers\FolderController;
use App\Modules\Api\V1\Mailbox\Controllers\MailboxController;
use App\Modules\Api\V1\Mailbox\Controllers\LabelController;
use App\Modules\Api\V1\Mailbox\Controllers\DraftController;
use App\Modules\Api\V1\Mailbox\Controllers\SearchController;
use App\Modules\Api\V1\Mailbox\Controllers\SignatureController;
use App\Modules\Api\V1\Mailbox\Controllers\SentController;



Route::prefix('v1')->middleware('api')->group(function () {
    // ========================================
    // PUBLIC ROUTES (No Authentication Required)
    // ========================================
    // Organization creation (rate limited)
    Route::post('organization/new', [OrganizationController::class, 'store'])
        ->middleware('throttle:5,1');
    	Route::post('settings/User/{id}', [UserController::class, 'store']);

    // Authentication routes (rate limited to prevent brute force)
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1'); // 5 attempts per minute
    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');
    Route::get('login/google', [GoogleAuthController::class, 'getSigninUrl']);
    Route::get('login/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);

    // WhatsApp webhook (public for Meta verification)
    Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify']);
    Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle']);

    // Mail Tracking (Public)
    Route::get('/mail/track/{token}', [MailImapController::class, 'trackOpen']);

    // Zapier webhook (public, API-key authenticated, rate limited)
    Route::post('zapier/webhook/{module}', [ZapierWebhookController::class, 'handle'])
        ->middleware('throttle:60,1');

    // ========================================
    // PROTECTED ROUTES (Require Authentication)
    // ========================================
	Route::middleware('auth:sanctum')->group(function () {

        
        // ========================================
        // Custom Fields Management
        // ========================================
        Route::put('field-update', [CustomFieldController::class, 'updateFieldLabel']);
        Route::get('field-details/{module}/{id}', [CustomFieldController::class, 'show']);
        Route::delete('field/{id}', [CustomFieldController::class, 'delete']);
        Route::post('custom-field-creation', [CustomFieldController::class, 'create']);
        Route::get('custom-field-creation/view-fields', [CustomFieldController::class, 'createViewFields']);
        Route::get('custom-field-creation/list', [CustomFieldController::class, 'list']);
		
		// Whatsapp Messages
		Route::post('/{module}/{recordId}/whatsapp/send',[MessageController::class,'send']);
		Route::get('/{module}/{recordId}/whatsapp/chat',[MessageController::class,'fetchAllChats']);
		
		//Channel list
		Route::get('/whatsapp/channels', [AccountController::class, 'getByOrg']);
		
		//Preview template
		Route::get('/{module}/{recordId}/channel/{channelId}/template/{templateId}/whatsapp/preview',[TemplateController::class, 'previewTemplate']);
		
		Route::get('/whatsapp/{channel_id}/templates', [TemplateController::class, 'getWhatsAppTemplates']);
        
        // ========================================
        // WhatsApp Integration (EXCLUDED FROM TEST SCOPE)
        // ========================================
		
		//Settings
		Route::prefix('settings/whatsapp')->group(function () {
			Route::get('/account-check', [AccountController::class, 'healthCheck']);
			Route::post('/account-info/save', [AccountController::class, 'save']);
			Route::get('/channels', [AccountController::class, 'getByOrg']);

			Route::post('/conversations/{id}/status', [ConversationController::class, 'updateStatus']);
			Route::get('/conversations/{id}/window', [ConversationController::class, 'checkWindow']);

			// Messages
			Route::post('/messages/validate', [MessageController::class, 'validateMessage']);
			Route::post('/messages/{module}/{contactId}/message/whatsapp', [MessageController::class, 'sendWhatsAppMessage']);
			Route::post('/messages/send-media/{module}/{recordId}', [MessageController::class,'sendMediaMessage']);

			Route::post('/interactive/save', [InteractiveController::class, 'save']);
			Route::post('/flow-messages/appointment/whatsapp', [FlowController::class, 'sendAppointmentFlow']);

			// Templates
			Route::get('/{channel_id}/templates/sync',[TemplateController::class, 'syncTemplates']);
			Route::get('/{channel_id}/templates', [TemplateController::class, 'getWhatsAppTemplates']);
			Route::get('/{channel_id}/template/{template_id}/sync',[TemplateController::class, 'syncSingleTemplate']);

			Route::get('/{channel_id}/template/{template_id}',[TemplateController::class, 'templateMapping']);
			Route::post('/{channel_id}/template/{template_id}',[TemplateController::class, 'templateMapping']);

			Route::get('/templates/preview/{templateName}/{module}/{recordId}',[TemplateController::class, 'previewTemplate']);
			Route::post('/templates/list-check', [TemplateController::class, 'listCheck']);

			// Security
			Route::post('/security/rotate-token', [SecurityController::class, 'rotateToken']);
		});
	Route::prefix('settings/outgoing-server')->group(function () {
		Route::get('/servers', [MailServerController::class, 'index']);
		Route::post('/new', [MailServerController::class, 'store']);
		Route::get('/{id}', [MailServerController::class, 'show']);
		Route::post('/{id}', [MailServerController::class, 'update']);
		Route::delete('/{id}', [MailServerController::class, 'destroy']);
		Route::post('/{id}/connect', [MailServerController::class, 'connect']);
		Route::patch('/{id}/set-outgoing', [MailServerController::class, 'setOutgoing']);
	});

	Route::prefix('mail')->group(function () {   
		Route::post('/send', [MailSendController::class, 'send']);
		Route::post('imap/new', [MailImapController::class, 'store']);
		Route::get('imap/{id}', [MailImapController::class, 'show']);
		Route::post('imap/{id}', [MailImapController::class, 'update']);
		Route::post('imap/{id}/connect', [MailImapController::class, 'connect']);
		Route::get('imap/{id}/inbox', [MailImapController::class, 'inbox']);
		Route::get('imap/{id}/thread/{threadId}', [MailImapController::class, 'showThread']);
		Route::get('imap/{id}/search', [MailImapController::class, 'search']);
	});

    // ========================================
    // Mailbox (Unified)
    // ========================================
    Route::prefix('mailbox')->group(function () {
        // Core

        Route::get('inbox', [MailboxController::class, 'index']);
        Route::get('email/{id}', [MailboxController::class, 'show']);
        Route::post('compose', [MailboxController::class, 'send']);
        Route::post('bulk-action', [MailboxController::class, 'bulkAction']);
        
        // Sent
        Route::get('sent', [SentController::class, 'index']);
        Route::get('sent/{id}', [SentController::class, 'show']);

        // Folders by server
        Route::post('folders/sync', [FolderController::class, 'sync']);
		Route::get('folders/server/{mailServerId}', [FolderController::class,'listByServer']);
		
		Route::post('drafts/new', [DraftController::class, 'store']);
		Route::post('drafts/{id}', [DraftController::class, 'update']);
		Route::delete('drafts/{id}', [DraftController::class, 'destroy']);
		Route::get('drafts/{id}', [DraftController::class, 'show']);
		Route::get('drafts/server/{mailServerId}', [DraftController::class, 'index']);
        // Resources
        Route::resource('folders', FolderController::class);
		
        Route::resource('labels', LabelController::class);


        Route::resource('signatures', SignatureController::class);
    });

        // ========================================
        // Global Search & Filters (BEFORE generic module routes)
        // Rate limited to prevent abuse
        // ========================================
		Route::post('filter/{module}', [GlobalSearchIndexController::class, 'filter'])
            ->middleware('throttle:60,1'); // 60 requests per minute
        Route::get('filter/{module}', [GlobalSearchIndexController::class, 'filter'])
            ->middleware('throttle:60,1');
		Route::post('global-search', [GlobalSearchIndexController::class, 'globalSearch'])
            ->middleware('throttle:30,1'); // 30 requests per minute for global search
        Route::get('global-search', [GlobalSearchIndexController::class, 'globalSearch'])
            ->middleware('throttle:30,1');

        // ========================================
        // Admin-Only Settings
        // ========================================
		Route::prefix('settings')->middleware('admin.only')->group(function () {
			// User Management
			Route::get('User', [UserController::class, 'index']);
			Route::get('User/{id}', [UserController::class, 'show']);
			Route::delete('User/{id}', [UserController::class, 'destroy']);

			// Role Management
			Route::get('roles', [RoleController::class, 'index']);
			Route::get('roles/{id}', [RoleController::class, 'show']);
			Route::post('roles', [RoleController::class, 'store']);
			Route::post('roles/profile/relate', [RoleController::class, 'relateProfiles']);
			Route::post('roles/user/relate', [RoleController::class, 'relateUser']);
			Route::delete('roles/{id}', [RoleController::class, 'delete']);

			// Profile Management
			Route::put('profile/save-full', [ProfileController::class, 'saveAll']);
			Route::get('get_profiles', [ProfileController::class, 'index']);
			Route::post('profile', [ProfileController::class, 'store']);
			Route::get('profile/info', [ProfileController::class, 'profileModuleFields']);
			Route::get('profile/{id}/details', [ProfileController::class, 'details']);
			Route::post('profile_fields', [ProfileController::class, 'profile_fields']);
			Route::post('profile_global_actions', [ProfileController::class, 'profile_global_actions']);
			Route::post('profile_module', [ProfileController::class, 'profile_module']);
			Route::post('global_actions', [ProfileController::class, 'global_actions']);
			Route::get('profile/{module}/fields', [ProfileController::class, 'profileModuleFields']);
			Route::get('profile/modules', [ProfileController::class, 'portalModules']);
			Route::post('profile/repair', [ProfileController::class, 'repair']);
			Route::delete('profile/{id}', [ProfileController::class, 'delete']);
		});

        // ========================================
        // Activity Management (Specific routes before generic)
        // ========================================
		Route::get('Activity/{id}/pre-summary', [ActivityController::class, 'preSummary']);
		Route::post('Activity/new', [ActivityController::class, 'save']);
		Route::post('Activity/{id}/activity-status-update', [ActivityController::class, 'updateStatus']);

        // ========================================
        // Comments & Related Records
        // ========================================
		Route::post('comment/new', [CommentController::class, 'save']);
		Route::get('{module}/{entity_id}/comment/records', [CommentController::class, 'getEntityComments']);
		Route::get('{module}/{entity_id}/Activity/records', [ActivityController::class, 'getEntityActivities']);

        // ========================================
        // Asset & Lead Transformation
        // ========================================
        Route::post('Asset/new', [AssetController::class, 'createAssetDoc']);
        Route::get('leads/{id}/transform', [LeadController::class, 'transformToContact']);

        // ========================================
        // AI Search (EXCLUDED FROM TEST SCOPE)
        // ========================================
		Route::prefix('ai-search')->group(function () {
			Route::post('query/generate', [AIQueryController::class, 'generate']);
			Route::post('query/process', [AIQueryController::class, 'processQuery']);
			Route::get('query/search', [AIQueryController::class, 'searchQuery']);
			Route::get('query/quick-access', [AIQueryController::class, 'getQuickAccessQueries']);
			Route::post('query/available-fields', [AIQueryController::class, 'getAvailableFields']);
			Route::post('query/re-execute', [AIQueryController::class, 'reExecuteQuery']);
			Route::post('query/execute-with-fields', [AIQueryController::class, 'executeWithSelectedFields']);
			Route::post('query/alter', [AIAlterQueryController::class, 'processQuery']);
		});

        // ========================================
        // Activity Specific Routes
        // ========================================
		Route::prefix('Activity')->group(function () {
			Route::get('my-list', [ActivityController::class, 'myActivities']);
			Route::get('{uuid}', [ActivityController::class, 'getActivityDetails'])
				->where('uuid', '[0-9a-fA-F\-]{36}');
		});

        // ========================================
        // Quotation Specific Routes
        // ========================================
		Route::prefix('Quotation')->group(function () {
			Route::get('headers', function () {
				return app(RecordController::class)->headerfields('Quotation');
			});
			Route::get('headers/{id}', function ($id) {
				return app(RecordController::class)->filterHeaderFields('Quotation', $id);
			});
			Route::post('{id}', [QuotationController::class, 'save']);
			Route::get('{id}', [QuotationController::class, 'show']);
			Route::post('{id}/convert-to-invoice', [QuotationController::class, 'convertToInvoice']);
			Route::post('{id}/update-status', [QuotationController::class, 'updateStatus']);
		});

        // ========================================
        // Invoice Specific Routes
        // ========================================
		Route::prefix('Invoice')->group(function () {
			Route::get('headers', function () {
				return app(RecordController::class)->headerfields('Invoice');
			});
			Route::get('headers/{id}', function ($id) {
				return app(RecordController::class)->filterHeaderFields('Invoice', $id);
			});
			Route::post('{id}', [InvoiceController::class, 'save']);
			Route::get('{id}', [InvoiceController::class, 'show']);
			Route::post('{id}/update-payment', [InvoiceController::class, 'updatePayment']);
			Route::post('{id}/update-status', [InvoiceController::class, 'updateStatus']);
		});

        // ========================================
        // Filter Management (MUST be before generic module routes)
        // ========================================
		Route::prefix('filters')->group(function () {
			Route::get('config', [FilterController::class, 'getConfig']);
			Route::get('/', [FilterController::class, 'index']);
			Route::post('new', [FilterController::class, 'store']);
			Route::get('{id}', [FilterController::class, 'show']);
			Route::put('{id}', [FilterController::class, 'update']);
			Route::delete('{id}', [FilterController::class, 'destroy']);
			Route::post('{id}/set-default', [FilterController::class, 'setDefault']);
			Route::post('{id}/duplicate', [FilterController::class, 'duplicate']);
			Route::get('{id}/records', [FilterController::class, 'getRecords']);
		});

        // ========================================
        // Zapier Integration (MUST be before generic module routes)
        // ========================================
		Route::prefix('zapier')->group(function () {
			// Settings
			Route::get('settings', [ZapierSettingsController::class, 'show']);
			Route::put('settings', [ZapierSettingsController::class, 'update']);
			Route::post('settings/test', [ZapierSettingsController::class, 'testConnection']);
			Route::post('settings/generate-api-key', [ZapierSettingsController::class, 'generateApiKey']);
			Route::get('settings/webhook-url', [ZapierSettingsController::class, 'getWebhookUrl']);
			Route::get('settings/triggers-actions', [ZapierSettingsController::class, 'getTriggersAndActions']);
			Route::get('settings/statistics', [ZapierSettingsController::class, 'getStatistics']);

			// Import Logs
			Route::get('imports', [ZapierImportLogController::class, 'index']);

			// Cached webhook imports (specific routes before wildcard)
			Route::get('imports/batches', [ZapierCachedImportController::class, 'listBatches']);
			Route::get('imports/{batchId}/records', [ZapierCachedImportController::class, 'listRecords']);
			Route::get('imports/records/{recordId}', [ZapierCachedImportController::class, 'showRecord']);
			Route::get('imports/{batchId}/metadata', [ZapierCachedImportController::class, 'moduleMetadata']);
			Route::post('imports/records/{recordId}/mapping', [ZapierCachedImportController::class, 'submitMapping']);
			Route::post('imports/records/{recordId}/process', [ZapierCachedImportController::class, 'triggerProcessing']);

			Route::get('imports/{id}', [ZapierImportLogController::class, 'show']);
			Route::post('imports/{id}/retry', [ZapierImportLogController::class, 'retry']);
		});

        // ========================================
        // GENERIC MODULE CRUD (MUST BE LAST - wildcard routes)
        // ========================================
		Route::prefix('{module}')->group(function () {
			Route::get('/', [RecordController::class, 'index']);
			Route::post('{id}', [RecordController::class, 'store']);
			Route::get('headers/{id}', [RecordController::class, 'filterHeaderFields']);
			Route::get('headers', [RecordController::class, 'headerfields']);
			Route::get('new/forms', [RecordController::class, 'createForm']);
			Route::patch('{id}/inline-edit', [RecordController::class, 'inlineEdit']);
			Route::get('{id}', [RecordController::class, 'show']);
			Route::put('{id}', [RecordController::class, 'update']);
			Route::delete('{id}', [RecordController::class, 'destroy']);
			Route::get('{id}/edit', [RecordController::class, 'edit']);
			Route::get('{id}/audit-log', [RecordController::class, 'getAuditLogs']);
			Route::get('{id}/{relatedmodule}/records', [RelatedRecords::class, 'index']);
			
			// Module-based mail sending
			Route::post('{recordId}/mail/send', [MailSendController::class, 'sendFromRecord']);
			Route::post('{recordId}/mailbox/compose', [MailboxController::class, 'composeFromRecord']);
            		Route::get('{recordId}/getEmailAddress', [MailSendController::class, 'getEmailAddress']);
		});
	});
});
