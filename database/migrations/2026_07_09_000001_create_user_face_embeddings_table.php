<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live DB may already have this table from a prior manual/out-of-band create.
        if (Schema::hasTable('user_face_embeddings')) {
            return;
        }

        Schema::create('user_face_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('embedding');
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->index('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_face_embeddings');
    }
};
