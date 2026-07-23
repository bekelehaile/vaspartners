<?php

use App\Enums\CompanyApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('approval_status', 16)->default(CompanyApprovalStatus::Pending->value)->after('is_active');
            $table->foreignId('approved_by_user_id')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->text('approval_note')->nullable()->after('approved_at');
            $table->index('approval_status');
        });

        // Existing companies already in use are treated as approved.
        DB::table('companies')->update([
            'approval_status' => CompanyApprovalStatus::Approved->value,
            'approved_at' => now(),
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['approval_status', 'approved_at', 'approval_note']);
        });
    }
};
