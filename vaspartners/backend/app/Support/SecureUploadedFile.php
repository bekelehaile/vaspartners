<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Strict upload checks used by partner service-request documents and provisioning.
 * Rejects empty files, oversize uploads, spoofed extensions, and disallowed MIME types.
 */
class SecureUploadedFile
{
    /**
     * Allowed MIME types for each configured extension.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSION_MIME_MAP = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', // some browsers sniff docx as zip
        ],
        'xls' => ['application/vnd.ms-excel', 'application/msexcel'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
    ];

    /**
     * @param  list<string>|string  $acceptedMimes  Extensions (comma-separated or list), e.g. pdf,jpg,png
     */
    public static function assert(
        UploadedFile $file,
        string $field,
        string $label,
        array|string $acceptedMimes,
        int $maxSizeKb,
    ): void {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $field => 'The uploaded file is invalid or incomplete.',
            ]);
        }

        $extensions = self::normalizeExtensions($acceptedMimes);
        if ($extensions === []) {
            throw ValidationException::withMessages([
                $field => 'No accepted file types are configured for '.$label.'.',
            ]);
        }

        $maxKb = max(1, $maxSizeKb);
        $allowedList = implode(', ', $extensions);

        $validator = Validator::make(
            [$field => $file],
            [
                $field => [
                    'required',
                    'file',
                    'max:'.$maxKb,
                    'mimes:'.implode(',', $extensions),
                ],
            ],
            [
                "{$field}.required" => "A file is required for {$label}.",
                "{$field}.file" => "The upload for {$label} must be a file.",
                "{$field}.max" => "{$label} must be {$maxKb} KB or smaller.",
                "{$field}.mimes" => "{$label} must be one of: {$allowedList}.",
            ]
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $sizeBytes = (int) $file->getSize();
        if ($sizeBytes <= 0) {
            throw ValidationException::withMessages([
                $field => "{$label} cannot be an empty file.",
            ]);
        }

        $maxBytes = $maxKb * 1024;
        if ($sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                $field => "{$label} must be {$maxKb} KB or smaller.",
            ]);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        $guessedExt = strtolower((string) ($file->guessExtension() ?: ''));
        $detectedMime = strtolower((string) ($file->getMimeType() ?: ''));
        $clientMime = strtolower((string) ($file->getClientMimeType() ?: ''));

        if ($ext === '' || ! in_array($ext, $extensions, true)) {
            throw ValidationException::withMessages([
                $field => "{$label} file extension must be one of: {$allowedList}.",
            ]);
        }

        if ($guessedExt !== '' && ! in_array($guessedExt, $extensions, true)) {
            $jpegFamily = ['jpg', 'jpeg'];
            $spoofed = ! (
                in_array($ext, $jpegFamily, true)
                && in_array($guessedExt, $jpegFamily, true)
                && count(array_intersect($extensions, $jpegFamily)) > 0
            );

            if ($spoofed) {
                throw ValidationException::withMessages([
                    $field => "{$label} content does not match an allowed type ({$allowedList}).",
                ]);
            }
        }

        $allowedMimes = self::mimeTypesForExtensions($extensions);
        if ($allowedMimes !== [] && $detectedMime !== '') {
            if (! in_array($detectedMime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    $field => "{$label} content type \"{$detectedMime}\" is not allowed. Use: {$allowedList}.",
                ]);
            }
        }

        if ($clientMime !== '' && $clientMime !== 'application/octet-stream' && $allowedMimes !== []) {
            if (! in_array($clientMime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    $field => "{$label} reported type \"{$clientMime}\" is not allowed. Use: {$allowedList}.",
                ]);
            }
        }
    }

    /**
     * @param  list<string>|string  $acceptedMimes
     * @return list<string>
     */
    public static function normalizeExtensions(array|string $acceptedMimes): array
    {
        if (is_array($acceptedMimes)) {
            $parts = $acceptedMimes;
        } else {
            $parts = preg_split('/[,\s]+/', $acceptedMimes) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part, " \t\n\r\0\x0B.")),
            $parts
        ))));
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function mimeTypesForExtensions(array $extensions): array
    {
        $mimes = [];
        foreach ($extensions as $ext) {
            foreach (self::EXTENSION_MIME_MAP[$ext] ?? [] as $mime) {
                $mimes[$mime] = true;
            }
        }

        return array_keys($mimes);
    }

    public static function safeOriginalName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?: 'upload';

        return mb_substr($name, 0, 180);
    }
}
