<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Attach legacy website SVG artwork to catalogue services.
 */
class AttachLegacyServiceImagesSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('services:attach-legacy-images');
        $this->command?->info(trim(Artisan::output()));
    }
}
