<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Friendship;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendshipController extends Controller
{
    /**
     * POST /api/v1/friends/request
     * body: { "receiver_id": 12 }
     */
    public function sendRequest(Request $request, FcmService $fcm)
    {
        $data = $request->validate([
            'receiver_id' => ['required', 'exists:users,id'],
        ]);

        $sender = $request->user();
        $receiverId = (int) $data['receiver_id'];

        if (! $sender) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($sender->id === $receiverId) {
            return response()->json(['message' => 'You cannot add yourself.'], 422);
        }

        // Block duplicate request (same direction)
        $existing = Friendship::where('sender_id', $sender->id)
            ->where('receiver_id', $receiverId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Request already exists.'], 422);
        }

        // Create request
        $friendship = Friendship::create([
            'sender_id'   => $sender->id,
            'receiver_id' => $receiverId,
            'status'      => 'pending',
        ]);

        $receiver = User::find($receiverId);

        // Save notification in DB (receiver)
        $notif = AppNotification::create([
            'user_id' => $receiverId,
            'type'    => 'friend_request',
            'title'   => 'New friend request',
            'body'    => $sender->first_name . ' sent you a friend request',
            'data'    => [
                'friendship_id' => $friendship->id,
                'sender_id'     => $sender->id,
            ],
            'is_read' => 0,
        ]);

        // ✅ Real-time push (ONLY if receiver has token)
        if ($receiver && !empty($receiver->fcm_token)) {
            $fcm->sendToToken(
                $receiver->fcm_token,
                $notif->title ?? 'Notification',
                $notif->body ?? '',
                [
                    'type' => 'friend_request',
                    'friendship_id' => (string) $friendship->id,
                    'sender_id' => (string) $sender->id,
                ]
            );
        }

        return response()->json([
            'message'    => 'Request sent.',
            'friendship' => $friendship,
        ], 201);
    }

    /**
     * POST /api/v1/friends/respond
     * body: { "friendship_id": 1, "action": "accept" | "reject" }
     */
   /**
 * POST /api/v1/friends/respond
 * body: { "friendship_id": 1, "action": "accept" | "reject" }
 */
public function respond(Request $request, FcmService $fcm)
{
    $data = $request->validate([
        'friendship_id' => ['required', 'exists:friendships,id'],
        'action'        => ['required', 'in:accept,reject'],
    ]);

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $friendship = Friendship::find($data['friendship_id']);

    if (! $friendship) {
        return response()->json(['message' => 'Not found.'], 404);
    }

    // Only receiver can respond
    // if ($friendship->receiver_id !== $user->id) {
    //     return response()->json(['message' => 'Not allowed.'], 403);
    // }

    if ($friendship->status !== 'pending') {
        return response()->json(['message' => 'Request already handled.'], 422);
    }

    // ✅ Update friendship status
    $friendship->status = ($data['action'] === 'accept') ? 'accepted' : 'rejected';
    $friendship->save();

    /**
     * ❌ DELETE ORIGINAL FRIEND REQUEST NOTIFICATION
     * (the one sent to the receiver)
     */
    AppNotification::where('type', 'friend_request')
        ->where('user_id', $user->id) // receiver
        ->where('data->friendship_id', $friendship->id)
        ->delete();

    /**
     * ✅ Notify sender about response
     */
    $sender = User::find($friendship->sender_id);

    $type  = ($friendship->status === 'accepted')
        ? 'friend_accepted'
        : 'friend_rejected';

    $title = ($friendship->status === 'accepted')
        ? 'Friend request accepted'
        : 'Friend request rejected';

    $body  = $user->first_name . ' ' .
        (($friendship->status === 'accepted')
            ? 'accepted your friend request'
            : 'rejected your friend request');

    $notif = AppNotification::create([
        'user_id' => $sender ? $sender->id : $friendship->sender_id,
        'type'    => $type,
        'title'   => $title,
        'body'    => $body,
        'data'    => [
            'friendship_id' => $friendship->id,
            'receiver_id'   => $user->id,
        ],
        'is_read' => 0,
    ]);

    // ✅ Real-time push (ONLY if sender has token)
    if ($sender && !empty($sender->fcm_token)) {
        $fcm->sendToToken(
            $sender->fcm_token,
            $notif->title,
            $notif->body,
            [
                'type' => $type,
                'friendship_id' => (string) $friendship->id,
                'receiver_id' => (string) $user->id,
            ]
        );
    }

    return response()->json([
        'message'    => 'Done.',
        'friendship' => $friendship,
    ]);
}


    /**
     * GET /api/v1/friends/requests
     * incoming pending requests to me
     */
    public function incomingRequests(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);

        $items = Friendship::with('sender:id,first_name,last_name,profile_image')
            ->where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        return response()->json(['requests' => $items]);
    }

    /**
     * GET /api/v1/friends
     * accepted friends
     */
public function friends(Request $request)
{
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $rows = Friendship::with([
            'sender' => function ($q) {
                $q->where('is_active', 1) // ✅ NEW
                  ->select('id', 'first_name', 'last_name', 'profile_image', 'date_of_birth', 'location');
            },
            'receiver' => function ($q) {
                $q->where('is_active', 1) // ✅ NEW
                  ->select('id', 'first_name', 'last_name', 'profile_image', 'date_of_birth', 'location');
            },
        ])
        ->where('status', 'accepted')
        ->where(function ($q) use ($user) {
            $q->where('sender_id', $user->id)
              ->orWhere('receiver_id', $user->id);
        })
        ->orderByDesc('id')
        ->get();

    $friends = $rows->map(function ($f) use ($user) {

        $other = ((int)$f->sender_id === (int)$user->id) ? $f->receiver : $f->sender;

        // ✅ if friend is inactive => relation will be null because of where(is_active,1)
        if (! $other) {
            return null;
        }

        return [
            'friendship_id' => $f->id,
            'since'         => $f->created_at,
            'user'          => [
                'id'                => $other->id,
                'first_name'        => $other->first_name,
                'last_name'         => $other->last_name,
                'profile_image_url' => $other->profile_image_url,
                'age'               => $other->age,
                'location'          => $other->location,
            ],
        ];
    })->filter()->values();

    return response()->json([
        'friends' => $friends
    ]);
}

public function friendDetails(Request $request, int $userId)
{
    $me = $request->user();
    if (! $me) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // ✅ Load user + posts + interests
    $other = User::with([
            'posts' => function ($q) {
                $q->orderByDesc('id');
            },
            'interests:id,name', // ✅ add this
        ])
        ->find($userId);

    if (! $other) {
        return response()->json(['message' => 'User not found.'], 404);
    }

    // ✅ relation status between me and this user
    $status = Friendship::getRelationStatus((int)$me->id, (int)$other->id);

    // ✅ Get friendship row (if any)
    $friendship = Friendship::where(function ($q) use ($me, $other) {
            $q->where('sender_id', $me->id)->where('receiver_id', $other->id);
        })
        ->orWhere(function ($q) use ($me, $other) {
            $q->where('sender_id', $other->id)->where('receiver_id', $me->id);
        })
        ->orderByDesc('id')
        ->first();

    return response()->json([
        'relation_status' => $status,
        'friendship_id'   => $friendship?->id,
        'since'           => $friendship?->created_at,

        // ✅ full user data (now includes interests)
        'user'  => $other->toArray(),

    ]);
}




public function searchFriends(Request $request)
{
    $me = $request->user();
    if (! $me) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $data = $request->validate([
        'name' => ['required', 'string', 'min:1', 'max:100'],
    ]);

    $search = trim($data['name']);
    $parts  = preg_split('/\s+/', $search);

    $rows = Friendship::query()
        ->where('status', 'accepted')
        ->where(function ($q) use ($me) {
            $q->where('sender_id', $me->id)
              ->orWhere('receiver_id', $me->id);
        })
        ->with([
            'sender' => function ($q) use ($search, $parts) {
                $q->where('is_active', 1) // ✅ NEW: hide deactivated
                  ->where(function ($qq) use ($search, $parts) {

                      // first or last
                      $qq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");

                      // full name
                      $qq->orWhereRaw(
                          "CONCAT(first_name, ' ', last_name) LIKE ?",
                          ["%{$search}%"]
                      );

                      // 2 words
                      if (count($parts) >= 2) {
                          $qq->orWhere(function ($q2) use ($parts) {
                              $q2->where('first_name', 'like', "%{$parts[0]}%")
                                 ->where('last_name', 'like', "%{$parts[1]}%");
                          });
                      }
                  });
            },

            'receiver' => function ($q) use ($search, $parts) {
                $q->where('is_active', 1) // ✅ NEW: hide deactivated
                  ->where(function ($qq) use ($search, $parts) {

                      $qq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");

                      $qq->orWhereRaw(
                          "CONCAT(first_name, ' ', last_name) LIKE ?",
                          ["%{$search}%"]
                      );

                      if (count($parts) >= 2) {
                          $qq->orWhere(function ($q2) use ($parts) {
                              $q2->where('first_name', 'like', "%{$parts[0]}%")
                                 ->where('last_name', 'like', "%{$parts[1]}%");
                          });
                      }
                  });
            },
        ])
        ->orderByDesc('id')
        ->get();

    $friends = $rows->map(function (Friendship $f) use ($me) {

        $other = ((int)$f->sender_id === (int)$me->id) ? $f->receiver : $f->sender;

        // ✅ if other is null => means filtered out (inactive or not matched)
        if (! $other) return null;

        return [
            'friendship_id' => $f->id,
            'since'         => $f->created_at,
            'user'          => $other->toArray(),
        ];
    })->filter()->values();

    return response()->json([
        'query'   => $search,
        'friends' => $friends,
    ]);
}

public function searchUsers(Request $request)
{
    $data = $request->validate([
        'name'   => ['required', 'string', 'min:1', 'max:100'],
        'cursor' => ['nullable', 'string'],
    ]);

    $search = trim($data['name']);
    $parts = preg_split('/\s+/', $search);

    $users = User::query()
        ->where('is_active', 1) // ✅ NEW
        ->where(function ($query) use ($search, $parts) {

            $query->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");

            $query->orWhereRaw(
                "CONCAT(first_name, ' ', last_name) LIKE ?",
                ["%{$search}%"]
            );

            if (count($parts) >= 2) {
                $query->orWhere(function ($q) use ($parts) {
                    $q->where('first_name', 'like', "%{$parts[0]}%")
                      ->where('last_name', 'like', "%{$parts[1]}%");
                });
            }
        })
        ->select('id', 'first_name', 'last_name', 'profile_image', 'location')
        ->orderByDesc('id')
        ->cursorPaginate(10);

    return response()->json($users);
}


public function suggestedFriends(Request $request)
{
    $me = $request->user();
    if (! $me) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $request->validate([
        'cursor' => ['nullable', 'string'],
    ]);

    $perPage = 10;

    $myInterestIds = $me->interests()->pluck('interests.id')->toArray();
    if (empty($myInterestIds)) {
        return response()->json([
            'data' => [],
            'next_cursor' => null,
            'prev_cursor' => null,
        ]);
    }

    // ✅ exclude only accepted/rejected + me (keep pending visible)
    $excludeIds = Friendship::query()
        ->where(function ($q) use ($me) {
            $q->where('sender_id', $me->id)
              ->orWhere('receiver_id', $me->id);
        })
        ->whereIn('status', ['accepted', 'rejected'])
        ->get(['sender_id', 'receiver_id'])
        ->map(function ($r) use ($me) {
            return ((int)$r->sender_id === (int)$me->id) ? (int)$r->receiver_id : (int)$r->sender_id;
        })
        ->unique()
        ->values()
        ->toArray();

    $excludeIds[] = (int) $me->id;
    $excludeIds = array_values(array_unique($excludeIds));

    // ✅ map latest friendship row per other user (pending maybe exists)
    $friendRows = Friendship::query()
        ->where(function ($q) use ($me) {
            $q->where('sender_id', $me->id)
              ->orWhere('receiver_id', $me->id);
        })
        ->orderByDesc('id')
        ->get(['id', 'sender_id', 'receiver_id', 'status']);

    $map = [];
    foreach ($friendRows as $r) {
        $otherId = ((int)$r->sender_id === (int)$me->id) ? (int)$r->receiver_id : (int)$r->sender_id;
        if (!isset($map[$otherId])) {
            $map[$otherId] = $r; // latest
        }
    }

    $users = User::query()
        ->select([
            'users.id',
            'users.first_name',
            'users.last_name',
            'users.profile_image',
            'users.gender',
            'users.location',
            'users.date_of_birth',
            'users.about_me',
            'users.created_at',
        ])
        ->where('users.is_active', 1) // ✅ NEW (hide deactivated users)
        ->whereNotIn('users.id', $excludeIds)
        ->whereHas('interests', function ($q) use ($myInterestIds) {
            $q->whereIn('interests.id', $myInterestIds);
        })
        ->with(['interests:id,name'])
        ->withCount([
            'interests as common_interests_count' => function ($q) use ($myInterestIds) {
                $q->whereIn('interests.id', $myInterestIds);
            }
        ])
        ->orderByDesc('common_interests_count')
        ->orderByDesc('users.id')
        ->cursorPaginate($perPage);

    // ✅ FLAT output
    $users->getCollection()->transform(function ($u) use ($me, $map) {

        $row = $map[$u->id] ?? null;

        $relationStatus = 'none';
        $friendshipId = null;

        if ($row) {
            $dbStatus = strtolower(trim((string)$row->status));

            if ($dbStatus === 'accepted') {
                $relationStatus = 'friends';
                $friendshipId = $row->id;
            } elseif ($dbStatus === 'pending') {
                $friendshipId = $row->id;
                $relationStatus = ((int)$row->sender_id === (int)$me->id)
                    ? 'outgoing_request'
                    : 'incoming_request';
            }
        }

        $arr = $u->toArray();
        $arr['profile_image_url'] = $u->profile_image_url ?? null;
        $arr['relation_status'] = $relationStatus;
        $arr['friendship_id'] = $friendshipId;

        return $arr;
    });

    return response()->json($users);
}

public function cancelRequest(Request $request)
{
    $me = $request->user();
    if (! $me) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $data = $request->validate([
        'receiver_id' => ['required', 'exists:users,id'],
    ]);

    $receiverId = (int) $data['receiver_id'];

    if ((int)$me->id === $receiverId) {
        return response()->json(['message' => 'Invalid receiver.'], 422);
    }

    // ✅ find my outgoing pending request
    $friendship = Friendship::query()
        ->where('sender_id', $me->id)
        ->where('receiver_id', $receiverId)
        ->where('status', 'pending')
        ->orderByDesc('id')
        ->first();

    if (! $friendship) {
        return response()->json([
            'message' => 'No pending request found.',
        ], 404);
    }

    try {
        DB::beginTransaction();

        // ✅ delete notification that was sent to receiver for this request
        AppNotification::where('type', 'friend_request')
            ->where('user_id', $receiverId)
            ->where('data->friendship_id', $friendship->id)
            ->delete();

        // ✅ delete the friendship row
        $friendship->delete();

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Failed to cancel request.',
            'error'   => $e->getMessage(),
        ], 422);
    }

    return response()->json([
        'message' => 'Friend request cancelled successfully.',
        'receiver_id' => $receiverId,
    ]);
}


public function removeFriend(Request $request)
{
    $me = $request->user();
    if (! $me) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $data = $request->validate([
        'user_id' => ['required', 'exists:users,id'],
    ]);

    $otherId = (int) $data['user_id'];

    if ((int)$me->id === $otherId) {
        return response()->json(['message' => 'Invalid user.'], 422);
    }

    // ✅ Find latest friendship row between the two users
    $friendship = Friendship::query()
        ->where(function ($q) use ($me, $otherId) {
            $q->where('sender_id', $me->id)->where('receiver_id', $otherId);
        })
        ->orWhere(function ($q) use ($me, $otherId) {
            $q->where('sender_id', $otherId)->where('receiver_id', $me->id);
        })
        ->orderByDesc('id')
        ->first();

    if (! $friendship) {
        return response()->json(['message' => 'No relationship found.'], 404);
    }

    if ($friendship->status !== 'accepted') {
        return response()->json([
            'message' => 'Users are not friends.',
            'status'  => $friendship->status,
        ], 422);
    }

    try {
        DB::beginTransaction();

        // ✅ Optional: remove any old notifications related to this friendship
        AppNotification::where('data->friendship_id', $friendship->id)->delete();

        // ✅ Delete friendship row => now relation becomes none
        $friendship->delete();

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Failed to remove friend.',
            'error'   => $e->getMessage(),
        ], 422);
    }

    return response()->json([
        'message'         => 'Friend removed successfully.',
        'relation_status' => 'none',
        'user_id'         => $otherId,
    ]);
}



}
