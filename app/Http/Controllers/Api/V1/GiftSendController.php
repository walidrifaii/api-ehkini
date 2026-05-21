<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftSendController extends Controller
{
    /**
     * POST /api/v1/gifts/send
     * body: { "receiver_id": 12, "gift_id": 3 }
     */
public function send(Request $request, FcmService $fcm)
{
    $sender = $request->user();
    if (! $sender) return response()->json(['message' => 'Unauthenticated.'], 401);

    $data = $request->validate([
        'receiver_id' => ['required', 'exists:users,id'],
        'gift_id'     => ['required', 'exists:gifts,id'],
    ]);

    $receiverId = (int) $data['receiver_id'];
    $giftId     = (int) $data['gift_id'];

    if ((int)$sender->id === $receiverId) {
        return response()->json(['message' => 'You cannot send gift to yourself.'], 422);
    }

    $receiver = User::find($receiverId);
    $gift     = Gift::find($giftId);

    if (! $receiver || ! $gift) {
        return response()->json(['message' => 'Not found.'], 404);
    }

    $title = 'New Gift';
    $body  = $sender->first_name . ' sent you a ' . $gift->name;

    $price = (float) $gift->price;

    try {
        DB::beginTransaction();

        $tx = GiftTransaction::create([
            'sender_id'   => $sender->id,
            'receiver_id' => $receiver->id,
            'gift_id'     => $gift->id,
            'price'       => $price,
            'status'      => 'completed',
        ]);

        // Save notification for receiver
        $notif = AppNotification::create([
            'user_id' => $receiver->id,
            'type'    => 'gift_sent',
            'title'   => $title,
            'body'    => $body,
            'data'    => [
                'gift_transaction_id' => $tx->id,
                'gift_id'             => $gift->id,
                'gift_name'           => $gift->name,
                'sender_id'           => $sender->id,
                'price'               => (string) $price,
            ],
            'is_read' => 0,
        ]);

        DB::commit();

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('SEND_GIFT_FAILED', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiverId,
            'gift_id' => $giftId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'Failed to send gift.',
        ], 422);
    }

    // ✅ Push notification (after commit)
    if (!empty($receiver->fcm_token)) {
        try {
            $fcm->sendToToken(
                $receiver->fcm_token,
                $title,
                $body,
                [
                    'type' => 'gift_sent',
                    'gift_transaction_id' => (string) $tx->id,
                    'gift_id' => (string) $gift->id,
                    'sender_id' => (string) $sender->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('SEND_GIFT_FCM_FAILED', [
                'receiver_id' => $receiverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return response()->json([
        'message' => 'Gift sent successfully.',
        'transaction' => $tx,
        'notification_id' => $notif->id,
    ], 201);
}
}