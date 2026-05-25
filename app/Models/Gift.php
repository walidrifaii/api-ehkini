<?php

namespace App\Models;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $table = 'gifts';

    protected $fillable = [
        'category_id', 'name', 'price', 'image', 'is_active',
    ];

    protected $appends = ['image_url'];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return MediaStorage::url($this->image);
    }

    public function category()
    {
        return $this->belongsTo(GiftCategory::class, 'category_id');
    }
}