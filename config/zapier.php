<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zapier API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Zapier webhook integration. The API key is used to
    | authenticate incoming webhook requests from Zapier.
    |
    */

    /**
     * API Key for Zapier webhook authentication
     * 
     * Set this in your .env file:
     * ZAPIER_API_KEY=your-secret-api-key-here
     * 
     * This key should be:
     * - At least 32 characters long
     * - Random and secure
     * - Kept secret (never commit to version control)
     */
    'api_key' => env('ZAPIER_API_KEY'),

    /**
     * Allowed modules for Zapier webhooks
     * 
     * Only these modules can receive webhook data from Zapier
     */
    'allowed_modules' => [
        'contacts',
        'leads',
        'products',
    ],

    /**
     * Webhook timeout (seconds)
     * 
     * Maximum time to wait for webhook processing
     */
    'timeout' => env('ZAPIER_WEBHOOK_TIMEOUT', 30),

    /**
     * Enable webhook logging
     * 
     * Set to false to disable detailed webhook logging
     */
    'logging_enabled' => env('ZAPIER_LOGGING_ENABLED', true),

];
