<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserLastSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FriendshipController extends \App\Http\Controllers\Api\V1\FriendshipController
{
    public function searchUsers(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string'],
            'interested' => ['nullable'],
            'intersted' => ['nullable'],
            'gender' => ['nullable', 'in:male,female'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'min_age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'max_age' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $me = $request->user('sanctum') ?? $request->user();
        $search = trim((string) (
            $data['name']
            ?? $data['keyword']
            ?? $data['q']
            ?? ''
        ));
        $parts = $search !== '' ? preg_split('/\s+/', $search) : [];
        $interestedRaw = $data['interested'] ?? $data['intersted'] ?? null;

        if (is_array($interestedRaw)) {
            $interestIds = $interestedRaw;
        } elseif (is_string($interestedRaw)) {
            $interestIds = array_filter(array_map('trim', explode(',', $interestedRaw)));
        } elseif (is_numeric($interestedRaw)) {
            $interestIds = [(string) $interestedRaw];
        } else {
            $interestIds = [];
        }

        $interestIds = array_values(array_unique(array_map('intval', $interestIds)));
        $interestIds = array_values(array_filter($interestIds, fn ($id) => $id > 0));

        $hasFilters = ! empty($data['gender'])
            || ! empty($data['country_id'])
            || ! empty(trim((string) ($data['country'] ?? '')))
            || ! empty($data['age'])
            || ! empty($data['min_age'])
            || ! empty($data['max_age'])
            || $interestIds !== [];

        if ($search === '' && ! $hasFilters) {
            return response()->json([
                'message' => 'Provide a name/keyword or at least one filter (gender, country, age, interests).',
            ], 422);
        }

        $friendMap = [];
        if ($me) {
            $friendRows = Friendship::query()
                ->where(function ($q) use ($me) {
                    $q->where('sender_id', $me->id)
                        ->orWhere('receiver_id', $me->id);
                })
                ->orderByDesc('id')
                ->get(['id', 'sender_id', 'receiver_id', 'status']);

            foreach ($friendRows as $row) {
                $otherId = ((int) $row->sender_id === (int) $me->id)
                    ? (int) $row->receiver_id
                    : (int) $row->sender_id;

                if (!isset($friendMap[$otherId])) {
                    $friendMap[$otherId] = $row; // latest relation row
                }
            }
        }

        $users = User::query()
            ->where('is_active', 1)
            ->when($search !== '', function ($query) use ($search, $parts) {
                $query->where(function ($query) use ($search, $parts) {
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
                });
            })
            ->when(!empty($interestIds), function ($query) use ($interestIds) {
                $query->whereHas('interests', function ($interestQuery) use ($interestIds) {
                    $interestQuery->whereIn('interests.id', $interestIds);
                });
            })
            ->when(!empty($data['gender']), function ($query) use ($data) {
                $query->where('gender', $data['gender']);
            })
            ->when(!empty($data['country_id']), function ($query) use ($data) {
                $query->where('country_id', (int) $data['country_id']);
            })
            ->when(empty($data['country_id']) && !empty($data['country']), function ($query) use ($data) {
                $country = trim((string) $data['country']);
                $query->whereHas('country', function ($countryQuery) use ($country) {
                    $countryQuery->where('name', 'like', "%{$country}%")
                        ->orWhere('iso2', strtoupper($country));
                });
            })
            ->when(!empty($data['age']), function ($query) use ($data) {
                $today = Carbon::today();
                $age = (int) $data['age'];
                $dobFrom = $today->copy()->subYears($age + 1)->addDay();
                $dobTo = $today->copy()->subYears($age);

                $query->whereBetween('date_of_birth', [$dobFrom->toDateString(), $dobTo->toDateString()]);
            })
            ->when(empty($data['age']) && !empty($data['min_age']), function ($query) use ($data) {
                $minAgeDate = Carbon::today()->subYears((int) $data['min_age'])->toDateString();
                $query->whereDate('date_of_birth', '<=', $minAgeDate);
            })
            ->when(empty($data['age']) && !empty($data['max_age']), function ($query) use ($data) {
                $maxAgeDate = Carbon::today()->subYears(((int) $data['max_age']) + 1)->addDay()->toDateString();
                $query->whereDate('date_of_birth', '>=', $maxAgeDate);
            })
            ->select('id', 'first_name', 'last_name', 'profile_image', 'location', 'country_id', 'date_of_birth', 'gender')
            ->orderByDesc('id')
            ->cursorPaginate(10);

        $users->getCollection()->transform(function ($user) use ($me, $friendMap) {
            $row = $friendMap[$user->id] ?? null;

            $relationStatus = 'none';
            $friendshipId = null;

            if ($me && $row) {
                $dbStatus = strtolower(trim((string) $row->status));

                if ($dbStatus === 'accepted') {
                    $relationStatus = 'friends';
                    $friendshipId = $row->id;
                } elseif ($dbStatus === 'pending') {
                    $friendshipId = $row->id;
                    $relationStatus = ((int) $row->sender_id === (int) $me->id)
                        ? 'outgoing_request'
                        : 'incoming_request';
                }
            }

            $arr = $user->toArray();
            $arr['relation_status'] = $relationStatus;
            $arr['friendship_id'] = $friendshipId;

            return $arr;
        });

        $savedSearch = null;
        $clickedUserIds = [];
        if ($me) {
            $normalized = $this->normalizedLastUserSearchPayload($data, $search, $interestIds);
            $record = UserLastSearch::query()->updateOrCreate(
                ['user_id' => $me->id],
                ['filters' => $normalized]
            );
            $savedSearch = $record->filters;
            $clickedUserIds = $record->clicked_user_ids ?? [];
        }

        $payload = $users->toArray();
        $payload['saved_search'] = $savedSearch;
        $payload['clicked_user_ids'] = $clickedUserIds;

        return response()->json($payload);
    }

    /**
     * GET /api/v2/users/search/last (auth)
     */
    public function lastUserSearch(Request $request)
    {
        $me = $request->user('sanctum') ?? $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $row = UserLastSearch::query()->where('user_id', $me->id)->first();

        return response()->json([
            'saved_search' => $row?->filters,
            'clicked_user_ids' => $row?->clicked_user_ids ?? [],
        ]);
    }

    /**
     * DELETE /api/v2/users/search/last (auth)
     */
    public function deleteLastUserSearch(Request $request)
    {
        $me = $request->user('sanctum') ?? $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        UserLastSearch::query()->where('user_id', $me->id)->delete();

        return response()->json([
            'message' => 'Saved search cleared.',
            'saved_search' => null,
            'clicked_user_ids' => [],
        ]);
    }

    /**
     * POST /api/v2/users/search/click (auth)
     * Record that the current user opened a profile from search results (by user id).
     */
    public function recordSearchResultClick(Request $request)
    {
        $me = $request->user('sanctum') ?? $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetId = (int) $data['user_id'];
        if ($targetId === (int) $me->id) {
            return response()->json(['message' => 'Invalid user.'], 422);
        }

        $target = User::query()
            ->where('is_active', 1)
            ->whereKey($targetId)
            ->first();

        if (! $target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $record = UserLastSearch::query()->firstOrNew(['user_id' => $me->id]);
        $merged = $this->mergeClickedUserIds($record->clicked_user_ids, $targetId);
        $record->clicked_user_ids = $merged;
        $record->save();

        return response()->json([
            'message' => 'Recorded.',
            'clicked_user_ids' => $record->clicked_user_ids,
        ]);
    }

    /**
     * DELETE /api/v2/users/search/click (auth)
     * Remove one user id from recent search-result clicks (clicked_user_ids).
     * Pass user_id as query (?user_id=) or JSON body.
     */
    public function deleteSearchResultClick(Request $request)
    {
        $me = $request->user('sanctum') ?? $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $removeId = (int) $data['user_id'];

        $record = UserLastSearch::query()->where('user_id', $me->id)->first();
        if (! $record) {
            return response()->json([
                'message' => 'No saved search data.',
                'clicked_user_ids' => [],
            ]);
        }

        $current = array_values(array_filter(
            array_map('intval', $record->clicked_user_ids ?? []),
            fn (int $id) => $id > 0
        ));

        if (! in_array($removeId, $current, true)) {
            return response()->json([
                'message' => 'Not in recent list.',
                'clicked_user_ids' => $current,
            ]);
        }

        $remaining = array_values(array_diff($current, [$removeId]));
        $record->clicked_user_ids = $remaining;

        $filtersEmpty = $record->filters === null
            || $record->filters === [];

        if ($remaining === [] && $filtersEmpty) {
            $record->delete();

            return response()->json([
                'message' => 'Removed from recent.',
                'clicked_user_ids' => [],
            ]);
        }

        $record->save();

        return response()->json([
            'message' => 'Removed from recent.',
            'clicked_user_ids' => $record->clicked_user_ids ?? [],
        ]);
    }

    /**
     * @param  list<int>|null  $current
     * @return list<int>
     */
    private function mergeClickedUserIds(?array $current, int $clickedId, int $max = 100): array
    {
        $list = array_values(array_filter(
            array_map('intval', $current ?? []),
            fn (int $id) => $id > 0
        ));
        $list = array_values(array_diff($list, [$clickedId]));
        array_unshift($list, $clickedId);

        return array_slice($list, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $interestIds
     * @return array<string, mixed>
     */
    private function normalizedLastUserSearchPayload(array $data, string $search, array $interestIds): array
    {
        $out = [];
        if ($search !== '') {
            $out['name'] = $search;
        }

        if ($interestIds !== []) {
            $out['interested'] = array_values($interestIds);
        }

        foreach (['gender', 'country_id', 'country', 'age', 'min_age', 'max_age'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = $data[$key];
            if ($val === null || $val === '') {
                continue;
            }
            if ($key === 'country') {
                $out[$key] = trim((string) $val);
            } elseif ($key === 'country_id') {
                $out[$key] = (int) $val;
            } elseif (in_array($key, ['age', 'min_age', 'max_age'], true)) {
                $out[$key] = (int) $val;
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
