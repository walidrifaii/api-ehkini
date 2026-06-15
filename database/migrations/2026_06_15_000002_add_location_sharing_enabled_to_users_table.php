<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('location_sharing_enabled')->default(false)->after('location_updated_at');

            $table->index(
                ['is_active', 'location_sharing_enabled', 'latitude'],
                'users_active_sharing_lat_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_active_sharing_lat_idx');
            $table->dropColumn('location_sharing_enabled');
        });
    }
};
