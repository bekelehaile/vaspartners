<?php

namespace App\Support\Migration;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stream MySQL dump INSERT rows for a given table from a `.dump` / `.sql` file.
 */
final class MvasDumpTableReader
{
    /**
     * @return Generator<int, list<string|null>>
     */
    public function rows(string $dumpPath, string $table): Generator
    {
        if (! is_file($dumpPath)) {
            throw new InvalidArgumentException("Dump file not found: {$dumpPath}");
        }

        $lower = strtolower($dumpPath);
        if (! str_ends_with($lower, '.dump') && ! str_ends_with($lower, '.sql')) {
            throw new InvalidArgumentException('Source must be a MySQL .dump (or .sql) file.');
        }

        $needle = 'INSERT INTO `'.$table.'`';
        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open dump: {$dumpPath}");
        }

        try {
            $buffer = '';
            $capturing = false;

            while (($line = fgets($handle)) !== false) {
                if (! $capturing) {
                    if (! str_starts_with($line, $needle)) {
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
                    yield $row;
                }

                $capturing = false;
                $buffer = '';
            }
        } finally {
            fclose($handle);
        }
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
