<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftTransaction extends Model
{
    protected $table = 'gift_transactions';

    protected $fillable = [
        'sender_id', 'receiver_id', 'gift_id', 'price', 'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function gift()
    {
        return $this->belongsTo(Gift::class, 'gift_id');
    }

    /**
     * Profile stats for GET /me (and profile update).
     *
     * @return array{gifts_sent: int, gifts_received: int}
     */
    public static function profileGiftCountsFor(int $userId): array
    {
        $successfulStatuses = ['completed', 'sent'];

        return [
            'gifts_sent' => self::query()
                ->where('sender_id', $userId)
                ->whereIn('status', $successfulStatuses)
                ->count(),
            'gifts_received' => self::query()
                ->where('receiver_id', $userId)
                ->whereIn('status', $successfulStatuses)
                ->count(),
        ];
    }
}