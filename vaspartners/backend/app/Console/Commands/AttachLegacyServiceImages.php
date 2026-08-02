<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Attach existing website SVG artwork to catalogue services (by slug).
 */
class AttachLegacyServiceImages extends Command
{
    protected $signature = 'services:attach-legacy-images
                            {--force : Overwrite services that already have an image}
                            {--dry-run : Show what would be attached without writing}';

    protected $description = 'Copy legacy /img/*.svg artwork into storage and set services.image';

    /**
     * slug => filename under public/img (or resources/service-images).
     *
     * @var array<string, string>
     */
    protected array $map = [
        'sms-premium' => 'sms_premium.svg',
        'sms-non-premium' => 'sms_np.svg',
        'voice-premium' => 'voice_premium.svg',
        'voice-non-premium' => 'voice_np.svg',
        'collocation' => 'collocation.svg',
        'm2m-machine-to-machine' => 'm2m.svg',
        'visp-virtual-internet-service-provider' => 'visp.svg',
        'crbt' => 'crbt.svg',
        'corporate-crbt' => 'corporate_crbt.svg',
        'ussd-premium' => 'ussd_premium.svg',
        'ussd-non-premium' => 'ussd_np.svg',
        'obd' => 'obd.svg',
        'api' => 'api.svg',
        'mo-mobile-originating' => 'mo.svg',
        'mt-mobile-terminated-premium' => 'mt.svg',
        'a2p-application-to-person' => 'a2p.svg',
        'device-insurance' => 'device_insurance.svg',
        'ethio-avaya-spaces' => 'api.svg',
        'public-ip' => 'payment_api.svg',
        'white-list' => 'a2p.svg',
        'get-pass-request' => 'device_insurance.svg',
        'merchant-acoount' => 'payment_api.svg',
        'startup' => 'services.svg',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $attached = 0;
        $skipped = 0;
        $missing = 0;

        foreach (Service::query()->orderBy('id')->get() as $service) {
            $slug = (string) $service->slug;
            $file = $this->map[$slug] ?? null;

            if (! $file) {
                $this->warn("No legacy image mapped for {$slug}");
                $missing++;

                continue;
            }

            if (filled($service->image) && ! $force) {
                $skipped++;

                continue;
            }

            $source = $this->resolveSource($file);
            if (! $source) {
                $this->error("Source file not found for {$slug} ({$file})");
                $missing++;

                continue;
            }

            $dest = 'services/'.$slug.preg_replace('/^.*(\.[a-z0-9]+)$/i', '$1', $file);

            if ($dryRun) {
                $this->line("[dry-run] {$slug} <- {$source} -> {$dest}");
                $attached++;

                continue;
            }

            Storage::disk('public')->put($dest, File::get($source));
            $service->forceFill(['image' => $dest])->save();
            $this->info("Attached {$slug} -> {$dest}");
            $attached++;
        }

        $this->newLine();
        $this->info("Attached: {$attached}, skipped: {$skipped}, missing: {$missing}");

        return self::SUCCESS;
    }

    protected function resolveSource(string $file): ?string
    {
        $candidates = [
            resource_path('service-images/'.$file),
            base_path('../frontend/public/img/'.$file),
            base_path('public/img/'.$file),
            '/var/www/html/resources/service-images/'.$file,
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
