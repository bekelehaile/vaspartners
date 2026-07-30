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
    | Ethio telecom NGBSS / IVR CRM (same stack as bill_complaint).
    | Used to verify partner personal identity by MSISDN after OTP when Fayda is unavailable.
    */
    'crm' => [
        'enabled' => filter_var(env('CRM_ENABLED', true), FILTER_VALIDATE_BOOL),
        'query_endpoint' => env('CRM_QUERY_CUSTOMER_ENDPOINT')
            ?: env('QUERY_CUSTOMER_ENDPOINT')
            ?: (rtrim((string) env('CRM_API_BASE_URL', env('API_BASE_URL', '')), '/')
                .(string) env('CRM_CUSTOMER_ENDPOINT', env('CUSTOMER_ENDPOINT', ''))),
        'timeout' => (int) env('CRM_TIMEOUT', env('TIMEOUT', 15)),
        'access_token' => [
            'base_url' => env('CRM_ACCESS_TOKEN_BASE_URL', env('ACCESS_TOKEN_BASE_URL')),
            'app_key' => env('CRM_ACCESS_TOKEN_APP_KEY', env('ACCESS_TOKEN_APP_KEY')),
            'secret_key' => env('CRM_ACCESS_TOKEN_SECRET_KEY', env('ACCESS_TOKEN_SECRET_KEY')),
            'timeout' => (int) env('CRM_TIMEOUT', env('TIMEOUT', 15)),
        ],
    ],

];
