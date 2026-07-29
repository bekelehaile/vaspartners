<?php

namespace App\Console\Commands;

use App\Services\RevenuePartnerPhoneSyncService;
use Illuminate\Console\Command;

class SyncRevenuePartnerPhonesCommand extends Command
{
    protected $signature = 'vas:sync-revenue-partner-phones
        {path : JSON or CSV path (Service ID + Phone)}
        {--overwrite : Replace phones that are already set}
        {--create-missing : Create revenue partners for Service IDs / short codes not in the DB}
        {--vas-service= : Default catalog service id or slug when creating (default: api)}
        {--account-manager= : Assign matched partners to this user id or name}';

    protected $description = 'Fill revenue partner phones by Service ID / Short code from a JSON or CSV file';

    public function handle(RevenuePartnerPhoneSyncService $sync): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rows = str_ends_with(strtolower($path), '.json')
            ? $this->readJson($path)
            : $this->readCsv($path);

        if ($rows === []) {
            $this->warn('No rows to sync.');

            return self::SUCCESS;
        }

        $amId = $this->resolveAccountManagerId();
        if ($this->option('account-manager') && ! $amId) {
            $this->error('Account manager not found: '.$this->option('account-manager'));

            return self::FAILURE;
        }

        $defaultVas = $this->resolveDefaultVasServiceId();
        if ($this->option('create-missing') && $this->option('vas-service') && ! $defaultVas) {
            $this->error('Catalog service not found: '.$this->option('vas-service'));

            return self::FAILURE;
        }

        $stats = $sync->syncFromRows(
            $rows,
            (bool) $this->option('overwrite'),
            $amId,
            (bool) $this->option('create-missing'),
            $defaultVas,
        );

        $this->info("Processed {$stats['total']} rows.");
        $this->line("  Updated phones: {$stats['updated']}");
        $this->line("  Already had phone: {$stats['already_had_phone']}");
        $this->line("  Created partners: {$stats['created']}");
        $this->line("  Assigned account manager: {$stats['assigned_am']}");
        $this->line("  Unknown account manager: {$stats['unknown_am']}");
        $this->line("  Skipped NA/blank: {$stats['skipped_na']}");
        $this->line("  Invalid phone: {$stats['invalid_phone']}");
        $this->line("  Not found: {$stats['not_found']}");

        if (($stats['created_keys'] ?? []) !== []) {
            $this->info('Created: '.implode(', ', array_slice($stats['created_keys'], 0, 40)));
        }
        if ($stats['not_found_keys'] !== []) {
            $this->warn('Not found keys: '.implode(', ', array_slice($stats['not_found_keys'], 0, 30)));
        }
        if ($stats['invalid_keys'] !== []) {
            $this->warn('Invalid phone keys: '.implode(', ', $stats['invalid_keys']));
        }
        if (($stats['unknown_am_names'] ?? []) !== []) {
            $this->warn('Unknown AM names: '.implode(', ', $stats['unknown_am_names']));
        }

        return self::SUCCESS;
    }

    protected function resolveAccountManagerId(): ?int
    {
        $raw = trim((string) $this->option('account-manager'));
        if ($raw === '') {
            return null;
        }

        return app(RevenuePartnerPhoneSyncService::class)->resolveAccountManagerId($raw);
    }

    protected function resolveDefaultVasServiceId(): ?int
    {
        $raw = trim((string) $this->option('vas-service'));
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return \App\Models\Service::query()->whereKey((int) $raw)->value('id');
        }

        return \App\Models\Service::query()
            ->where('slug', $raw)
            ->orWhereRaw('lower(name) = ?', [mb_strtolower($raw)])
            ->value('id');
    }

    /**
     * @return list<array{service_id: ?string, short_code: ?string, phone: ?string, account_manager: ?string}>
     */
    protected function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'service_id' => $row['service_id'] ?? $row['Service ID'] ?? null,
                'short_code' => $row['short_code'] ?? $row['Short code'] ?? null,
                'phone' => $row['phone'] ?? $row['phone_raw'] ?? $row['Phone Number'] ?? $row['Phone'] ?? null,
                'account_manager' => $row['account_manager'] ?? $row['am'] ?? $row['AM'] ?? null,
                'partner_name' => $row['partner_name'] ?? $row['Partner Name'] ?? $row['partner name'] ?? null,
                'service' => $row['service'] ?? $row['Service'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{service_id: ?string, short_code: ?string, phone: ?string, account_manager: ?string}>
     */
    protected function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        $header = null;
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data);

                continue;
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = $data[$i] ?? null;
            }
            $rows[] = [
                'service_id' => $assoc['service_id'] ?? $assoc['service id'] ?? null,
                'short_code' => $assoc['short_code'] ?? $assoc['short code'] ?? null,
                'phone' => $assoc['phone']
                    ?? $assoc['phone number']
                    ?? $assoc['phone_number']
                    ?? $assoc['phone no.']
                    ?? $assoc['phone no']
                    ?? null,
                'account_manager' => $assoc['account_manager']
                    ?? $assoc['account manager']
                    ?? $assoc['am']
                    ?? null,
                'partner_name' => $assoc['partner_name']
                    ?? $assoc['partner name']
                    ?? null,
                'service' => $assoc['service'] ?? $assoc['catalog_service'] ?? null,
            ];
        }
        fclose($fh);

        return $rows;
    }
}
