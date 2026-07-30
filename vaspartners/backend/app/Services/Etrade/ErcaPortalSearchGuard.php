<?php

namespace App\Services\Etrade;

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
        $contactId = (int) $contact->id;
        $hourLimit = max(1, (int) config('services.etrade.portal_lookups_per_hour', 12));
        $dayLimit = max(1, (int) config('services.etrade.portal_lookups_per_day', 20));
        $uniqueLimit = max(1, (int) config('services.etrade.portal_unique_tins_per_day', 8));

        $hourCount = (int) Cache::get($this->contactHourKey($contactId), 0);
        if ($hourCount >= $hourLimit) {
            throw ValidationException::withMessages([
                'company_tin' => 'You have reached the hourly TIN number search limit. Try again later.',
            ]);
        }

        $dayCount = (int) Cache::get($this->contactDayKey($contactId), 0);
        if ($dayCount >= $dayLimit) {
            throw ValidationException::withMessages([
                'company_tin' => 'You have reached the daily TIN number search limit. Try again tomorrow.',
            ]);
        }

        /** @var list<string> $unique */
        $unique = Cache::get($this->contactUniqueDayKey($contactId), []);
        if (! is_array($unique)) {
            $unique = [];
        }
        if (! in_array($tin, $unique, true) && count($unique) >= $uniqueLimit) {
            throw ValidationException::withMessages([
                'company_tin' => 'You have searched too many different TIN numbers today. Try again tomorrow.',
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
