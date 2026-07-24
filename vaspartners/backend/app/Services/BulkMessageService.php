<?php

namespace App\Services;

use App\Enums\BulkMessageStatus;
use App\Enums\BulkMessageRecipientStatus;
use App\Jobs\ProcessBulkMessageJob;
use App\Jobs\SendBulkMessageRecipientJob;
use App\Models\Company;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\ReaderInterface;
use Throwable;

class BulkMessageService
{
    public function __construct(
        private readonly SmsService $sms,
    ) {}

    /**
     * Create a campaign from an Excel/CSV upload and build recipient rows.
     *
     * Spreadsheet columns (header row, case-insensitive):
     * - phone / mobile / company_phone (preferred)
     * - tin / company_tin (optional lookup)
     * - name / company / company_name (optional label)
     */
    public function createFromUpload(User $actor, string $title, string $message, UploadedFile $file): BulkMessage
    {
        $message = trim($message);
        $title = trim($title);

        if ($title === '' || $message === '') {
            throw ValidationException::withMessages([
                'title' => 'Title and message are required.',
            ]);
        }

        if (mb_strlen($message) > 640) {
            throw ValidationException::withMessages([
                'message' => 'Message must be 640 characters or fewer.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Upload an Excel (.xlsx) or CSV file.',
            ]);
        }

        $storedPath = $file->storeAs(
            'company-sms/'.now()->format('Y/m'),
            Str::ulid().'.'.$extension,
            'local',
        );

        return $this->createFromStoredPath(
            $actor,
            $title,
            $message,
            $storedPath,
            $file->getClientOriginalName(),
            $extension,
        );
    }

    public function createFromStoredPath(
        User $actor,
        string $title,
        string $message,
        string $storedPath,
        string $originalName,
        ?string $extension = null,
    ): BulkMessage {
        $title = trim($title);
        $message = trim($message);

        if ($title === '' || $message === '') {
            throw ValidationException::withMessages([
                'title' => 'Title and message are required.',
            ]);
        }

        if (mb_strlen($message) > 640) {
            throw ValidationException::withMessages([
                'message' => 'Message must be 640 characters or fewer.',
            ]);
        }

        $extension = strtolower($extension ?: pathinfo($storedPath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            Storage::disk('local')->delete($storedPath);
            throw ValidationException::withMessages([
                'file' => 'Upload an Excel (.xlsx) or CSV file.',
            ]);
        }

        $absolute = Storage::disk('local')->path($storedPath);
        if (! is_file($absolute)) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded file could not be read.',
            ]);
        }

        $rows = $this->readSpreadsheet($absolute, $extension);
        if ($rows === []) {
            Storage::disk('local')->delete($storedPath);
            throw ValidationException::withMessages([
                'file' => 'No data rows found. Include a header row with phone and/or tin.',
            ]);
        }

        return DB::transaction(function () use ($actor, $title, $message, $originalName, $storedPath, $rows) {
            $campaign = BulkMessage::query()->create([
                'title' => $title,
                'message' => $message,
                'source_filename' => $originalName,
                'source_path' => $storedPath,
                'status' => BulkMessageStatus::Draft,
                'created_by_user_id' => $actor->id,
            ]);

            $seenPhones = [];
            foreach ($rows as $index => $row) {
                $recipient = $this->mapRowToRecipient($campaign, $row, $index + 2, $seenPhones);
                if ($recipient !== null) {
                    BulkMessageRecipient::query()->create($recipient);
                }
            }

            $campaign->refreshCounts();

            if ($campaign->total_count === 0) {
                throw ValidationException::withMessages([
                    'file' => 'No valid company phones found. Use last-9 mobile digits (9xxxxxxxx / 7xxxxxxxx) or a matching TIN.',
                ]);
            }

            return $campaign->fresh('recipients');
        });
    }

    public function queue(BulkMessage $campaign): void
    {
        if (! in_array($campaign->status, [BulkMessageStatus::Draft, BulkMessageStatus::Completed, BulkMessageStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'campaign' => 'This campaign is already queued or sending.',
            ]);
        }

        $pending = $campaign->recipients()
            ->whereIn('status', [
                BulkMessageRecipientStatus::Pending->value,
                BulkMessageRecipientStatus::Failed->value,
            ])
            ->count();

        if ($pending === 0) {
            throw ValidationException::withMessages([
                'campaign' => 'There are no pending or failed recipients to send.',
            ]);
        }

        // Re-queue failed as pending for a full send / resend.
        $campaign->recipients()
            ->where('status', BulkMessageRecipientStatus::Failed->value)
            ->update([
                'status' => BulkMessageRecipientStatus::Pending->value,
                'error' => null,
            ]);

        $campaign->forceFill([
            'status' => BulkMessageStatus::Queued,
            'queued_at' => now(),
            'completed_at' => null,
        ])->save();

        ProcessBulkMessageJob::dispatch($campaign->id);
    }

    public function resendFailed(BulkMessage $campaign): void
    {
        $failed = $campaign->recipients()
            ->where('status', BulkMessageRecipientStatus::Failed->value)
            ->count();

        if ($failed === 0) {
            throw ValidationException::withMessages([
                'campaign' => 'No failed recipients to re-send.',
            ]);
        }

        $campaign->recipients()
            ->where('status', BulkMessageRecipientStatus::Failed->value)
            ->update([
                'status' => BulkMessageRecipientStatus::Pending->value,
                'error' => null,
            ]);

        $campaign->forceFill([
            'status' => BulkMessageStatus::Queued,
            'queued_at' => now(),
            'completed_at' => null,
        ])->save();
        $campaign->refreshCounts();

        ProcessBulkMessageJob::dispatch($campaign->id);
    }

    public function dispatchPending(BulkMessage $campaign): void
    {
        $campaign->forceFill(['status' => BulkMessageStatus::Processing])->save();

        $ids = $campaign->recipients()
            ->where('status', BulkMessageRecipientStatus::Pending->value)
            ->whereNotNull('phone_normalized')
            ->pluck('id');

        foreach ($ids as $id) {
            SendBulkMessageRecipientJob::dispatch((int) $id);
        }

        if ($ids->isEmpty()) {
            $this->maybeComplete($campaign->fresh());
        }
    }

    public function sendRecipient(BulkMessageRecipient $recipient): void
    {
        $campaign = $recipient->bulkMessage ?? $recipient->campaign;
        if (! $campaign) {
            return;
        }

        $phone = (string) $recipient->phone_normalized;
        if ($phone === '' || ! preg_match('/^(9|7)\d{8}$/', $phone)) {
            $recipient->forceFill([
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Invalid mobile (need local 9/7 + 8 digits).',
                'attempts' => $recipient->attempts + 1,
            ])->save();
            $this->afterRecipientUpdate($campaign);

            return;
        }

        $recipient->forceFill(['attempts' => $recipient->attempts + 1])->save();

        try {
            $ok = $this->sms->sendNow($phone, $campaign->message);
            if (! $ok) {
                $recipient->forceFill([
                    'status' => BulkMessageRecipientStatus::Failed,
                    'error' => 'SMS gateway rejected or timed out.',
                ])->save();
            } else {
                $recipient->forceFill([
                    'status' => BulkMessageRecipientStatus::Sent,
                    'error' => null,
                    'sent_at' => now(),
                ])->save();
            }
        } catch (Throwable $e) {
            $recipient->forceFill([
                'status' => BulkMessageRecipientStatus::Failed,
                'error' => Str::limit($e->getMessage(), 480),
            ])->save();
        }

        $this->afterRecipientUpdate($campaign->fresh());
    }

    public function maybeComplete(BulkMessage $campaign): void
    {
        $remaining = $campaign->recipients()
            ->where('status', BulkMessageRecipientStatus::Pending->value)
            ->count();

        if ($remaining > 0) {
            return;
        }

        $campaign->refreshCounts();
        $campaign->forceFill([
            'status' => $campaign->failed_count > 0 && $campaign->sent_count === 0
                ? BulkMessageStatus::Failed
                : BulkMessageStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function templateCsv(): string
    {
        return "phone,tin,name\n930011756,0012345678,Example Company PLC\n";
    }

    /**
     * @return list<array{phone:?string,tin:?string,name:?string}>
     */
    protected function readSpreadsheet(string $absolutePath, string $extension): array
    {
        $reader = $this->makeReader($extension);
        $reader->open($absolutePath);

        $headers = null;
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $values = [];
                foreach ($row->getCells() as $cell) {
                    $values[] = trim((string) $cell->getValue());
                }

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($values);
                    continue;
                }

                if ($this->rowIsEmpty($values)) {
                    continue;
                }

                $mapped = $this->mapAssoc($headers, $values);
                $rows[] = [
                    'phone' => $mapped['phone'] ?? null,
                    'tin' => $mapped['tin'] ?? null,
                    'name' => $mapped['name'] ?? null,
                ];
            }
            break; // first sheet only
        }

        $reader->close();

        return $rows;
    }

    protected function makeReader(string $extension): ReaderInterface
    {
        return match ($extension) {
            'csv' => new CsvReader,
            'xlsx', 'xls' => new XlsxReader,
            default => throw ValidationException::withMessages([
                'file' => 'Unsupported file type.',
            ]),
        };
    }

    /**
     * @param  list<string>  $headerCells
     * @return array<string, int>
     */
    protected function normalizeHeaders(array $headerCells): array
    {
        $map = [];
        foreach ($headerCells as $index => $label) {
            $key = Str::of($label)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            $alias = match ($key) {
                'phone', 'mobile', 'msisdn', 'company_phone', 'tel', 'telephone' => 'phone',
                'tin', 'company_tin', 'tax_id', 'tax_identification_number' => 'tin',
                'name', 'company', 'company_name', 'partner' => 'name',
                default => null,
            };
            if ($alias !== null && ! isset($map[$alias])) {
                $map[$alias] = $index;
            }
        }

        if (! isset($map['phone']) && ! isset($map['tin'])) {
            throw ValidationException::withMessages([
                'file' => 'Header row must include a phone and/or tin column.',
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $headers
     * @param  list<string>  $values
     * @return array{phone:?string,tin:?string,name:?string}
     */
    protected function mapAssoc(array $headers, array $values): array
    {
        $out = ['phone' => null, 'tin' => null, 'name' => null];
        foreach ($headers as $field => $index) {
            $out[$field] = $values[$index] ?? null;
        }

        return $out;
    }

    /**
     * @param  list<string>  $values
     */
    protected function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{phone:?string,tin:?string,name:?string}  $row
     * @param  array<string, true>  $seenPhones
     * @return array<string, mixed>|null
     */
    protected function mapRowToRecipient(BulkMessage $campaign, array $row, int $rowNumber, array &$seenPhones): ?array
    {
        $company = null;
        $tin = filled($row['tin'] ?? null) ? trim((string) $row['tin']) : null;
        $nameHint = filled($row['name'] ?? null) ? trim((string) $row['name']) : null;
        $phoneRaw = filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null;

        if ($tin !== null) {
            $company = Company::query()->where('tin', $tin)->first();
        }

        $normalizedFromFile = $phoneRaw !== null ? $this->sms->normalizePhone($phoneRaw) : '';

        if (! $company && $normalizedFromFile !== '' && strlen($normalizedFromFile) === 9) {
            $company = $this->findCompanyByLastNine($normalizedFromFile);
        }

        $sendPhone = null;
        $phoneSourceRaw = $phoneRaw;

        if ($company && filled($company->phone)) {
            $sendPhone = $this->sms->normalizePhone($company->phone);
            $phoneSourceRaw = $company->phone;
        } elseif ($normalizedFromFile !== '') {
            $sendPhone = $normalizedFromFile;
        }

        if ($sendPhone === null || $sendPhone === '' || strlen($sendPhone) !== 9) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => $company?->id,
                'phone_raw' => $phoneSourceRaw,
                'phone_normalized' => $sendPhone ?: null,
                'company_name' => $company?->name ?? $nameHint,
                'company_tin' => $company?->tin ?? $tin,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'No usable company mobile (last 9 digits).',
            ];
        }

        if (isset($seenPhones[$sendPhone])) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => $company?->id,
                'phone_raw' => $phoneSourceRaw,
                'phone_normalized' => $sendPhone,
                'company_name' => $company?->name ?? $nameHint,
                'company_tin' => $company?->tin ?? $tin,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Duplicate phone in this upload.',
            ];
        }
        $seenPhones[$sendPhone] = true;

        if (! preg_match('/^(9|7)\d{8}$/', $sendPhone) || ! $this->sms->ensurePhoneIsLocal($sendPhone)) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => $company?->id,
                'phone_raw' => $phoneSourceRaw,
                'phone_normalized' => $sendPhone,
                'company_name' => $company?->name ?? $nameHint,
                'company_tin' => $company?->tin ?? $tin,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Phone is not a local Ethio telecom mobile.',
            ];
        }

        if (! $company) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => null,
                'phone_raw' => $phoneSourceRaw,
                'phone_normalized' => $sendPhone,
                'company_name' => $nameHint,
                'company_tin' => $tin,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'No company matched for this phone/TIN.',
            ];
        }

        return [
            'campaign_id' => $campaign->id,
            'company_id' => $company->id,
            'phone_raw' => $phoneSourceRaw,
            'phone_normalized' => $sendPhone,
            'company_name' => $company->name,
            'company_tin' => $company->tin,
            'row_number' => $rowNumber,
            'status' => BulkMessageRecipientStatus::Pending,
            'error' => null,
        ];
    }

    protected function findCompanyByLastNine(string $lastNine): ?Company
    {
        return Company::query()
            ->whereRaw(
                "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                [$lastNine]
            )
            ->orderBy('id')
            ->first();
    }

    protected function afterRecipientUpdate(BulkMessage $campaign): void
    {
        $campaign->refreshCounts();
        $this->maybeComplete($campaign);
    }
}
