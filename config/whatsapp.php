<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed Domains for Media Access
    |--------------------------------------------------------------------------
    |
    | List of domains allowed to access WhatsApp media files.
    | Separate multiple domains with commas.
    | Example: 'domain1.com,domain2.com'
    |
    */
    'allowed_domains' => explode(',', env('WHATSAPP_ALLOWED_DOMAINS', '')),
];
