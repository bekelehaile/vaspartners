<?php

namespace App\Support\Migration;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Streams partner rows from an MVAS MySQL `.dump` file (INSERT INTO `clients`).
 */
final class MvasDumpClientReader
{
    /**
     * @return Generator<int, array{
     *   id: int,
     *   company_name: ?string,
     *   name: string,
     *   email: string,
     *   mobile: ?string,
     *   phone: string,
     *   is_banned: bool,
     *   is_verified_client: bool,
     *   is_active: bool,
     *   deleted_at: ?string,
     *   address: ?string,
     *   city: ?string,
     *   type: ?string,
     *   contact_phone: ?string,
     *   country: ?string
     * }>
     */
    public function clients(string $dumpPath): Generator
    {
        if (! is_file($dumpPath)) {
            throw new InvalidArgumentException("Dump file not found: {$dumpPath}");
        }

        if (! str_ends_with(strtolower($dumpPath), '.dump') && ! str_ends_with(strtolower($dumpPath), '.sql')) {
            throw new InvalidArgumentException('Source must be a MySQL .dump (or .sql) file.');
        }

        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open dump: {$dumpPath}");
        }

        try {
            $buffer = '';
            $capturing = false;

            while (($line = fgets($handle)) !== false) {
                if (! $capturing) {
                    if (! str_starts_with($line, 'INSERT INTO `clients`')) {
                        continue;
                    }
                    $capturing = true;
                    $buffer = $line;
                } else {
                    $buffer .= $line;
                }

                if (! str_ends_with(rtrim($line), ';')) {
                    continue;
                }

                $valuesPos = stripos($buffer, 'VALUES');
                if ($valuesPos === false) {
                    $capturing = false;
                    $buffer = '';

                    continue;
                }

                foreach ($this->parseValueTuples(substr($buffer, $valuesPos + 6)) as $row) {
                    $mapped = $this->mapClientRow($row);
                    if ($mapped !== null) {
                        yield $mapped;
                    }
                }

                $capturing = false;
                $buffer = '';
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>|null
     */
    private function mapClientRow(array $row): ?array
    {
        // CREATE TABLE `clients` column order in mvas_*.dump
        if (count($row) < 29) {
            return null;
        }

        $phoneDigits = preg_replace('/\D/', '', (string) ($row[5] ?? '')) ?? '';
        $phone = substr($phoneDigits, -9);

        return [
            'id' => (int) $row[0],
            'company_name' => $row[1],
            'name' => (string) ($row[2] ?? ''),
            'email' => (string) ($row[3] ?? ''),
            'mobile' => $row[4],
            'phone' => $phone,
            'is_banned' => (string) $row[6] === '1',
            'is_verified_client' => (string) $row[7] === '1',
            'is_active' => (string) $row[12] === '1',
            'deleted_at' => $row[15],
            'address' => $row[16],
            'city' => $row[17],
            'type' => $row[26],
            'contact_phone' => $row[27],
            'country' => $row[28],
        ];
    }

    /**
     * @return list<list<string|null>>
     */
    private function parseValueTuples(string $blob): array
    {
        $rows = [];
        $row = [];
        $field = '';
        $depth = 0;
        $inQuote = false;
        $length = strlen($blob);

        for ($i = 0; $i < $length; $i++) {
            $ch = $blob[$i];

            if ($inQuote) {
                if ($ch === '\\' && $i + 1 < $length) {
                    $field .= $blob[$i + 1];
                    $i++;

                    continue;
                }
                if ($ch === "'") {
                    if ($i + 1 < $length && $blob[$i + 1] === "'") {
                        $field .= "'";
                        $i++;

                        continue;
                    }
                    $inQuote = false;

                    continue;
                }
                $field .= $ch;

                continue;
            }

            if ($ch === "'") {
                $inQuote = true;

                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $row = [];
                    $field = '';
                    $depth = 1;

                    continue;
                }
                $field .= $ch;
                $depth++;

                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $row[] = $field === 'NULL' ? null : $field;
                    $rows[] = $row;
                    $field = '';

                    continue;
                }
                $field .= $ch;

                continue;
            }

            if ($ch === ',' && $depth === 1) {
                $row[] = $field === 'NULL' ? null : $field;
                $field = '';

                continue;
            }

            if ($depth >= 1) {
                $field .= $ch;
            }
        }

        return $rows;
    }
}
