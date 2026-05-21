<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request)
    {
        $authUser = auth('sanctum')->user();

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $selectFields = [
            'id',
            'first_name',
            'last_name',
            'profile_image',
            'gender',
            'location',
            'date_of_birth',
            'about_me',
            'created_at',
        ];

        /**
         * --------------------
         * GUEST MODE
         * --------------------
         */
        if (! $authUser) {
            $users = User::query()
                ->where('is_active', 1)
                ->select($selectFields)
                ->with(['interests:id,name'])
                ->orderByDesc('id')
                ->paginate($perPage);

            $users->getCollection()->transform(function ($user) {
                $user->profile_image_url = $user->profile_image_url ?? null;

                $user->friendship_status = null;
                $user->friendship_id = null;
                $user->can_cancel = false;
                $user->can_respond = false;

                return $user;
            });

            return response()->json($users);
        }

        /**
         * --------------------
         * LOGGED-IN MODE (ONLY "NONE" — no friendship row with me)
         * --------------------
         */

        $rows = Friendship::query()
            ->where(function ($q) use ($authUser) {
                $q->where('sender_id', $authUser->id)
                    ->orWhere('receiver_id', $authUser->id);
            })
            ->get(['sender_id', 'receiver_id']);

        $excludeIds = $rows->map(function ($r) use ($authUser) {
            return ((int) $r->sender_id === (int) $authUser->id)
                ? (int) $r->receiver_id
                : (int) $r->sender_id;
        })
            ->unique()
            ->values()
            ->all();

        $excludeIds[] = (int) $authUser->id;

        // user_blocks: blocker_id = who blocked, blocked_user_id = who was blocked
        $blockedByMe = UserBlock::query()
            ->where('blocker_id', $authUser->id)
            ->pluck('blocked_user_id');

        $blockedMe = UserBlock::query()
            ->where('blocked_user_id', $authUser->id)
            ->pluck('blocker_id');

        $excludeIds = array_values(array_unique(array_merge(
            $excludeIds,
            $blockedByMe->all(),
            $blockedMe->all()
        )));

        $users = User::query()
            ->where('is_active', 1)
            ->select($selectFields)
            ->with(['interests:id,name'])
            ->whereNotIn('id', $excludeIds)
            ->orderByDesc('id')
            ->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            $user->profile_image_url = $user->profile_image_url ?? null;

            $user->friendship_status = 'none';
            $user->friendship_id = null;
            $user->can_cancel = false;
            $user->can_respond = false;

            return $user;
        });

        return response()->json($users);
    }
}