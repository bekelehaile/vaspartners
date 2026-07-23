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

        'ticket_message' => <<<'SMS'
Dear {customer_name}, there is a new message on VAS request {tt_number}. Open the portal to reply. {note} — Ethio telecom
SMS,

        'company_attach_approved' => <<<'SMS'
Dear {customer_name}, your request to join {company_name} was approved. You can use the VAS Partners portal. — Ethio telecom
SMS,

        'company_attach_rejected' => <<<'SMS'
Dear {customer_name}, your request to join {company_name} was not approved. {note} — Ethio telecom
SMS,

        'company_membership_requested' => <<<'SMS'
Dear {customer_name}, {applicant_name} requested to join {company_name}. Open the VAS Partners portal to approve or reject. — Ethio telecom
SMS,

        'company_profile_approved' => <<<'SMS'
Dear {customer_name}, your company {company_name} was approved. You can now use the VAS Partners portal. — Ethio telecom
SMS,

        'company_profile_rejected' => <<<'SMS'
Dear {customer_name}, your company profile for {company_name} needs updates. {note} Open the portal to correct and resubmit. — Ethio telecom
SMS,

        'company_detach_approved' => <<<'SMS'
Dear {customer_name}, your request to leave {company_name} was approved. You may create or join another company in the portal. — Ethio telecom
SMS,

        'company_detach_rejected' => <<<'SMS'
Dear {customer_name}, your request to leave {company_name} was not approved. {note} — Ethio telecom
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

        'ticket_message' => 'New message on {tt_number}: {note}',

        'company_attach_approved' => 'You were approved to join {company_name}.',

        'company_attach_rejected' => 'Your request to join {company_name} was not approved.',

        'company_membership_requested' => '{applicant_name} requested to join {company_name}. Open Company to approve or reject.',

        'company_profile_approved' => 'Your company {company_name} was approved. You can submit service requests.',

        'company_profile_rejected' => 'Your company profile needs updates before approval.',

        'company_detach_approved' => 'You were detached from {company_name}. You can create or attach to another company.',

        'company_detach_rejected' => 'Your request to leave {company_name} was not approved.',

    ],

];
