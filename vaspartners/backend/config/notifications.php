<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Partner communications (Ethio telecom)
    |--------------------------------------------------------------------------
    |
    | Placeholders: {contact_name}, {company_name}, {tt_number} (request number),
    | {service}, {requisition}, {status}, {note}
    |
    | `templates`     — SMS (gateway). Keep concise.
    | `portal`        — In-app notification body (partner portal).
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | SMS queue names
    |--------------------------------------------------------------------------
    |
    | otp   — portal login + admin password OTP (dedicated worker, never blocked by bulk)
    | bulk  — bulk campaigns + general partner SMS (multiple workers)
    |
    */
    'sms_queues' => [
        'otp' => env('SMS_QUEUE_OTP', 'sms-otp'),
        'bulk' => env('SMS_QUEUE_BULK', 'sms'),
        'default' => env('SMS_QUEUE_DEFAULT', 'sms'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS rate limits (transactional / queued partner SMS)
    |--------------------------------------------------------------------------
    |
    | Used by SendSmsJob and general sendNow(). NOT applied as a campaign-size
    | cap on bulk/revenue sends — those use bulk_sms pacing instead.
    |
    | per_phone — max SMS to one Ethiopian number in the decay window
    | global    — max transactional SMS across the app in the decay window
    |
    */
    'sms_rate' => [
        'per_phone' => [
            'max' => (int) env('SMS_RATE_PER_PHONE_MAX', 20),
            'decay_seconds' => (int) env('SMS_RATE_PER_PHONE_DECAY', 3600),
        ],
        'global' => [
            'max' => (int) env('SMS_RATE_GLOBAL_MAX', 120),
            'decay_seconds' => (int) env('SMS_RATE_GLOBAL_DECAY', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk / revenue campaign SMS
    |--------------------------------------------------------------------------
    |
    | Internal admin campaigns are not rate-limited or queue-staggered.
    | OTP rate limits never apply here. Workers drain the sms queue as fast
    | as the gateway allows.
    |
    | messages_per_second — kept for backward-compatible env only (unused).
    |
    */
    'bulk_sms' => [
        'messages_per_second' => max(1, (int) env('SMS_BULK_MESSAGES_PER_SECOND', 5)),
        'throttle' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP-only rate limits (portal login + admin password OTP)
    |--------------------------------------------------------------------------
    |
    | Tighter than bulk SMS so an IP cannot spray verification codes across
    | many numbers. Shared NAT offices still fit the burst + hourly caps.
    |
    | ip_request / ip_verify — HTTP throttle middleware (by client IP)
    | sms_*                 — gateway caps applied only on sync OTP sends
    |
    */
    'otp_rate' => [
        'ip_request' => [
            'max_per_5_minutes' => (int) env('OTP_IP_REQUEST_MAX_5M', 10),
            'max_per_hour' => (int) env('OTP_IP_REQUEST_MAX_HOUR', 20),
        ],
        'ip_verify' => [
            'max_per_5_minutes' => (int) env('OTP_IP_VERIFY_MAX_5M', 40),
            'max_per_hour' => (int) env('OTP_IP_VERIFY_MAX_HOUR', 100),
        ],
        'sms_per_phone' => [
            'max' => (int) env('OTP_SMS_PER_PHONE_MAX', 8),
            'decay_seconds' => (int) env('OTP_SMS_PER_PHONE_DECAY', 300),
        ],
        'sms_global' => [
            'max' => (int) env('OTP_SMS_GLOBAL_MAX', 30),
            'decay_seconds' => (int) env('OTP_SMS_GLOBAL_DECAY', 60),
        ],
    ],

    'templates' => [

        'ticket_submitted' => <<<'SMS'
Dear {contact_name}, your VAS request number {tt_number} for {service} ({requisition}) was submitted. Track it in the VAS Partners portal. — Ethio telecom
SMS,

        'ticket_in_progress' => <<<'SMS'
Dear {contact_name}, VAS request number {tt_number} ({service}) is now in progress. Our team is reviewing your submission. — Ethio telecom
SMS,

        'documents_need_attention' => <<<'SMS'
Dear {contact_name}, documents for VAS request number {tt_number} need your attention. Please update them in the portal. {note} — Ethio telecom
SMS,

        'documents_passed' => <<<'SMS'
Dear {contact_name}, documents for VAS request number {tt_number} ({service}) were accepted. Your request is under review. — Ethio telecom
SMS,

        'ticket_completed' => <<<'SMS'
Dear {contact_name}, VAS request number {tt_number} for {service} has been completed. — Ethio telecom
SMS,

        'ticket_rejected' => <<<'SMS'
Dear {contact_name}, VAS request number {tt_number} for {service} was not approved. {note} Please review in the portal. — Ethio telecom
SMS,

        'documents_incomplete_auto' => <<<'SMS'
Dear {contact_name}, an automated document check found missing required files on VAS request number {tt_number} ({service}). Your request was returned. Upload all required documents in the portal and resubmit. — Ethio telecom
SMS,

        'ticket_closed' => <<<'SMS'
Dear {contact_name}, VAS request number {tt_number} for {service} is now closed. Thank you for partnering with Ethio telecom.
SMS,

        'profile_completed' => <<<'SMS'
Dear {contact_name}, your company profile for {company_name} is complete. You can now submit VAS service requests. — Ethio telecom
SMS,

        'ticket_message' => <<<'SMS'
Dear {contact_name}, there is a new message on VAS request number {tt_number}. Open the portal to reply. {note} — Ethio telecom
SMS,

        'company_attach_approved' => <<<'SMS'
Dear {contact_name}, your request to join {company_name} was approved. You can use the VAS Partners portal. — Ethio telecom
SMS,

        'company_attach_rejected' => <<<'SMS'
Dear {contact_name}, your request to join {company_name} was not approved. {note} — Ethio telecom
SMS,

        'company_membership_requested' => <<<'SMS'
Dear {contact_name}, {applicant_name} requested to join {company_name}. Open the VAS Partners portal to approve or reject. — Ethio telecom
SMS,

        'company_profile_approved' => <<<'SMS'
Dear {contact_name}, your company {company_name} was approved. — Ethio telecom
SMS,

        'company_tin_validated' => <<<'SMS'
Dear {contact_name}, the TIN number for {company_name} is confirmed. Log in to the VAS Partners portal and submit service requests. — Ethio telecom
SMS,

        'company_tin_invalid' => <<<'SMS'
Dear Partner, We are updating the VAS partner portal and you are requested to update your TIN number for your company {company_name}. The access will be revoked if you fail to update your TIN number in the VAS Partners portal. {portal_url} — Ethio telecom
SMS,

        'company_tin_not_found_erca' => <<<'SMS'
Dear Partner, your TIN number ({company_tin}) for {company_name} was not found in ERCA. Update your TIN number in the VAS Partners portal. {portal_url} — Ethio telecom
SMS,

        'company_erca_name_mismatch' => <<<'SMS'
Dear Partner, your company name for {company_name} (TIN number {company_tin}) does not match ERCA (legal name: {legal_name}). Confirm or update in the VAS Partners portal to continue VAS services. {portal_url} — Ethio telecom
SMS,

        'company_profile_rejected' => <<<'SMS'
Dear {contact_name}, your company profile for {company_name} needs updates. {note} Open the portal to correct and resubmit. — Ethio telecom
SMS,

        'company_member_left' => <<<'SMS'
Dear {contact_name}, {applicant_name} left {company_name}. — Ethio telecom
SMS,

        'company_member_added' => <<<'SMS'
Dear {contact_name}, you were added as an active member of {company_name}. Sign in to the VAS Partners portal with this mobile number to continue. {portal_url} — Ethio telecom
SMS,

        'company_transfer_approved' => <<<'SMS'
Dear {contact_name}, ownership transfer for {company_name} was approved. New owner: {applicant_name}. — Ethio telecom
SMS,

        'company_transfer_rejected' => <<<'SMS'
Dear {contact_name}, ownership transfer for {company_name} was not approved. {note} — Ethio telecom
SMS,

        'company_detach_approved' => <<<'SMS'
Dear {contact_name}, your request to leave {company_name} was approved. You may create or join another company in the portal. — Ethio telecom
SMS,

        'company_detach_rejected' => <<<'SMS'
Dear {contact_name}, your request to leave {company_name} was not approved. {note} — Ethio telecom
SMS,

    ],

    'portal' => [

        'ticket_submitted' => 'We received your {requisition} for {service}. Request number {tt_number}.',

        'ticket_in_progress' => 'Request number {tt_number} for {service} is under review by our team.',

        'documents_need_attention' => 'Action needed on request number {tt_number}: please update the required documents in the portal.',

        'documents_passed' => 'Documents for request number {tt_number} ({service}) were accepted. Your request is under review.',

        'ticket_completed' => 'Request number {tt_number} for {service} has been approved and completed.',

        'ticket_rejected' => 'Request number {tt_number} for {service} was not approved. {note} Please review the request in the portal.',

        'documents_incomplete_auto' => 'Automated document check: required files are missing on request number {tt_number} ({service}). Upload all required documents and resubmit.',

        'ticket_closed' => 'Request number {tt_number} for {service} is closed. Thank you for partnering with Ethio telecom.',

        'profile_completed' => 'Your organisation profile for {company_name} is saved. You can submit service requests.',

        'ticket_message' => 'New message on request number {tt_number}: {note}',

        'company_attach_approved' => 'You were approved to join {company_name}.',

        'company_attach_rejected' => 'Your request to join {company_name} was not approved.',

        'company_membership_requested' => '{applicant_name} requested to join {company_name}. Open Company to approve or reject.',

        'company_profile_approved' => 'Your company {company_name} was approved. Ethio telecom will confirm your TIN number before you can submit service requests.',

        'company_tin_validated' => 'TIN number for {company_name} is confirmed. Log in and submit service requests.',

        'company_tin_invalid' => 'Dear Partner, We are updating the VAS partner portal and you are requested to update your TIN number for your company {company_name}. The access will be revoked if you fail to update your TIN number in the VAS Partners portal. {portal_url}',

        'company_tin_not_found_erca' => 'Dear Partner, your TIN number ({company_tin}) for {company_name} was not found in ERCA. Update your TIN number in the VAS Partners portal. {portal_url}',

        'company_erca_name_mismatch' => 'Dear Partner, your company name for {company_name} (TIN number {company_tin}) does not match ERCA (legal name: {legal_name}). Confirm or update in the VAS Partners portal to continue VAS services. {portal_url}',

        'company_profile_pending' => 'Your company {company_name} (TIN number {company_tin}) was submitted and is waiting for Ethio telecom approval. You will be notified when it is reviewed.',

        'company_profile_rejected' => 'Your company profile needs updates before approval.',

        'company_member_left' => '{applicant_name} left {company_name}.',

        'company_member_added' => 'You were added as an active member of {company_name}. Sign in with this mobile number to open the portal.',

        'company_transfer_approved' => 'Ownership of {company_name} was transferred. New owner: {applicant_name}.',

        'company_transfer_rejected' => 'Ownership transfer for {company_name} was not approved.',

        'company_detach_approved' => 'You were detached from {company_name}. You can create or attach to another company.',

        'company_detach_rejected' => 'Your request to leave {company_name} was not approved.',

    ],

];
