<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Friendship extends Model
{
    protected $table = 'friendships';

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'status',
    ];
    
    protected $appends = ['profile_image_url'];

  public function getProfileImageUrlAttribute(): ?string
   {
    if (! $this->profile_image) return null;
    return url('storage/' . ltrim($this->profile_image, '/'));
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
    public static function getRelationStatus(int $meId, int $otherUserId): string
{
    $row = self::where(function ($q) use ($meId, $otherUserId) {
            $q->where('sender_id', $meId)
              ->where('receiver_id', $otherUserId);
        })
        ->orWhere(function ($q) use ($meId, $otherUserId) {
            $q->where('sender_id', $otherUserId)
              ->where('receiver_id', $meId);
        })
        ->orderByDesc('id')
        ->first();

    if (! $row) return 'none';

    if ($row->status === 'accepted') return 'friends';

    if ($row->status === 'pending') {
        return $row->sender_id === $meId ? 'outgoing_request' : 'incoming_request';
    }

    return 'none';
}

    /**
     * Count accepted friends for profile stats (matches GET /friends list rules).
     */
    public static function countAcceptedConnectionsFor(int $userId): int
    {
        $rows = self::query()
            ->where('status', 'accepted')
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)
                    ->orWhere('receiver_id', $userId);
            })
            ->orderByDesc('id')
            ->get(['sender_id', 'receiver_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $otherIds = [];
        foreach ($rows as $row) {
            $otherId = (int) $row->sender_id === $userId
                ? (int) $row->receiver_id
                : (int) $row->sender_id;

            $otherIds[$otherId] = true;
        }

        return User::query()
            ->whereIn('id', array_keys($otherIds))
            ->where('is_active', 1)
            ->count();
    }

}
