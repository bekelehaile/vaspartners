<?php

namespace App\Services\Etrade;

use App\Models\AppSetting;
use App\Models\Contact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Per-contact caps for partner-initiated ERCA TIN searches (preview + submit).
 */
class ErcaPortalSearchGuard
{
    public function assertCanSearch(Contact $contact, string $tin): void
    {
        if (! AppSetting::tinRateLimitEnabled()) {
            return;
        }

        $contactId = (int) $contact->id;
        $hourLimit = AppSetting::tinLookupPerHour();
        $dayLimit = AppSetting::tinLookupPerDay();
        $uniqueLimit = AppSetting::tinUniqueTinsPerDay();

        $hourCount = (int) Cache::get($this->contactHourKey($contactId), 0);
        if ($hourCount >= $hourLimit) {
            $minutes = max(1, 60 - (int) now()->format('i'));
            throw ValidationException::withMessages([
                'company_tin' => "You have reached the hourly TIN search limit ({$hourLimit}/hour). Try again in about {$minutes} minute(s).",
            ]);
        }

        $dayCount = (int) Cache::get($this->contactDayKey($contactId), 0);
        if ($dayCount >= $dayLimit) {
            $hours = max(1, (int) ceil(now()->endOfDay()->diffInMinutes(now()) / 60));
            throw ValidationException::withMessages([
                'company_tin' => "You have reached the daily TIN search limit ({$dayLimit}/day). Try again in about {$hours} hour(s).",
            ]);
        }

        /** @var list<string> $unique */
        $unique = Cache::get($this->contactUniqueDayKey($contactId), []);
        if (! is_array($unique)) {
            $unique = [];
        }
        if (! in_array($tin, $unique, true) && count($unique) >= $uniqueLimit) {
            $hours = max(1, (int) ceil(now()->endOfDay()->diffInMinutes(now()) / 60));
            throw ValidationException::withMessages([
                'company_tin' => "You have searched too many different TIN numbers today (max {$uniqueLimit}). Try again in about {$hours} hour(s).",
            ]);
        }
    }

    public function recordSearch(Contact $contact, string $tin): void
    {
        $contactId = (int) $contact->id;

        $hourKey = $this->contactHourKey($contactId);
        Cache::put($hourKey, (int) Cache::get($hourKey, 0) + 1, now()->addHour());

        $dayKey = $this->contactDayKey($contactId);
        Cache::put($dayKey, (int) Cache::get($dayKey, 0) + 1, now()->endOfDay());

        $uniqueKey = $this->contactUniqueDayKey($contactId);
        /** @var list<string> $unique */
        $unique = Cache::get($uniqueKey, []);
        if (! is_array($unique)) {
            $unique = [];
        }
        if (! in_array($tin, $unique, true)) {
            $unique[] = $tin;
            Cache::put($uniqueKey, array_values($unique), now()->endOfDay());
        }
    }

    protected function contactHourKey(int $contactId): string
    {
        return 'erca:portal-search:hour:'.$contactId.':'.now()->format('YmdH');
    }

    protected function contactDayKey(int $contactId): string
    {
        return 'erca:portal-search:day:'.$contactId.':'.now()->format('Ymd');
    }

    protected function contactUniqueDayKey(int $contactId): string
    {
        return 'erca:portal-search:unique:'.$contactId.':'.now()->format('Ymd');
    }
}
