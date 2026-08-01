<?php

namespace App\Models;

use App\Support\PartnerCompanyNameMatcher;
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
            // Company is our validated portal record. Partner name comes from finance — never overwrite it.
            if ($partner->company_id) {
                $company = Company::query()->find($partner->company_id);
                if ($company && ! filled($partner->phone) && filled($company->revenuePhone())) {
                    $partner->phone = PhoneNumber::normalizeNullable($company->revenuePhone());
                }
            }

            if (filled($partner->phone)) {
                $partner->phone = PhoneNumber::normalizeNullable($partner->phone);
            }

            // Auto-link only when phone matches AND partner_name ≈ company name.
            // Abay contact phones are often reused across unrelated partners.
            if (! $partner->company_id && filled($partner->phone)) {
                $candidates = Company::query()
                    ->where(function ($q) use ($partner): void {
                        $q->whereRaw(
                            "RIGHT(REGEXP_REPLACE(COALESCE(revenue_phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                            [(string) $partner->phone],
                        )->orWhereRaw(
                            "RIGHT(REGEXP_REPLACE(COALESCE(claim_phone, phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                            [(string) $partner->phone],
                        );
                    })
                    ->orderBy('id')
                    ->get(['id', 'name', 'phone', 'claim_phone', 'revenue_phone']);

                $match = $candidates->first(
                    fn (Company $company) => PartnerCompanyNameMatcher::matches(
                        $partner->partner_name,
                        $company->name,
                    )
                );

                if ($match) {
                    $partner->company_id = $match->id;
                } elseif ($candidates->count() === 1 && RevenuePartner::query()
                    ->where('is_active', true)
                    ->where('id', '!=', (int) ($partner->id ?: 0))
                    ->whereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                        [(string) $partner->phone]
                    )
                    ->doesntExist()) {
                    // Unique phone on companies + unique among other partners.
                    $partner->company_id = $candidates->first()->id;
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
