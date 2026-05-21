<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftCategory extends Model
{
    protected $table = 'gift_categories';

    protected $fillable = [
        'name', 'slug', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function gifts()
    {
        return $this->hasMany(Gift::class, 'category_id');
    }
}