<?php

namespace App\Query;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Nearby exclusions: self and blocks only. Friends stay visible in Nearby.
 */
final class DiscoverableUsersQuery
{
    public static function applyNearbyExclusions(Builder $query, User $viewer): Builder
    {
        $viewerId = (int) $viewer->id;

        return $query
            ->where('users.id', '!=', $viewerId)
            ->whereNotExists(function ($sub) use ($viewerId) {
                $sub->selectRaw('1')
                    ->from('user_blocks')
                    ->where(function ($block) use ($viewerId) {
                        $block
                            ->where(function ($q) use ($viewerId) {
                                $q->where('user_blocks.blocker_id', $viewerId)
                                    ->whereColumn('user_blocks.blocked_user_id', 'users.id');
                            })
                            ->orWhere(function ($q) use ($viewerId) {
                                $q->where('user_blocks.blocked_user_id', $viewerId)
                                    ->whereColumn('user_blocks.blocker_id', 'users.id');
                            });
                    });
            });
    }
}
