<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id','type','title','body','data','is_read'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
    ];
    
     public static function deleteFriendRequest($friendshipId)
    {
        self::where('type', 'friend_request')
            ->where('data->friendship_id', $friendshipId)
            ->delete();
    }
}
