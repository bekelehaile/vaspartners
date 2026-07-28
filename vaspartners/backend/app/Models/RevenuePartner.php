<?php

namespace App\Models;

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
        'vas_service_id',
        'partner_name',
        'phone',
        'company_id',
        'created_by_user_id',
        'is_active',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (RevenuePartner $partner): void {
            if (! $partner->created_by_user_id && auth()->id()) {
                $partner->created_by_user_id = auth()->id();
            }
        });

        static::saving(function (RevenuePartner $partner): void {
            if ($partner->company_id) {
                $company = Company::query()->find($partner->company_id);
                if ($company) {
                    if (! filled($partner->partner_name)) {
                        $partner->partner_name = $company->name;
                    }
                    if (! filled($partner->phone) && filled($company->phone)) {
                        $partner->phone = PhoneNumber::normalizeNullable($company->phone);
                    }
                }
            }

            if (filled($partner->phone)) {
                $partner->phone = PhoneNumber::normalizeNullable($partner->phone);
            }

            // Auto-link company by phone when none is set; do not overwrite partner_name.
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
                    if (! filled($partner->partner_name)) {
                        $partner->partner_name = $company->name;
                    }
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

    public function vasService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'vas_service_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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
