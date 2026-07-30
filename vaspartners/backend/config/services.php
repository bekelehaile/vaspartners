<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'esignet' => [
        'client_id' => env('FAYDA_CLIENT_ID'),
        'redirect_uri' => env('FAYDA_REDIRECT_URI'),
        'authorization_endpoint' => env('FAYDA_AUTH_URL'),
        'token_endpoint' => env('FAYDA_TOKEN_URL'),
        'userinfo_endpoint' => env('FAYDA_USERINFO_URL'),
        'client_assertion_type' => env('FAYDA_ASSERTION_TYPE', 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'),
        'private_key' => env('FAYDA_PRIVATE_KEY'),
        'expiration_time' => env('FAYDA_EXPIRATION_TIME', 15),
        'algorithm' => env('FAYDA_ALG', 'RS256'),
    ],

    /*
    | Ethio telecom bulk SMS gateway.
    | Full URL prefix ending with receiver= — MSISDN (251…) + message are appended.
    | Receivers are always Ethiopian mobiles (+251 / 251XXXXXXXXX).
    */
    'sms_endpoint' => env('SMS_ENDPOINT', 'https://smsgw.ethiotelecom.et/bl/index.php?receiver='),
    'sms_country_code' => env('SMS_COUNTRY_CODE', '251'),

    /*
    | Ethio telecom BSS GetCustomer (same stack as fixedservices).
    | Used to verify partner personal identity by MSISDN after OTP when Fayda is unavailable.
    | Prefer NG_* (shared with fixedservices); CRM_* aliases kept for ops familiarity.
    */
    'crm' => [
        'enabled' => filter_var(env('CRM_ENABLED', true), FILTER_VALIDATE_BOOL),
        'endpoint' => env('CRM_NG_ENDPOINT', env('NG_ENDPOINT')),
        'access_user' => env('CRM_NG_ACCESS_USER', env('NG_ACCESS_USER', 'PortalFixedService')),
        'access_pwd' => env('CRM_NG_ACCESS_PWD', env('NG_ACCESS_PWD')),
        'channel_id' => env('CRM_NG_CHANNEL_ID', env('NG_CHANNEL_ID', '116')),
        'technical_channel_id' => env('CRM_NG_TECHNICAL_CHANNEL_ID', env('NG_TECHNICAL_CHANNEL_ID', '53')),
        'tenant_id' => env('CRM_NG_TENANT_ID', env('NG_TENANT_ID', '101')),
        'language' => env('CRM_NG_LANGUAGE', env('NG_LANGUAGE', '2002')),
        'timeout' => (int) env('CRM_TIMEOUT', env('TIMEOUT', 15)),
    ],

    /*
    | eTrade (etrade.gov.et) — Ministry of Trade TIN / registration lookup.
    | Official browser app uses /api/Tin/checkTin/{tin} (api/v1/taxpayers/tin/{tin} currently 404s).
    */
    'etrade' => [
        'enabled' => filter_var(env('ETRADE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => rtrim((string) env('ETRADE_BASE_URL', 'https://etrade.gov.et'), '/'),
        'tin_path' => (string) env('ETRADE_TIN_PATH', '/api/Tin/checkTin/{tin}'),
        'registrations_path' => (string) env('ETRADE_REGISTRATIONS_PATH', '/api/Registration/GetRegistrationsByTin/{tin}'),
        'timeout' => (int) env('ETRADE_TIMEOUT', 20),
        'verify_ssl' => filter_var(env('ETRADE_VERIFY_SSL', false), FILTER_VALIDATE_BOOL),
        'include_registrations' => filter_var(env('ETRADE_INCLUDE_REGISTRATIONS', true), FILTER_VALIDATE_BOOL),
        // Anti-flood: cache each TIN number lookup and cap scheduled/global calls.
        'tin_cache_hours' => (int) env('ETRADE_TIN_CACHE_HOURS', 6),
        'global_lookups_per_minute' => (int) env('ETRADE_GLOBAL_LOOKUPS_PER_MINUTE', 20),
        'recheck_hours' => (int) env('ETRADE_RECHECK_HOURS', 168),
        'retry_hours' => (int) env('ETRADE_RETRY_HOURS', 6),
        'schedule_limit' => (int) env('ETRADE_SCHEDULE_LIMIT', 25),
        'schedule_sleep_ms' => (int) env('ETRADE_SCHEDULE_SLEEP_MS', 800),
    ],

];
