<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;

/**
 * Rewrite legacy service descriptions into clean HTML for the public site / Filament editor.
 */
class PolishServiceDescriptions extends Command
{
    protected $signature = 'services:polish-descriptions
                            {--dry-run : Preview without saving}
                            {--from-catalog : Reset descriptions from mvas_catalog.json before polishing}';

    protected $description = 'Clean legacy rn/title prefixes from service descriptions into professional HTML';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fromCatalog = (bool) $this->option('from-catalog');
        $catalogBySlug = $fromCatalog ? $this->catalogDescriptions() : [];
        $updated = 0;

        foreach (Service::query()->orderBy('id')->get() as $service) {
            $raw = (string) $service->description;
            if ($fromCatalog && isset($catalogBySlug[$service->slug])) {
                $raw = $catalogBySlug[$service->slug];
            }

            $polished = $this->polish($raw, (string) $service->name);
            $html = $this->toHtml($polished);

            if ($html === (string) $service->description) {
                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '').$service->slug);
            $this->line('  '.str($polished)->limit(120));

            if (! $dry) {
                $service->forceFill(['description' => $html])->save();
            }
            $updated++;
        }

        $this->info(($dry ? 'Would update ' : 'Updated ').$updated.' service description(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function catalogDescriptions(): array
    {
        $path = database_path('data/mvas_catalog.json');
        if (! is_file($path)) {
            $this->warn('Catalog file not found: '.$path);

            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        $out = [];
        foreach (($json['services'] ?? []) as $row) {
            if (! empty($row['slug']) && isset($row['description'])) {
                $out[$row['slug']] = (string) $row['description'];
            }
        }

        return $out;
    }

    protected function polish(string $raw, string $serviceName): string
    {
        $text = trim($raw);
        if ($text === '') {
            return 'Details for this service will be published here soon.';
        }

        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $this->splitLegacyNewlines($text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = preg_replace('/Value added ervice/i', 'Value added service', $text) ?? $text;
        $text = preg_replace(
            '/VASP\s*\(\s*Value\s+added\s+service\s+provider\s*\)/i',
            'VASP (Value Added Service Provider)',
            $text,
        ) ?? $text;
        $text = preg_replace('/\bethio telecom\b/i', 'Ethio telecom', $text) ?? $text;
        $text = preg_replace('/information[\'’]s\b/i', 'information', $text) ?? $text;
        $text = preg_replace('/\s+:\s*-\s*/', ': ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = trim($text);

        $lines = array_values(array_filter(array_map('trim', preg_split("/\n+/", $text) ?: [])));
        $nameRoot = trim(preg_replace('/\s*\/\s*.*$/', '', $serviceName) ?? $serviceName);

        while (count($lines) > 1 && $this->isRedundantTitle($lines[0], $serviceName, $nameRoot)) {
            array_shift($lines);
        }

        $body = implode("\n\n", $lines);
        // Drop "SMS Premium service: -" / "MT (Mobile terminated):" style prefixes.
        $body = preg_replace('/^[\w][^.\n]{0,90}?(?:\bservice)?\s*:\s*(?:-\s*)?/i', '', $body) ?? $body;
        $body = preg_replace_callback('/^means\s+(an?\s+)/i', static fn ($m) => ucfirst($m[1]), $body) ?? $body;
        $body = preg_replace('/^means\s+/i', '', $body) ?? $body;
        $body = preg_replace_callback('/^is\s+(an?\s+)/i', static fn ($m) => ucfirst($m[1]), $body) ?? $body;
        $body = preg_replace('/^is\s+/i', '', $body) ?? $body;
        $body = preg_replace(
            '/\s*Legal\s+requirements?(?:\s+to\s+get[^\n]*)?/i',
            "\n\nLegal requirements\n",
            $body,
        ) ?? $body;
        $body = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);

        if ($body === '') {
            return 'Details for this service will be published here soon.';
        }

        return mb_strtoupper(mb_substr($body, 0, 1)).mb_substr($body, 1);
    }

    /**
     * Legacy catalogue used literal "rn" as newlines. Never split words like "internet".
     */
    protected function splitLegacyNewlines(string $text): string
    {
        $text = preg_replace('/rn(?=rn)/', "\n", $text) ?? $text;
        $text = preg_replace('/\s*rn(?=\d+[).])/', "\n", $text) ?? $text;
        $text = preg_replace('/\s+rn\s+/', "\n", $text) ?? $text;
        $text = preg_replace('/\s*rn(?=[A-Z0-9(])/', "\n", $text) ?? $text;
        $text = preg_replace('/(?<=[A-Z][A-Z])rn/', "\n", $text) ?? $text;
        $text = preg_replace('/(?<=[a-z)])rn(?=[A-Z(])/', "\n", $text) ?? $text;

        return $text;
    }

    protected function isRedundantTitle(string $line, string $serviceName, string $nameRoot): bool
    {
        if (mb_strlen($line) > 48) {
            return false;
        }

        $lower = mb_strtolower($line);

        return (bool) preg_match('/^[A-Z0-9][A-Z0-9\s\-()\/]{0,24}$/', $line)
            || $lower === mb_strtolower($serviceName)
            || ($nameRoot !== '' && $lower === mb_strtolower($nameRoot));
    }

    protected function toHtml(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split("/\n+/", $text) ?: [])));
        $html = [];
        $list = [];

        $flush = function () use (&$html, &$list): void {
            if ($list === []) {
                return;
            }
            $items = array_map(fn ($item) => '<li>'.e($item).'</li>', $list);
            $html[] = '<ol>'.implode('', $items).'</ol>';
            $list = [];
        };

        foreach ($lines as $line) {
            if ($line === '' || preg_match('/^[sS]$/', $line)) {
                continue;
            }
            if (preg_match('/^\d+[).]\s*(.+)$/', $line, $m)) {
                $list[] = $m[1];

                continue;
            }
            $flush();
            if (preg_match('/^legal requirements?$/i', $line)) {
                $html[] = '<h3>Legal requirements</h3>';

                continue;
            }
            $html[] = '<p>'.e($line).'</p>';
        }
        $flush();

        return implode('', $html);
    }
}
