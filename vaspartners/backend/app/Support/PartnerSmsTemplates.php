<?php

namespace App\Support;

/**
 * Catalog of partner-facing SMS templates (editable in App settings).
 */
final class PartnerSmsTemplates
{
    public const STORAGE_KEY = 'partner_sms_templates';

    /**
     * @return array<string, array{label: string, group: string, help: string, default_source: string}>
     */
    public static function catalog(): array
    {
        return [
            // Service requests
            'ticket_submitted' => [
                'label' => 'Request submitted',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}, {requisition}',
                'default_source' => 'templates.ticket_submitted',
            ],
            'ticket_in_progress' => [
                'label' => 'Request in progress',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}',
                'default_source' => 'templates.ticket_in_progress',
            ],
            'documents_need_attention' => [
                'label' => 'Documents need attention',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {note}',
                'default_source' => 'templates.documents_need_attention',
            ],
            'documents_passed' => [
                'label' => 'Documents accepted',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}',
                'default_source' => 'templates.documents_passed',
            ],
            'documents_incomplete_auto' => [
                'label' => 'Documents incomplete (auto return)',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}',
                'default_source' => 'templates.documents_incomplete_auto',
            ],
            'ticket_completed' => [
                'label' => 'Service proposal accepted (final approval)',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}',
                'default_source' => 'templates.ticket_completed',
            ],
            'ticket_rejected' => [
                'label' => 'Request not approved',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}, {note}',
                'default_source' => 'templates.ticket_rejected',
            ],
            'ticket_closed' => [
                'label' => 'Request closed',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {service}',
                'default_source' => 'templates.ticket_closed',
            ],
            'ticket_message' => [
                'label' => 'New message on request',
                'group' => 'Service requests',
                'help' => '{contact_name}, {tt_number}, {note}',
                'default_source' => 'templates.ticket_message',
            ],

            // Company & membership
            'profile_completed' => [
                'label' => 'Company profile completed',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}',
                'default_source' => 'templates.profile_completed',
            ],
            'company_profile_approved' => [
                'label' => 'Company approved',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}',
                'default_source' => 'templates.company_profile_approved',
            ],
            'company_profile_rejected' => [
                'label' => 'Company needs updates',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {note}',
                'default_source' => 'templates.company_profile_rejected',
            ],
            'company_tin_validated' => [
                'label' => 'TIN confirmed',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}',
                'default_source' => 'templates.company_tin_validated',
            ],
            'company_tin_invalid' => [
                'label' => 'TIN invalid / update required',
                'group' => 'Company & membership',
                'help' => '{company_name}, {portal_url}',
                'default_source' => 'templates.company_tin_invalid',
            ],
            'company_tin_not_found_erca' => [
                'label' => 'TIN not found in ERCA',
                'group' => 'Company & membership',
                'help' => '{company_tin}, {company_name}, {portal_url}',
                'default_source' => 'templates.company_tin_not_found_erca',
            ],
            'company_erca_name_mismatch' => [
                'label' => 'ERCA name mismatch',
                'group' => 'Company & membership',
                'help' => '{company_name}, {company_tin}, {legal_name}, {portal_url}',
                'default_source' => 'templates.company_erca_name_mismatch',
            ],
            'company_membership_requested' => [
                'label' => 'Membership requested (owner)',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {applicant_name}, {company_name}',
                'default_source' => 'templates.company_membership_requested',
            ],
            'company_attach_approved' => [
                'label' => 'Join company approved',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}',
                'default_source' => 'templates.company_attach_approved',
            ],
            'company_attach_rejected' => [
                'label' => 'Join company rejected',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {note}',
                'default_source' => 'templates.company_attach_rejected',
            ],
            'company_detach_approved' => [
                'label' => 'Leave company approved',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}',
                'default_source' => 'templates.company_detach_approved',
            ],
            'company_detach_rejected' => [
                'label' => 'Leave company rejected',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {note}',
                'default_source' => 'templates.company_detach_rejected',
            ],
            'company_member_added' => [
                'label' => 'Member added',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {portal_url}',
                'default_source' => 'templates.company_member_added',
            ],
            'company_member_left' => [
                'label' => 'Member left',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {applicant_name}, {company_name}',
                'default_source' => 'templates.company_member_left',
            ],
            'company_transfer_approved' => [
                'label' => 'Ownership transfer approved',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {applicant_name}',
                'default_source' => 'templates.company_transfer_approved',
            ],
            'company_transfer_rejected' => [
                'label' => 'Ownership transfer rejected',
                'group' => 'Company & membership',
                'help' => '{contact_name}, {company_name}, {note}',
                'default_source' => 'templates.company_transfer_rejected',
            ],

            // Subscription & activation
            'subscription_contract_signed' => [
                'label' => 'Contract signed',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {service}, {company_name}, {contract_signed_at}',
                'default_source' => 'templates.subscription_contract_signed',
            ],
            'subscription_provisioning_invite' => [
                'label' => 'Service activation invite',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {service}, {company_name}, {portal_url}',
                'default_source' => 'templates.subscription_provisioning_invite',
            ],
            'subscription_renewal_reminder' => [
                'label' => 'Contract renewal reminder',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {service}, {company_name}, {renewal_date}, {days_remaining}, {contract_signed_at}, {portal_url}',
                'default_source' => 'templates.subscription_renewal_reminder',
            ],
            'provisioning_message' => [
                'label' => 'Service activation chat message',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {prv_number}, {service}, {note}, {portal_url}',
                'default_source' => 'templates.provisioning_message',
            ],
            'provisioning.credentials_ready' => [
                'label' => 'Credentials ready',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {company}, {service}, {prv_number}, {portal_url}',
                'default_source' => 'provisioning.provisioning.credentials_ready',
            ],
            'provisioning.go_live' => [
                'label' => 'Go Live',
                'group' => 'Subscription & activation',
                'help' => '{contact_name}, {company}, {service}, {prv_number}, {portal_url}',
                'default_source' => 'provisioning.provisioning.go_live',
            ],
        ];
    }

    public static function defaultBody(string $templateKey): string
    {
        $meta = self::catalog()[$templateKey] ?? null;
        if ($meta === null) {
            return '';
        }

        $source = $meta['default_source'];

        // Dotted provisioning keys live as literal keys under notifications.provisioning.
        if (str_starts_with($source, 'provisioning.provisioning.')) {
            $dotted = substr($source, strlen('provisioning.'));
            $templates = config('notifications.provisioning', []);

            return trim((string) (is_array($templates) ? ($templates[$dotted] ?? '') : ''));
        }

        if (str_starts_with($source, 'templates.')) {
            $key = substr($source, strlen('templates.'));

            return trim((string) config('notifications.templates.'.$key, ''));
        }

        return trim((string) config('notifications.'.$source, ''));
    }

    /**
     * @return array<string, string>
     */
    public static function defaultBodies(): array
    {
        $out = [];
        foreach (array_keys(self::catalog()) as $key) {
            $out[$key] = self::defaultBody($key);
        }

        return $out;
    }

    /**
     * Form-safe field name (dots → double underscore).
     */
    public static function formField(string $templateKey): string
    {
        return 'sms_'.str_replace('.', '__', $templateKey);
    }

    public static function templateKeyFromFormField(string $field): ?string
    {
        if (! str_starts_with($field, 'sms_')) {
            return null;
        }

        return str_replace('__', '.', substr($field, 4));
    }
}
