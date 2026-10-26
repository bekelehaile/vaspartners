<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProvisioningDepartment: string implements HasLabel
{
    case Partner = 'partner';
    case VasSales = 'vas_sales';
    case VasOperation = 'vas_operation';
    case IsBilling = 'is_billing';
    case Ma = 'ma';
    case IsSecurity = 'is_security';

    public function label(): string
    {
        return match ($this) {
            self::Partner => 'VAS Partner',
            self::VasSales => 'VAS Sales',
            self::VasOperation => 'VAS Operation',
            self::IsBilling => 'IS Billing',
            self::Ma => 'MA',
            self::IsSecurity => 'IS Security',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function roleName(): string
    {
        return $this->value;
    }
}
