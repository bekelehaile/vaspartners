<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'max_open_tickets' => (int) env('MAX_OPEN_TICKETS', 1),
    // Default when a subscription-based service has no interval set yet
    'default_renewal_interval' => env('DEFAULT_RENEWAL_INTERVAL', 'yearly'), // yearly|bi_yearly
];
