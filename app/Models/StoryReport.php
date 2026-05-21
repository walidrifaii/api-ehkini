<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryReport extends Model
{
    protected $table = 'story_reports';

    protected $fillable = [
        'reporter_id',
        'story_id',
        'reason',
        'description',
        'status',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function story()
    {
        return $this->belongsTo(Story::class, 'story_id');
    }
}
