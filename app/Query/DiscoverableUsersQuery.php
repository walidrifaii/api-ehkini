<?php

namespace App\Query;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Excludes self, friendships, and blocks using NOT EXISTS (scales better than large whereNotIn).
 */
final class DiscoverableUsersQuery
{
    public static function applySocialExclusions(Builder $query, User $viewer): Builder
    {
        $viewerId = (int) $viewer->id;

        return $query
            ->where('users.id', '!=', $viewerId)
            ->whereNotExists(function ($sub) use ($viewerId) {
                $sub->selectRaw('1')
                    ->from('friendships')
                    ->where(function ($friendship) use ($viewerId) {
                        $friendship
                            ->where(function ($q) use ($viewerId) {
                                $q->whereColumn('friendships.sender_id', 'users.id')
                                    ->where('friendships.receiver_id', $viewerId);
                            })
                            ->orWhere(function ($q) use ($viewerId) {
                                $q->whereColumn('friendships.receiver_id', 'users.id')
                                    ->where('friendships.sender_id', $viewerId);
                            });
                    });
            })
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
