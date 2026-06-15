<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\NearbyUsersService;
use App\Services\UserLocationService;
use Illuminate\Http\Request;

class UserController extends \App\Http\Controllers\Api\V1\UserController
{
    /**
     * GET /api/v2/users/{id}
     * Public user info for chat/profile headers.
     */
    public function showPublic($id)
    {
        $user = User::query()
            ->where('is_active', 1)
            ->select(['id', 'first_name', 'last_name', 'profile_image'])
            ->find((int) $id);

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'profile_image_url' => $user->profile_image_url,
            ],
        ]);
    }

    /**
     * GET /api/v2/users/discover-by-country (auth)
     * Lists discoverable users (same rules as GET /users): excludes self, friends/requests, blocks.
     * Orders same country as the current user first; if fewer than a page in that country, fills with other countries.
     */
    public function discoverByCountry(Request $request)
    {
        $authUser = auth('sanctum')->user();
        if (! $authUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

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

        $myCountryId = $authUser->country_id;

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
            'country_id',
        ];

        $query = User::query()
            ->where('is_active', 1)
            ->select($selectFields)
            ->with(['interests:id,name'])
            ->whereNotIn('id', $excludeIds);

        if ($myCountryId !== null) {
            $query->orderByRaw('CASE WHEN country_id = ? THEN 0 ELSE 1 END', [$myCountryId]);
        }

        $users = $query->orderByDesc('id')->paginate($perPage);

        $users->getCollection()->transform(function ($user) use ($myCountryId) {
            $user->profile_image_url = $user->profile_image_url ?? null;

            $user->friendship_status = 'none';
            $user->friendship_id = null;
            $user->can_cancel = false;
            $user->can_respond = false;

            $user->is_same_country = $myCountryId !== null
                && $user->country_id !== null
                && (int) $user->country_id === (int) $myCountryId;

            return $user;
        });

        return response()->json($users);
    }

    /**
     * GET /api/v2/users/nearby (auth)
     * Straight-line distance (Haversine), optional gender filter, ordered nearest first.
     */
    public function nearby(Request $request, NearbyUsersService $nearbyUsers, UserLocationService $locationService)
    {
        $authUser = auth('sanctum')->user();
        if (! $authUser) {
            return response()->json(['message' => api_trans('unauthenticated')], 401);
        }

        if (! $authUser->location_sharing_enabled) {
            return response()->json([
                'message' => api_trans('location_sharing_required'),
            ], 422);
        }

        $data = $request->validate([
            'gender' => ['nullable', 'in:male,female'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:'.NearbyUsersService::MAX_RADIUS_KM],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $latitude = isset($data['latitude'])
            ? (float) $data['latitude']
            : ($authUser->latitude !== null ? (float) $authUser->latitude : null);
        $longitude = isset($data['longitude'])
            ? (float) $data['longitude']
            : ($authUser->longitude !== null ? (float) $authUser->longitude : null);

        if ($latitude === null || $longitude === null) {
            return response()->json([
                'message' => api_trans('location_required_for_nearby'),
            ], 422);
        }

        if (isset($data['latitude'], $data['longitude'])) {
            $locationService->enableAndUpdate($authUser, $latitude, $longitude);
            $authUser->refresh();
        }

        $users = $nearbyUsers->paginateNearby($authUser, $latitude, $longitude, $data);

        $users->getCollection()->transform(function ($user) {
            $user->profile_image_url = $user->profile_image_url ?? null;
            $user->friendship_status = 'none';
            $user->friendship_id = null;
            $user->can_cancel = false;
            $user->can_respond = false;

            $distanceKm = round((float) $user->distance_km, 1);
            $user->distance_km = $distanceKm;
            $user->distance = $distanceKm < 1.0
                ? ((int) round($distanceKm * 1000)).' m away'
                : $distanceKm.' km away';

            return $user;
        });

        return response()->json($users);
    }
}
