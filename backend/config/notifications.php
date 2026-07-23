<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Partner SMS templates (Ethio telecom gateway)
    |--------------------------------------------------------------------------
    |
    | Placeholders: {customer_name}, {company_name}, {tt_number}, {service},
    | {requisition}, {status}, {note}
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', true),

    'templates' => [

        'ticket_submitted' => <<<'SMS'
Dear {customer_name},

Your VAS partner request {tt_number} for {service} ({requisition}) has been submitted successfully.

Track progress in the VAS Partners portal.

Ethio telecom
SMS,

        'ticket_in_progress' => <<<'SMS'
Dear {customer_name},

Your VAS request {tt_number} ({service}) is now in progress. Our team is reviewing your submission.

Ethio telecom
SMS,

        'documents_need_attention' => <<<'SMS'
Dear {customer_name},

Documents for VAS request {tt_number} need your attention. Please update them in the portal and we will re-check.

{note}

Ethio telecom
SMS,

        'ticket_completed' => <<<'SMS'
Dear {customer_name},

Good news — VAS request {tt_number} for {service} has been approved/completed.

Ethio telecom
SMS,

        'ticket_rejected' => <<<'SMS'
Dear {customer_name},

VAS request {tt_number} for {service} was rejected and needs attention.

{note}

Please update your documents or contact support via the portal.

Ethio telecom
SMS,

        'ticket_closed' => <<<'SMS'
Dear {customer_name},

VAS request {tt_number} for {service} is now closed.

Thank you for partnering with Ethio telecom.
SMS,

        'profile_completed' => <<<'SMS'
Dear {customer_name},

Your company profile for {company_name} is complete. You can now submit VAS partner service requests.

Ethio telecom
SMS,

    ],

];
