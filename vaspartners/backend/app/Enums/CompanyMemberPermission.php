<?php

namespace App\Enums;

enum CompanyMemberPermission: string
{
    case CreateSubscriptions = 'create_subscriptions';
    case ManageServices = 'manage_services';
    case ManageMembershipRequests = 'manage_membership_requests';
    case EditCompanyProfile = 'edit_company_profile';

    public function label(): string
    {
        return match ($this) {
            self::CreateSubscriptions => 'New VAS subscriptions',
            self::ManageServices => 'Manage service',
            self::ManageMembershipRequests => 'Approve membership requests',
            self::EditCompanyProfile => 'Edit company profile',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateSubscriptions => 'Start new VAS subscriptions for the company.',
            self::ManageServices => 'Change, renew, or terminate existing services, and request non-subscription services.',
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
        return [
            self::CreateSubscriptions->value,
            self::ManageServices->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * Expand legacy permission keys stored before the subscribe/manage split.
     *
     * @param  list<string>|array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function normalizeStored(array $permissions): array
    {
        $out = [];
        foreach ($permissions as $raw) {
            $key = (string) $raw;
            if ($key === 'create_service_requests') {
                $out[] = self::CreateSubscriptions->value;
                $out[] = self::ManageServices->value;

                continue;
            }
            $out[] = $key;
        }

        return array_values(array_unique($out));
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
