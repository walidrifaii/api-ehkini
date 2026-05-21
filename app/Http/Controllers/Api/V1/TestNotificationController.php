<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestNotificationController extends Controller
{
    public function pushAll(Request $request, FcmService $fcm)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'body'  => ['nullable', 'string', 'max:255'],
        ]);

        $title = $data['title'] ?? 'Test Push';
        $body  = $data['body'] ?? 'Hello from backend';

        $sent = 0;
        $failed = 0;
        $errors = [];

        User::query()
            ->select('id', 'fcm_token')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->chunk(200, function ($users) use ($fcm, $title, $body, &$sent, &$failed, &$errors) {
                foreach ($users as $user) {

                    $res = $fcm->sendToToken($user->fcm_token, $title, $body, [
                        'type' => 'test_push',
                        'user_id' => (string)$user->id,
                    ]);

                    Log::info('FCM TEST PUSH', [
                        'user_id' => $user->id,
                        'ok' => $res['ok'] ?? null,
                        'status' => $res['status'] ?? null,
                        'body' => $res['body'] ?? null,
                    ]);

                    if (!empty($res['ok'])) {
                        $sent++;
                    } else {
                        $failed++;
                        $errors[] = [
                            'user_id' => $user->id,
                            'status' => $res['status'] ?? null,
                            'body' => $res['body'] ?? null,
                        ];
                    }
                }
            });

        return response()->json([
            'message' => 'Test push finished (HTTP v1).',
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors, // ✅ shows why
        ]);
    }
}
