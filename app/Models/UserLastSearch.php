<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLastSearch extends Model
{
    protected $table = 'user_last_searches';

    protected $fillable = [
        'user_id',
        'filters',
        'clicked_user_ids',
    ];

    protected $casts = [
        'filters' => 'array',
        'clicked_user_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
