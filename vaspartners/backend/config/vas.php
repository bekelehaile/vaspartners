<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    // Cap on concurrent *new subscription* (creates_subscription) open tickets
    // per company + service. Other services are unaffected. Manage / renew /
    // terminate requests are not limited by this value.
    'max_open_tickets' => (int) env('MAX_OPEN_TICKETS', 1),
    // Comma-separated last-9 phone digits that may exceed max_open_tickets (test accounts).
    'open_ticket_limit_exempt_phones' => array_values(array_filter(array_map(
        static fn (string $phone): string => \App\Support\PhoneNumber::normalize($phone),
        explode(',', (string) env('OPEN_TICKET_LIMIT_EXEMPT_PHONES', '')),
    ))),
    // Default when a subscription-based service has no interval set yet
    'default_renewal_interval' => env('DEFAULT_RENEWAL_INTERVAL', 'yearly'), // yearly|bi_yearly

    // Rejected tickets stay open for partner resubmit this many days, then the system closes them.
    'rejected_ticket_auto_close_days' => (int) env('REJECTED_TICKET_AUTO_CLOSE_DAYS', 14),
    // Max size for chat PDF attachments (KB)
    'chat_attachment_max_kb' => (int) env('CHAT_ATTACHMENT_MAX_KB', 2048),
    // Don't re-notify on consecutive messages from the same party within this window
    'chat_notify_quiet_minutes' => (int) env('CHAT_NOTIFY_QUIET_MINUTES', 10),
    // Max size for company attach/detach PDF docs (KB)
    'company_change_doc_max_kb' => (int) env('COMPANY_CHANGE_DOC_MAX_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Portal ticket create (anti-abuse only)
    |--------------------------------------------------------------------------
    |
    | These limits only slow scripted / concurrent POSTs. They are not the
    | subscription quota. Business rule: one alive subscription per service
    | per company (unique validated TIN number) — enforced in SubscriptionLifecycle
    | + partial unique index subscriptions_one_alive_per_company_service.
    | Idempotency-Key replays return the same ticket for 24h (safe client retries).
    |
    */
    'ticket_create' => [
        'per_contact_per_minute' => (int) env('TICKET_CREATE_PER_CONTACT_PER_MINUTE', 5),
        'per_company_per_minute' => (int) env('TICKET_CREATE_PER_COMPANY_PER_MINUTE', 10),
        'per_ip_per_minute' => (int) env('TICKET_CREATE_PER_IP_PER_MINUTE', 20),
        'lock_seconds' => (int) env('TICKET_CREATE_LOCK_SECONDS', 20),
        'lock_wait_seconds' => (int) env('TICKET_CREATE_LOCK_WAIT_SECONDS', 8),
        'idempotency_ttl_hours' => (int) env('TICKET_CREATE_IDEMPOTENCY_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monthly Revenue
    |--------------------------------------------------------------------------
    |
    | block_duplicates=false (default): open mode for AMs — same partner/month
    | can be re-imported and re-sent; duplicate Service IDs in one CSV are kept.
    | Set REVENUE_BLOCK_DUPLICATES=true (or later an admin setting) to enforce.
    |
    */
    'revenue' => [
        'block_duplicates' => filter_var(env('REVENUE_BLOCK_DUPLICATES', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
