<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatNotificationController extends Controller
{
    /**
     * POST /api/v1/chat/notify
     *
     * Body:
     * - receiver_id (required)
     * - chat_id (required)
     * - message (required)
     * - sender_id (optional)
     * - sender_name (optional)
     */
    public function notify(Request $request, FcmService $fcm)
    {
        $data = $request->validate([
            'receiver_id' => ['required', 'exists:users,id'],
            'chat_id'     => ['required', 'string', 'max:200'],
            'message'     => ['required', 'string', 'max:1000'],
            'sender_id'   => ['nullable', 'integer'],
            'sender_name' => ['nullable', 'string', 'max:120'],
        ]);

        $receiver = User::find((int)$data['receiver_id']);

        if (! $receiver) {
            return response()->json(['ok' => false, 'error' => 'Receiver not found'], 404);
        }

        if (empty($receiver->fcm_token)) {
            return response()->json([
                'ok' => false,
                'error' => 'Receiver has no fcm_token',
                'receiver_id' => $receiver->id,
            ], 200);
        }

        $title = $data['sender_name'] ?? 'New message';
        $body  = $data['message'];

        // ✅ Send using your FcmService (HTTP v1)
        try {
            $res = $fcm->sendToToken(
                $receiver->fcm_token,
                $title,
                $body,
                [
                    'type' => 'chat_message',
                    'chat_id' => (string)$data['chat_id'],
                    'sender_id' => (string)($data['sender_id'] ?? ''),
                ]
            );

            // ✅ Return full debug so you know WHY it fails
            return response()->json([
                'ok' => (bool)($res['ok'] ?? false),
                'fcm' => $res,
                'receiver_id' => $receiver->id,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Chat notify exception', [
                'error' => $e->getMessage(),
                'receiver_id' => $receiver->id,
            ]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
