<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_provisioning_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_provisioning_id')->constrained('service_provisionings')->cascadeOnDelete();
            $table->nullableMorphs('author');
            $table->text('body');
            $table->boolean('is_public')->default(true);
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->unsignedInteger('attachment_size_bytes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_provisioning_id', 'created_at']);
            $table->index(['service_provisioning_id', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_provisioning_messages');
    }
};
