<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserSafetyController extends Controller
{
    /**
     * POST /api/v1/users/block
     * body: { "user_id": 12 }
     */
    public function block(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $targetUserId = (int) $data['user_id'];

        if ($targetUserId === (int) $me->id) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        try {
            DB::beginTransaction();

            // ✅ create block if not exists
            UserBlock::firstOrCreate([
                'blocker_id' => $me->id,
                'blocked_user_id' => $targetUserId,
            ]);

            // ✅ optional: remove any friendship row between both users
            Friendship::where(function ($q) use ($me, $targetUserId) {
                    $q->where('sender_id', $me->id)
                      ->where('receiver_id', $targetUserId);
                })
                ->orWhere(function ($q) use ($me, $targetUserId) {
                    $q->where('sender_id', $targetUserId)
                      ->where('receiver_id', $me->id);
                })
                ->delete();

            DB::commit();

            return response()->json([
                'message' => 'User blocked successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to block user.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/users/unblock
     * body: { "user_id": 12 }
     */
    public function unblock(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        UserBlock::where('blocker_id', $me->id)
            ->where('blocked_user_id', (int) $data['user_id'])
            ->delete();

        return response()->json([
            'message' => 'User unblocked successfully.',
        ]);
    }

    /**
     * POST /api/v1/users/report
     * body: { "user_id": 12, "reason": "spam", "description": "optional" }
     */
    public function report(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetUserId = (int) $data['user_id'];

        if ($targetUserId === (int) $me->id) {
            return response()->json(['message' => 'You cannot report yourself.'], 422);
        }

        $report = UserReport::create([
            'reporter_id' => $me->id,
            'reported_user_id' => $targetUserId,
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Report submitted successfully.',
            'report_id' => $report->id,
        ], 201);
    }

    /**
     * GET /api/v1/users/blocked
     */
    public function blockedUsers(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $items = UserBlock::with('blockedUser:id,first_name,last_name,profile_image')
            ->where('blocker_id', $me->id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($row) {
                $u = $row->blockedUser;

                return [
                    'id' => $row->id,
                    'user' => [
                        'id' => $u?->id,
                        'first_name' => $u?->first_name,
                        'last_name' => $u?->last_name,
                        'profile_image' => $u?->profile_image,
                        'profile_image_url' => $u?->profile_image_url,
                    ],
                    'created_at' => $row->created_at,
                ];
            });

        return response()->json([
            'blocked_users' => $items,
        ]);
    }
}