<?php

namespace App\Enums;

enum CompanyMemberPermission: string
{
    case CreateServiceRequests = 'create_service_requests';
    case ManageMembershipRequests = 'manage_membership_requests';
    case EditCompanyProfile = 'edit_company_profile';

    public function label(): string
    {
        return match ($this) {
            self::CreateServiceRequests => 'Create service requests',
            self::ManageMembershipRequests => 'Approve membership requests',
            self::EditCompanyProfile => 'Edit company profile',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateServiceRequests => 'Submit new VAS subscriptions and service changes.',
            self::ManageMembershipRequests => 'Approve or reject partners asking to join the company.',
            self::EditCompanyProfile => 'Update company details while waiting for admin approval.',
        };
    }

    /**
     * Default grants for newly linked non-owner members.
     *
     * @return list<string>
     */
    public static function defaultsForMember(): array
    {
        return [self::CreateServiceRequests->value];
    }

    /**
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function catalog(): array
    {
        return array_map(
            fn (self $p) => [
                'key' => $p->value,
                'label' => $p->label(),
                'description' => $p->description(),
            ],
            self::cases(),
        );
    }
}
