<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Story extends Model
{
    protected $table = 'stories';

    protected $fillable = [
        'user_id',
        'media',
        'media_type',
        'caption',
        'view_count',
        'expires_at',
        'created_at',
        'deleted_at',
    ];

    public $timestamps = false; // because table doesn't have updated_at

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['media_url'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
        public function reports()
    {
        return $this->hasMany(StoryReport::class, 'story_id');
    }


    public function views()
    {
        return $this->hasMany(StoryView::class, 'story_id');
    }

    public function getMediaUrlAttribute()
    {
        if (! $this->media) return null;

        $base = rtrim(config('app.url'), '/');
        return $base . '/storage/app/public/' . ltrim($this->media, '/');
    }
}