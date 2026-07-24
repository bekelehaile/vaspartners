<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    // Cap on concurrent *new subscription* (creates_subscription) open tickets only.
    // Manage / renew / terminate requests are not limited by this value.
    'max_open_tickets' => (int) env('MAX_OPEN_TICKETS', 1),
    // Default when a subscription-based service has no interval set yet
    'default_renewal_interval' => env('DEFAULT_RENEWAL_INTERVAL', 'yearly'), // yearly|bi_yearly
    // Max size for chat PDF attachments (KB)
    'chat_attachment_max_kb' => (int) env('CHAT_ATTACHMENT_MAX_KB', 2048),
    // Don't re-notify on consecutive messages from the same party within this window
    'chat_notify_quiet_minutes' => (int) env('CHAT_NOTIFY_QUIET_MINUTES', 10),
    // Max size for company attach/detach PDF docs (KB)
    'company_change_doc_max_kb' => (int) env('COMPANY_CHANGE_DOC_MAX_KB', 5120),
];
