<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'location_sharing_enabled')) {
                $table->boolean('location_sharing_enabled')->default(false)->after('location_updated_at');
            }
        });

        $indexes = collect(DB::select('SHOW INDEX FROM users'))
            ->pluck('Key_name')
            ->unique();

        if (
            ! $indexes->contains('users_active_sharing_lat_idx')
            && Schema::hasColumn('users', 'location_sharing_enabled')
            && Schema::hasColumn('users', 'latitude')
        ) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(
                    ['is_active', 'location_sharing_enabled', 'latitude'],
                    'users_active_sharing_lat_idx'
                );
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM users'))
            ->pluck('Key_name')
            ->unique();

        Schema::table('users', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('users_active_sharing_lat_idx')) {
                $table->dropIndex('users_active_sharing_lat_idx');
            }
            if (Schema::hasColumn('users', 'location_sharing_enabled')) {
                $table->dropColumn('location_sharing_enabled');
            }
        });
    }
};
