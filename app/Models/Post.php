<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\MediaStorage;

class Post extends Model
{
    protected $table = 'posts';

    protected $fillable = [
        'user_id',
        'image',
    ];

    protected $appends = ['image_url'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
      public function reports()
    {
        return $this->hasMany(PostReport::class, 'post_id');
    }


    /**
     * Full public image URL
     */
    public function getImageUrlAttribute()
    {
        if (! $this->image) {
            return null;
        }

        return MediaStorage::url($this->image);
    }
}
