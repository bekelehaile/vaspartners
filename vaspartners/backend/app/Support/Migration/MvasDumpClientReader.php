<?php

namespace App\Support\Migration;

use Generator;

/**
 * Streams partner rows from an MVAS MySQL `.dump` file (INSERT INTO `clients`).
 */
final class MvasDumpClientReader
{
    public function __construct(
        private readonly MvasDumpTableReader $tables,
    ) {}

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
        foreach ($this->tables->rows($dumpPath, 'clients') as $row) {
            $mapped = $this->mapClientRow($row);
            if ($mapped !== null) {
                yield $mapped;
            }
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
}
