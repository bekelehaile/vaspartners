<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Attaches core New subscription documents without re-importing the full catalog. */
class CoreSubscriptionDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        (new CatalogSeeder)->ensureCoreNewSubscriptionDocuments();
    }
}
