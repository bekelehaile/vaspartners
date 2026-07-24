<?php

namespace App\Services;

use App\Enums\BulkMessageStatus;
use App\Enums\BulkMessageRecipientStatus;
use App\Jobs\ImportBulkMessageJob;
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
     * Spreadsheet needs a phone column only (header: phone / mobile / company_phone).
     * Company name, TIN, and send number are loaded from the matched company record.
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
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            Storage::disk('local')->delete($storedPath);
            throw ValidationException::withMessages([
                'file' => 'Upload an Excel (.xlsx) or CSV file.',
            ]);
        }

        if (! Storage::disk('local')->exists($storedPath)) {
            throw ValidationException::withMessages([
                'file' => 'Uploaded file could not be read.',
            ]);
        }

        $campaign = BulkMessage::query()->create([
            'title' => $title,
            'message' => $message,
            'source_filename' => $originalName,
            'source_path' => $storedPath,
            'status' => BulkMessageStatus::Importing,
            'created_by_user_id' => $actor->id,
        ]);

        ImportBulkMessageJob::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * Parse the stored spreadsheet and build recipient rows (runs on the queue).
     */
    public function processImport(BulkMessage $campaign): void
    {
        if ($campaign->status !== BulkMessageStatus::Importing) {
            return;
        }

        $storedPath = (string) $campaign->source_path;
        $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true) || ! Storage::disk('local')->exists($storedPath)) {
            $this->failImport($campaign);

            return;
        }

        $absolute = Storage::disk('local')->path($storedPath);

        try {
            $rows = $this->readSpreadsheet($absolute, $extension === 'xls' ? 'xlsx' : $extension);
        } catch (ValidationException|Throwable) {
            $this->failImport($campaign);

            return;
        }

        if ($rows === []) {
            $this->failImport($campaign);

            return;
        }

        DB::transaction(function () use ($campaign, $rows): void {
            $seenPhones = [];
            foreach ($rows as $index => $row) {
                $recipient = $this->mapRowToRecipient($campaign, $row, $index + 2, $seenPhones);
                if ($recipient !== null) {
                    BulkMessageRecipient::query()->create($recipient);
                }
            }

            $campaign->refreshCounts();

            $pending = $campaign->recipients()
                ->where('status', BulkMessageRecipientStatus::Pending->value)
                ->count();

            $campaign->forceFill([
                'status' => $pending === 0 ? BulkMessageStatus::Failed : BulkMessageStatus::Draft,
                'completed_at' => $pending === 0 ? now() : null,
            ])->save();
        });
    }

    public function failImport(BulkMessage $campaign, ?string $reason = null): void
    {
        $campaign->forceFill([
            'status' => BulkMessageStatus::Failed,
            'completed_at' => now(),
        ])->save();
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
        $path = resource_path('templates/bulk-message-template.csv');
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        return "phone\n930011756\n911223344\n912345678\n";
    }

    /**
     * @return list<array{phone:?string}>
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
                default => null,
            };
            if ($alias !== null && ! isset($map[$alias])) {
                $map[$alias] = $index;
            }
        }

        if (! isset($map['phone'])) {
            throw ValidationException::withMessages([
                'file' => 'Header row must include a phone column.',
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $headers
     * @param  list<string>  $values
     * @return array{phone:?string}
     */
    protected function mapAssoc(array $headers, array $values): array
    {
        $out = ['phone' => null];
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
     * Match company by phone (last 9 digits). Name, TIN, and send number come from DB.
     *
     * @param  array{phone:?string}  $row
     * @param  array<string, true>  $seenPhones
     * @return array<string, mixed>|null
     */
    protected function mapRowToRecipient(BulkMessage $campaign, array $row, int $rowNumber, array &$seenPhones): ?array
    {
        $phoneRaw = filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null;
        $normalizedFromFile = $phoneRaw !== null ? $this->sms->normalizePhone($phoneRaw) : '';

        if ($normalizedFromFile === '' || strlen($normalizedFromFile) !== 9) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => null,
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $normalizedFromFile ?: null,
                'company_name' => null,
                'company_tin' => null,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Invalid phone (need last 9 digits: 9xxxxxxxx / 7xxxxxxxx).',
            ];
        }

        if (isset($seenPhones[$normalizedFromFile])) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => null,
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $normalizedFromFile,
                'company_name' => null,
                'company_tin' => null,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Duplicate phone in this upload.',
            ];
        }
        $seenPhones[$normalizedFromFile] = true;

        if (! preg_match('/^(9|7)\d{8}$/', $normalizedFromFile) || ! $this->sms->ensurePhoneIsLocal($normalizedFromFile)) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => null,
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $normalizedFromFile,
                'company_name' => null,
                'company_tin' => null,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Phone is not a local Ethio telecom mobile.',
            ];
        }

        $company = $this->findCompanyByLastNine($normalizedFromFile);
        if (! $company) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => null,
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $normalizedFromFile,
                'company_name' => null,
                'company_tin' => null,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'No company matched for this phone.',
            ];
        }

        $sendPhone = filled($company->phone)
            ? $this->sms->normalizePhone($company->phone)
            : $normalizedFromFile;

        if ($sendPhone === '' || strlen($sendPhone) !== 9
            || ! preg_match('/^(9|7)\d{8}$/', $sendPhone)
            || ! $this->sms->ensurePhoneIsLocal($sendPhone)) {
            return [
                'campaign_id' => $campaign->id,
                'company_id' => $company->id,
                'phone_raw' => $company->phone ?: $phoneRaw,
                'phone_normalized' => $sendPhone ?: null,
                'company_name' => $company->name,
                'company_tin' => $company->tin,
                'row_number' => $rowNumber,
                'status' => BulkMessageRecipientStatus::Skipped,
                'error' => 'Matched company has no usable mobile on file.',
            ];
        }

        return [
            'campaign_id' => $campaign->id,
            'company_id' => $company->id,
            'phone_raw' => $company->phone ?: $phoneRaw,
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
