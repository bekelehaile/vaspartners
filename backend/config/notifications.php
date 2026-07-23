<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Partner communications (Ethio telecom)
    |--------------------------------------------------------------------------
    |
    | Placeholders: {customer_name}, {company_name}, {tt_number}, {service},
    | {requisition}, {status}, {note}
    |
    | `templates`     — SMS (gateway). Keep concise.
    | `portal`        — In-app notification body (partner portal).
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', true),

    'templates' => [

        'ticket_submitted' => <<<'SMS'
Dear {customer_name}, your VAS request {tt_number} for {service} ({requisition}) was submitted. Track it in the VAS Partners portal. — Ethio telecom
SMS,

        'ticket_in_progress' => <<<'SMS'
Dear {customer_name}, VAS request {tt_number} ({service}) is now in progress. Our team is reviewing your submission. — Ethio telecom
SMS,

        'documents_need_attention' => <<<'SMS'
Dear {customer_name}, documents for VAS request {tt_number} need your attention. Please update them in the portal. {note} — Ethio telecom
SMS,

        'ticket_completed' => <<<'SMS'
Dear {customer_name}, VAS request {tt_number} for {service} has been completed. — Ethio telecom
SMS,

        'ticket_rejected' => <<<'SMS'
Dear {customer_name}, VAS request {tt_number} for {service} was not approved. {note} Please review in the portal. — Ethio telecom
SMS,

        'ticket_closed' => <<<'SMS'
Dear {customer_name}, VAS request {tt_number} for {service} is now closed. Thank you for partnering with Ethio telecom.
SMS,

        'profile_completed' => <<<'SMS'
Dear {customer_name}, your company profile for {company_name} is complete. You can now submit VAS service requests. — Ethio telecom
SMS,

    ],

    'portal' => [

        'ticket_submitted' => 'We received your {requisition} for {service}. Reference {tt_number}.',

        'ticket_in_progress' => '{tt_number} for {service} is under review by our team.',

        'documents_need_attention' => 'Action needed on {tt_number}: please update the required documents in the portal.',

        'ticket_completed' => '{tt_number} for {service} has been approved and completed.',

        'ticket_rejected' => '{tt_number} for {service} was not approved. Please review the request in the portal.',

        'ticket_closed' => '{tt_number} for {service} is closed. Thank you for partnering with Ethio telecom.',

        'profile_completed' => 'Your organisation profile for {company_name} is saved. You can submit service requests.',

    ],

];
