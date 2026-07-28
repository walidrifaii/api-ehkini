<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deactivated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('deactivated_at')->nullable()->after('is_active');
            });
        }

        // Existing soft-deleted accounts: start the 30-day window from updated_at when possible.
        if (Schema::hasColumn('users', 'deactivated_at')) {
            DB::table('users')
                ->where('is_active', 0)
                ->whereNull('deactivated_at')
                ->update([
                    'deactivated_at' => DB::raw('COALESCE(updated_at, NOW())'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'deactivated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('deactivated_at');
            });
        }
    }
};
