<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('location_updated_at')->nullable()->after('longitude');

            // Bounding-box pre-filter: active users in a lat/lng window.
            $table->index(
                ['is_active', 'latitude', 'longitude'],
                'users_active_lat_lng_idx'
            );

            // Gender filter combined with active flag.
            $table->index(
                ['is_active', 'gender'],
                'users_active_gender_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_active_lat_lng_idx');
            $table->dropIndex('users_active_gender_idx');
            $table->dropColumn(['latitude', 'longitude', 'location_updated_at']);
        });
    }
};
