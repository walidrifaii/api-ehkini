<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends \App\Http\Controllers\Api\V1\NotificationController
{
    // /**
    //  * POST /api/v2/chat/notify
    //  *
    //  * Body:
    //  * - receiver_id (required)
    //  * - chat_id (required)
    //  * - message (required)
    //  * - sender_id (optional)
    //  * - sender_name (optional)
    //  * - sender_profile_pic (optional)
    //  */
    // public function notify(Request $request, FcmService $fcm)
    // {
    //     $data = $request->validate([
    //         'receiver_id' => ['required', 'exists:users,id'],
    //         'chat_id'     => ['required', 'string', 'max:200'],
    //         'message'     => ['required', 'string', 'max:1000'],
    //         'sender_id'   => ['nullable', 'integer'],
    //         'sender_name' => ['nullable', 'string', 'max:120'],
    //         'sender_profile_pic' => ['nullable', 'string', 'max:2048'],
    //     ]);

    //     $receiver = \App\Models\User::find((int) $data['receiver_id']);

    //     if (! $receiver) {
    //         return response()->json(['ok' => false, 'error' => 'Receiver not found'], 404);
    //     }

    //     if (empty($receiver->fcm_token)) {
    //         return response()->json([
    //             'ok' => false,
    //             'error' => 'Receiver has no fcm_token',
    //             'receiver_id' => $receiver->id,
    //         ], 200);
    //     }

    //     $sender = null;
    //     if (! empty($data['sender_id'])) {
    //         $sender = \App\Models\User::query()
    //             ->select(['id', 'first_name', 'last_name', 'profile_image'])
    //             ->find((int) $data['sender_id']);
    //     }

    //     $senderName = $data['sender_name']
    //         ?? ($sender ? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) : null)
    //         ?? 'New message';

    //     $senderProfilePic = $data['sender_profile_pic']
    //         ?? ($sender ? $sender->profile_image_url : null);

    //     $title = $senderName;
    //     $body  = $data['message'];

    //     try {
    //         $res = $fcm->sendToToken(
    //             $receiver->fcm_token,
    //             $title,
    //             $body,
    //             [
    //                 'type' => 'chat',
    //                 'chat_id' => (string) $data['chat_id'],
    //                 'sender_id' => (string) ($data['sender_id'] ?? ''),
    //                 'sender_name' => $senderName,
    //                 'sender_profile_pic' => $senderProfilePic ? (string) $senderProfilePic : '',
    //             ]
    //         );

    //         return response()->json([
    //             'ok' => (bool) ($res['ok'] ?? false),
    //             'fcm' => $res,
    //             'receiver_id' => $receiver->id,
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         Log::error('Chat notify exception', [
    //             'error' => $e->getMessage(),
    //             'receiver_id' => $receiver->id,
    //         ]);

    //         return response()->json([
    //             'ok' => false,
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }
}
