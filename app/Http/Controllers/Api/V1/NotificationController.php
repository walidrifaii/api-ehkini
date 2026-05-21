<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);

        $items = AppNotification::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['notifications' => $items]);
    }

    /**
     * ✅ GET /api/v1/notifications/friend-requests
     * Only received friend requests + sender info
     */
    public function friendRequests(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);

        // Get friend_request notifications for this user
        $items = AppNotification::where('user_id', $user->id)
            ->where('type', 'friend_request')
            ->orderByDesc('id')
            ->get();

        // Collect sender ids from data->sender_id
        $senderIds = $items->pluck('data.sender_id')->filter()->unique()->values()->all();

        // Load senders in 1 query
        $senders = User::whereIn('id', $senderIds)
            ->get(['id', 'first_name', 'last_name', 'profile_image'])
            ->keyBy('id');

        // Build response
        $result = $items->map(function ($n) use ($senders) {
            $senderId = $n->data['sender_id'] ?? null;
            $sender = $senderId ? ($senders[$senderId] ?? null) : null;

            return [
                'notification_id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'is_read' => (bool) $n->is_read,
                'created_at' => $n->created_at,

                // From notification data
                'friendship_id' => $n->data['friendship_id'] ?? null,
                'sender_id' => $senderId,

                // Sender info (what you requested)
                'sender' => $sender ? [
                    'id' => $sender->id,
                    'first_name' => $sender->first_name,
                    'last_name'  => $sender->last_name,
                    'profile_image_url' => $sender->profile_image_url,
                ] : null,
            ];
        });

        return response()->json([
            'requests' => $result
        ]);
    }

    /**
     * POST /api/v1/notifications/read
     * body: { "notification_id": 1 }
     */
    public function markRead(Request $request)
    {
        $data = $request->validate([
            'notification_id' => ['required', 'exists:notifications,id'],
        ]);

        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);

        $n = AppNotification::where('id', $data['notification_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $n) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $n->is_read = true;
        $n->save();

        return response()->json(['message' => 'Updated.']);
    }
    
    
    /**
 * DELETE /api/v1/notifications/{notification}
 * Delete notification (owner only)
 */
public function destroy(Request $request, AppNotification $notification)
{
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // ✅ Only owner can delete
    if ((int)$notification->user_id !== (int)$user->id) {
        return response()->json(['message' => 'Not allowed.'], 403);
    }

    $notification->delete();

    return response()->json([
        'message' => 'Notification deleted successfully.',
        'notification_id' => $notification->id,
    ]);
}
}
