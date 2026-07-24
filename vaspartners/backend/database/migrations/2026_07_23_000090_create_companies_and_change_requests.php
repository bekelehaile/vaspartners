<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('name');
            $table->string('tin', 64)->unique();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('profile_completed_at')->constrained('companies')->nullOnDelete();
            $table->string('company_role', 16)->nullable()->after('company_id'); // owner|member
        });

        Schema::create('company_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // attach|detach
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->text('contact_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('proposal_disk', 32)->nullable();
            $table->string('proposal_path')->nullable();
            $table->string('proposal_original_name')->nullable();
            $table->unsignedInteger('proposal_size_bytes')->nullable();
            $table->string('letter_disk', 32)->nullable();
            $table->string('letter_path')->nullable();
            $table->string('letter_original_name')->nullable();
            $table->unsignedInteger('letter_size_bytes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'type']);
            $table->index(['contact_id', 'status']);
        });

        // Backfill companies from existing partner profiles
        $contacts = DB::table('contacts')
            ->whereNotNull('company_tin')
            ->where('company_tin', '!=', '')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($contacts as $contact) {
            $tin = trim((string) $contact->company_tin);
            if ($tin === '') {
                continue;
            }

            $companyId = DB::table('companies')->where('tin', $tin)->value('id');
            if (! $companyId) {
                $companyId = DB::table('companies')->insertGetId([
                    'public_id' => (string) \Illuminate\Support\Str::ulid(),
                    'name' => $contact->company_name ?: 'Company '.$tin,
                    'tin' => $tin,
                    'phone' => $contact->company_phone,
                    'email' => $contact->company_email,
                    'address' => $contact->company_address,
                    'is_active' => true,
                    'created_by_contact_id' => $contact->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('contacts')->where('id', $contact->id)->update([
                'company_id' => $companyId,
                'company_role' => 'owner',
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_change_requests');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('company_role');
        });
        Schema::dropIfExists('companies');
    }
};
