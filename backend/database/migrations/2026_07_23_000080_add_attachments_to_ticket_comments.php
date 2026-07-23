<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->string('attachment_disk', 32)->nullable()->after('is_public');
            $table->string('attachment_path')->nullable()->after('attachment_disk');
            $table->string('attachment_original_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime', 128)->nullable()->after('attachment_original_name');
            $table->unsignedInteger('attachment_size_bytes')->nullable()->after('attachment_mime');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_disk',
                'attachment_path',
                'attachment_original_name',
                'attachment_mime',
                'attachment_size_bytes',
            ]);
        });
    }
};
