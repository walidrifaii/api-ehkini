<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallNotificationController extends Controller
{
    /**
     * POST /api/v1/call/notify
     */
    public function notify(Request $request, FcmService $fcm)
    {
        $data = $request->validate([
            'caller_id'   => ['required', 'exists:users,id'],
            'receiver_id' => ['required', 'exists:users,id'],
            'call_type'   => ['required', 'in:voice,video'],
            'channel_id'  => ['required', 'string', 'max:200'],
        ]);

        $caller   = User::find((int) $data['caller_id']);
        $receiver = User::find((int) $data['receiver_id']);

        if (! $caller || ! $receiver) {
            return response()->json(['ok' => false, 'error' => 'Caller/Receiver not found'], 404);
        }

        if (empty($receiver->fcm_token)) {
            return response()->json([
                'ok' => false,
                'error' => 'Receiver has no fcm_token',
                'receiver_id' => $receiver->id,
            ], 200);
        }

        $callerName  = trim($caller->first_name . ' ' . $caller->last_name);
        $channelName = (string) $data['channel_id'];

        // ✅ Required fields for Flutter
        $callId = $channelName; // simplest unique id
        $chatId = 'chat_' . min($caller->id, $receiver->id) . '_' . max($caller->id, $receiver->id);

        $title = '📞 Incoming ' . ($data['call_type'] === 'video' ? 'video' : 'voice') . ' call';
        $body  = $callerName . ' is calling you';

        try {
            $result = $fcm->sendToToken(
                $receiver->fcm_token,
                $title,
                $body,
                [
                    // ✅ Flutter expects EXACT keys:
                    'type'        => 'call',
                    'callId'      => (string) $callId,
                    'chatId'      => (string) $chatId,
                    'channelName' => (string) $channelName,
                    'callerId'    => (string) $caller->id,
                    'callerName'  => (string) $callerName,

                    // optional extra
                    'callType'    => (string) $data['call_type'],
                ]
            );

            return response()->json([
                'ok' => (bool) ($result['ok'] ?? false),
                'fcm' => $result,
                'debug' => [
                    'callId' => $callId,
                    'chatId' => $chatId,
                    'channelName' => $channelName,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Call notify exception', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function end(Request $request, FcmService $fcm)
{
    $data = $request->validate([
        // user who SHOULD RECEIVE the "call ended" FCM (the other side)
        'target_user_id' => ['required', 'exists:users,id'],

        // user who is ending the call (for logging / message text)
        'ender_id'       => ['required', 'exists:users,id'],

        'call_type'      => ['required', 'in:voice,video'],
        'call_id'        => ['required', 'string', 'max:200'],
        'chat_id'        => ['required', 'string', 'max:200'],
        'status'         => ['required', 'in:ended,declined,missed'],
    ]);

    $target = User::find((int) $data['target_user_id']);
    $ender  = User::find((int) $data['ender_id']);

    if (! $target || ! $ender) {
        return response()->json(['ok' => false, 'error' => 'Users not found'], 404);
    }

    if (empty($target->fcm_token)) {
        return response()->json([
            'ok' => false,
            'error' => 'Target user has no fcm_token',
            'user_id' => $target->id,
        ], 200);
    }

    $title = 'Call updated';
    $body  = 'The call was ' . $data['status'] . ' by ' . trim($ender->first_name . ' ' . $ender->last_name);

    $result = $fcm->sendToToken(
        $target->fcm_token,
        $title,
        $body,
        [
            'type'      => 'call_status',
            'status'    => (string) $data['status'],     // e.g. "ended"
            'callId'    => (string) $data['call_id'],
            'chatId'    => (string) $data['chat_id'],
            'callType'  => (string) $data['call_type'],  // "voice" / "video"
        ]
    );

    return response()->json([
        'ok'    => (bool) ($result['ok'] ?? false),
        'fcm'   => $result,
    ], 200);
}
}
