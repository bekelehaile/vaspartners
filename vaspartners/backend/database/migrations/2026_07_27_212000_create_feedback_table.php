<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter'); // 1–4
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->text('description');
            $table->timestamps();

            $table->unique(['contact_id', 'year', 'quarter']);
            $table->index(['year', 'quarter']);
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
