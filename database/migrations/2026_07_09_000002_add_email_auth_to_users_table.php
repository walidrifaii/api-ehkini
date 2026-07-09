<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Defensive: the live `users` table has drifted from this repo's migrations
     * (columns like `phone`/`country_code` are used everywhere but were never
     * migrated here), so every change is guarded instead of assumed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->unique()->after('last_name');
            }
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
        });

        // doctrine/dbal isn't installed, so Schema::table(...)->nullable()->change()
        // isn't available here — modify the columns directly instead.
        if (Schema::hasColumn('users', 'phone')) {
            DB::statement('ALTER TABLE users MODIFY phone VARCHAR(30) NULL');
        }
        if (Schema::hasColumn('users', 'country_code')) {
            DB::statement('ALTER TABLE users MODIFY country_code VARCHAR(6) NULL');
        }

        if (Schema::hasColumn('users', 'email')) {
            $indexes = collect(DB::select('SHOW INDEX FROM users'))
                ->pluck('Key_name')
                ->unique();
            if (! $indexes->contains('users_email_unique')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->unique('email');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
        });
    }
};
