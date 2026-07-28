<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            });
        }

        if (! Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (! Schema::hasColumn('users', 'location_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('location_updated_at')->nullable()->after('longitude');
            });
        }

        $indexes = collect(DB::select('SHOW INDEX FROM users'))
            ->pluck('Key_name')
            ->unique();

        if (
            ! $indexes->contains('users_active_lat_lng_idx')
            && Schema::hasColumn('users', 'latitude')
            && Schema::hasColumn('users', 'longitude')
        ) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(
                    ['is_active', 'latitude', 'longitude'],
                    'users_active_lat_lng_idx'
                );
            });
        }

        if (! $indexes->contains('users_active_gender_idx')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(
                    ['is_active', 'gender'],
                    'users_active_gender_idx'
                );
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM users'))
            ->pluck('Key_name')
            ->unique();

        if ($indexes->contains('users_active_lat_lng_idx')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_active_lat_lng_idx');
            });
        }

        if ($indexes->contains('users_active_gender_idx')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_active_gender_idx');
            });
        }

        foreach (['location_updated_at', 'longitude', 'latitude'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
