<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

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

    public function setEmbeddingVector(array $vector): void
    {
        $this->attributes['embedding'] = Crypt::encryptString(json_encode($vector));
    }

    public function getEmbeddingVector(): array
    {
        $raw = $this->attributes['embedding'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode(Crypt::decryptString($raw), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_map('floatval', $decoded);
    }
}
