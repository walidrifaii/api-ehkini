<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Friendship;
use App\Models\GiftTransaction;
use App\Models\Post;
use App\Models\PostReport;
use App\Models\Story;
use App\Models\StoryReport;
use App\Models\StoryView;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserFaceEmbedding;
use App\Models\UserLastSearch;
use App\Models\UserReport;
use App\Models\UserWallet;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\DB;

class UserAccountPurgeService
{
    /**
     * Permanently remove a user and related MySQL rows.
     * Media files are deleted after the DB commit when possible.
     */
    public function purge(User $user): void
    {
        $userId = (int) $user->id;
        $mediaPaths = [];

        if ($user->profile_image) {
            $mediaPaths[] = $user->profile_image;
        }

        $postIds = Post::where('user_id', $userId)->pluck('id');
        $storyIds = Story::where('user_id', $userId)->pluck('id');

        foreach (Post::where('user_id', $userId)->pluck('image') as $path) {
            if ($path) {
                $mediaPaths[] = $path;
            }
        }

        foreach (Story::where('user_id', $userId)->pluck('media') as $path) {
            if ($path) {
                $mediaPaths[] = $path;
            }
        }

        DB::transaction(function () use ($user, $userId, $postIds, $storyIds) {
            if ($storyIds->isNotEmpty()) {
                StoryView::whereIn('story_id', $storyIds)->delete();
                StoryReport::whereIn('story_id', $storyIds)->delete();
            }

            StoryView::where('user_id', $userId)->delete();
            StoryReport::where('reporter_id', $userId)->delete();
            Story::where('user_id', $userId)->delete();

            if ($postIds->isNotEmpty()) {
                PostReport::whereIn('post_id', $postIds)->delete();
            }

            PostReport::where('reporter_id', $userId)->delete();
            Post::where('user_id', $userId)->delete();

            AppNotification::where('user_id', $userId)->delete();

            Friendship::where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })->delete();

            UserBlock::where(function ($q) use ($userId) {
                $q->where('blocker_id', $userId)->orWhere('blocked_user_id', $userId);
            })->delete();

            UserReport::where(function ($q) use ($userId) {
                $q->where('reporter_id', $userId)->orWhere('reported_user_id', $userId);
            })->delete();

            GiftTransaction::where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })->delete();

            DB::table('user_interests')->where('user_id', $userId)->delete();
            UserWallet::where('user_id', $userId)->delete();
            UserLastSearch::where('user_id', $userId)->delete();
            UserFaceEmbedding::where('user_id', $userId)->delete();

            $user->tokens()->delete();
            $user->delete();
        });

        foreach (array_unique($mediaPaths) as $path) {
            try {
                MediaStorage::delete($path);
            } catch (\Throwable $e) {
                // Best-effort media cleanup; DB row is already gone.
            }
        }
    }
}
