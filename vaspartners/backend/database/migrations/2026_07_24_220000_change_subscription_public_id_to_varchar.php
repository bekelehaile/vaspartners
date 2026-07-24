<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ULID columns are CHAR(26) and space-pad shorter timestamp ids.
        DB::statement('ALTER TABLE subscriptions ALTER COLUMN public_id TYPE varchar(32)');
        DB::statement('UPDATE subscriptions SET public_id = trim(public_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions ALTER COLUMN public_id TYPE char(26)');
    }
};
