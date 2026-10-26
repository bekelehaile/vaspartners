<?php

use App\Enums\ProvisioningDepartment as Dept;

/**
 * Service provisioning workflow graphs.
 *
 * form_fields: only fields the actor types on that step (simple forms).
 * Optional remark is always available. Attachment is optional unless requires_attachment.
 * Auto/carry-forward values are merged server-side into payload for the follow-up table.
 */
return [

    'public_id_prefix' => 'PRV-',

    // Same family of checks as service-request DocumentType uploads (size + extension + MIME sniff).
    'attachment_accepted_mimes' => env('PROVISIONING_ATTACHMENT_MIMES', 'pdf,doc,docx,png,jpg,jpeg'),
    'attachment_max_kb' => (int) env('PROVISIONING_ATTACHMENT_MAX_KB', 10240),

    'workflows' => [

        'premium_full' => [
            'label' => 'Normal Service (API | MT | USSD Premium)',
            'steps' => [
                [
                    'key' => 'partner_resource_request',
                    'label' => 'Resource allocation request',
                    'from' => Dept::Partner->value,
                    'to' => Dept::VasSales->value,
                    'seq' => 1,
                    'form_fields' => [
                        ['name' => 'short_code', 'label' => 'Short code', 'type' => 'text', 'required' => true],
                        ['name' => 'data_sync_url', 'label' => 'Data Sync URL', 'type' => 'url', 'required' => false],
                    ],
                    'requires_attachment' => true,
                    'attachment_label' => 'Service activation letter',
                ],
                [
                    'key' => 'sales_to_operation',
                    'label' => 'Service configuration pack (Sales → Operation)',
                    'from' => Dept::VasSales->value,
                    'to' => Dept::VasOperation->value,
                    'seq' => 2,
                    'form_fields' => [
                        ['name' => 'crm_order', 'label' => 'CRM order', 'type' => 'text', 'required' => true],
                        ['name' => 'payer_party_type', 'label' => 'Payer party type', 'type' => 'text', 'required' => true],
                        ['name' => 'service_price', 'label' => 'Service price', 'type' => 'text', 'required' => true],
                        ['name' => 'tariff', 'label' => 'Tariff', 'type' => 'text', 'required' => true],
                        ['name' => 'packages', 'label' => 'Subscription packages', 'type' => 'packages', 'required' => true],
                        ['name' => 'free_trial_period', 'label' => 'Free trial period', 'type' => 'text', 'required' => false],
                    ],
                ],
                [
                    'key' => 'operation_to_ma',
                    'label' => 'Product creation request (Operation → MA)',
                    'from' => Dept::VasOperation->value,
                    'to' => Dept::Ma->value,
                    'seq' => 3,
                    'parallel_group' => 'config_fanout',
                    'form_fields' => [],
                ],
                [
                    'key' => 'operation_to_billing',
                    'label' => 'Billing configuration request (Operation → IS Billing)',
                    'from' => Dept::VasOperation->value,
                    'to' => Dept::IsBilling->value,
                    'seq' => 4,
                    'parallel_group' => 'config_fanout',
                    'form_fields' => [],
                ],
                [
                    'key' => 'ma_to_operation',
                    'label' => 'MA configuration completed',
                    'from' => Dept::Ma->value,
                    'to' => Dept::VasOperation->value,
                    'seq' => 5,
                    'parallel_group' => 'config_complete',
                    'form_fields' => [
                        ['name' => 'service_id', 'label' => 'Service ID', 'type' => 'text', 'required' => true],
                        ['name' => 'product_id', 'label' => 'Product ID', 'type' => 'text', 'required' => true],
                        ['name' => 'partner_id', 'label' => 'Partner ID', 'type' => 'text', 'required' => true],
                        ['name' => 'spid', 'label' => 'SPID', 'type' => 'text', 'required' => true],
                        ['name' => 'smsc_username', 'label' => 'SMSC username', 'type' => 'text', 'required' => true, 'sensitive' => true],
                        ['name' => 'smsc_password', 'label' => 'SMSC password', 'type' => 'password', 'required' => true, 'sensitive' => true],
                        ['name' => 'port', 'label' => 'Port', 'type' => 'text', 'required' => true, 'sensitive' => true],
                    ],
                    'stores_credentials' => true,
                ],
                [
                    'key' => 'billing_to_operation',
                    'label' => 'IS Billing configuration completed',
                    'from' => Dept::IsBilling->value,
                    'to' => Dept::VasOperation->value,
                    'seq' => 6,
                    'parallel_group' => 'config_complete',
                    'form_fields' => [],
                ],
                [
                    'key' => 'operation_to_sales',
                    'label' => 'Service configuration completed (Operation → Sales)',
                    'from' => Dept::VasOperation->value,
                    'to' => Dept::VasSales->value,
                    'seq' => 7,
                    'waits_for_parallel_group' => 'config_complete',
                    'form_fields' => [],
                ],
                [
                    'key' => 'sales_share_credentials',
                    'label' => 'Share credentials with partner',
                    'from' => Dept::VasSales->value,
                    'to' => Dept::Partner->value,
                    'seq' => 8,
                    'form_fields' => [],
                    'releases_credentials_to_partner' => true,
                ],
                [
                    'key' => 'partner_config_completed',
                    'label' => 'Partner configuration completed',
                    'from' => Dept::Partner->value,
                    'to' => Dept::VasSales->value,
                    'seq' => 9,
                    'form_fields' => [],
                ],
                [
                    'key' => 'sales_to_security',
                    'label' => 'Security assessment request',
                    'from' => Dept::VasSales->value,
                    'to' => Dept::IsSecurity->value,
                    'seq' => 10,
                    'form_fields' => [
                        ['name' => 'service_proposal', 'label' => 'Service proposal', 'type' => 'file', 'required' => true],
                        ['name' => 'service_proposal_note', 'label' => 'Proposal note', 'type' => 'textarea', 'required' => false],
                    ],
                ],
                [
                    'key' => 'security_clearance',
                    'label' => 'Security clearance confirmation',
                    'from' => Dept::IsSecurity->value,
                    'to' => Dept::VasSales->value,
                    'seq' => 11,
                    'form_fields' => [],
                ],
                [
                    'key' => 'go_live',
                    'label' => 'Go Live approval',
                    'from' => Dept::VasSales->value,
                    'to' => Dept::Partner->value,
                    'seq' => 12,
                    'form_fields' => [],
                    'is_go_live' => true,
                ],
            ],
        ],

    ],
];
