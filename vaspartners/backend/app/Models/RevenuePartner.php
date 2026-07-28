<?php

namespace App\Models;

use App\Enums\RevenueServiceFamily;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenuePartner extends Model
{
    use HasUlids;

    protected $fillable = [
        'public_id',
        'service_id',
        'short_code',
        'service_family',
        'partner_name',
        'phone',
        'service_type',
        'company_id',
        'is_active',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saving(function (RevenuePartner $partner): void {
            if (filled($partner->phone)) {
                $partner->phone = PhoneNumber::normalizeNullable($partner->phone);
            }

            if (! $partner->company_id && filled($partner->phone)) {
                $company = Company::query()
                    ->whereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [(string) $partner->phone]
                    )
                    ->orderBy('id')
                    ->first();
                if ($company) {
                    $partner->company_id = $company->id;
                }
            }
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_family' => RevenueServiceFamily::class,
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(RevenueImportRow::class);
    }

    public function hasUsablePhone(): bool
    {
        return filled($this->phone) && (bool) preg_match('/^[97]\d{8}$/', (string) $this->phone);
    }
}
