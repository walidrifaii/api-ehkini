<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Agora\RtcTokenBuilder2;

class AgoraTokenController extends Controller
{
    /**
     * POST /api/v1/agora/token?channel_name=xxx&uid=0
     * Public endpoint (no auth)
     */
    public function token(Request $request)
    {
        // allow sending in query or body
        $data = $request->validate([
            'channel_name' => ['required', 'string', 'max:190'],
            'uid'          => ['nullable', 'integer', 'min:0'],
        ]);

        $channelName = (string) $data['channel_name'];
        $uid = (int) ($data['uid'] ?? 0);

        $appId = (string) env('AGORA_APP_ID');
        $appCertificate = (string) env('AGORA_APP_CERTIFICATE');
        $expiresIn = (int) env('AGORA_TOKEN_EXPIRE_SECONDS', 3600);

        $expireTs = time() + $expiresIn;

        Log::info('AGORA_TOKEN_REQUEST', [
            'channel_name' => $channelName,
            'uid' => $uid,
            'expiresIn' => $expiresIn,
            'has_app_id' => !empty($appId),
            'has_certificate' => !empty($appCertificate),
        ]);

        if (empty($appId) || empty($appCertificate)) {
            Log::error('AGORA_TOKEN_ENV_MISSING', [
                'AGORA_APP_ID' => $appId ? 'SET' : 'MISSING',
                'AGORA_APP_CERTIFICATE' => $appCertificate ? 'SET' : 'MISSING',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Missing Agora env (AGORA_APP_ID / AGORA_APP_CERTIFICATE)',
            ], 500);
        }

        try {
            // video/voice call = broadcaster
            $role = RtcTokenBuilder2::ROLE_PUBLISHER;

            $token = RtcTokenBuilder2::buildTokenWithUid(
                $appId,
                $appCertificate,
                $channelName,
                $uid,
                $role,
                $expiresIn,
                $expireTs
            );

            Log::info('AGORA_TOKEN_SUCCESS', [
                'channel_name' => $channelName,
                'uid' => $uid,
                'token_len' => strlen($token),
            ]);

            // ✅ EXACT response format you requested
            return response()->json([
                'success' => true,
                'token' => $token,
                'channelName' => $channelName,
                'uid' => $uid,
                'expiresIn' => $expiresIn,
            ]);
        } catch (\Throwable $e) {
            Log::error('AGORA_TOKEN_FAIL', [
                'channel_name' => $channelName,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token generation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}