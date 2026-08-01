<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_comment_id')
                ->nullable()
                ->unique()
                ->after('attachment_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropUnique(['legacy_mvas_comment_id']);
            $table->dropColumn('legacy_mvas_comment_id');
        });
    }
};
