<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CallTokenController extends Controller
{
    /**
     * GET /api/v1/call/generate-token?channel_name=xxxx&uid=0
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'channel_name' => ['required', 'string', 'max:190'],
            'uid'          => ['nullable', 'integer', 'min:0'],
        ]);

        $channelName = $data['channel_name'];
        $uid = $data['uid'] ?? 0;

        // ✅ External PHP token generator
        $baseUrl = rtrim(config('services.call_token.base_url'), '/');
        $url = $baseUrl . '/generate_token.php';

        try {
            $response = Http::timeout(15)->get($url, [
                'channelName' => $channelName,
                'uid'         => $uid,
            ]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Token service error.',
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ], 502);
            }

            // ✅ If the PHP returns JSON already:
            $json = $response->json();

            // If it returns plain text token, handle it too:
            if (!is_array($json)) {
                return response()->json([
                    'channel_name' => $channelName,
                    'uid'          => $uid,
                    'token'        => trim((string) $response->body()),
                ]);
            }

            // Return whatever token format they send
            return response()->json($json);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate token.',
                'error'   => $e->getMessage(),
            ], 502);
        }
    }
}