<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class UserFaceEmbedding extends Model
{
    protected $fillable = [
        'user_id',
        'embedding',
        'quality_score',
        'enrolled_at',
    ];

    protected $casts = [
        'quality_score' => 'float',
        'enrolled_at' => 'datetime',
    ];

    protected $hidden = [
        'embedding',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Store embedding as plain JSON (no Crypt / hash encryption).
     */
    public function setEmbeddingVector(array $vector): void
    {
        $values = array_map('floatval', array_values($vector));
        $this->attributes['embedding'] = json_encode($values, JSON_THROW_ON_ERROR);
    }

    /**
     * Read plain JSON embeddings. Still accepts legacy Crypt-encrypted rows.
     */
    public function getEmbeddingVector(): array
    {
        $raw = $this->getRawOriginal('embedding') ?? $this->attributes['embedding'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        // Legacy: encrypted payload from older builds.
        if (! is_array($decoded)) {
            try {
                $decoded = json_decode(Crypt::decryptString($raw), true);
            } catch (\Throwable $e) {
                Log::warning('face_embedding.decrypt_failed', [
                    'user_id' => $this->user_id ?? null,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        }

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['embedding']) && is_array($decoded['embedding'])) {
            $decoded = $decoded['embedding'];
        }

        $vector = array_map('floatval', array_values($decoded));

        return count($vector) >= 64 ? $vector : [];
    }
}
