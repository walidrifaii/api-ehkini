<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryReport;
use App\Models\StoryView;
use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use getID3;

class StoryController extends Controller
{
    /**
     * POST /api/v1/stories
     * multipart/form-data
     * media: file (image/video)
     * caption: optional
     * media_type: image|video (optional, we can detect)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Base validation
        $data = $request->validate([
            'media'      => ['required', 'file', 'max:51200'], // 50MB
            'caption'    => ['nullable', 'string', 'max:2000'],
            'media_type' => ['nullable', 'in:image,video'],
        ]);

        $file = $request->file('media');

        // Detect type
        $mime = (string) $file->getMimeType();
        $type = $data['media_type'] ?? (str_starts_with($mime, 'video/') ? 'video' : 'image');

        // Validate mimes by type
        if ($type === 'image') {
            $request->validate([
                'media' => ['mimes:jpg,jpeg,png,webp'],
            ]);
        } else {
            $request->validate([
                'media' => ['mimes:mp4,mov,m4v,webm,avi,mkv'],
            ]);
        }

        $folder = $type === 'video' ? 'stories/videos' : 'stories/images';

        if ($type === 'image') {
            $path = app(ImageCompressionService::class)->storeCompressedJpeg(
                $file,
                'public',
                $folder,
                ImageCompressionService::STORY_IMAGE_MAX_SIDE
            );
        } else {
            $ext = $file->getClientOriginalExtension();
            $filename = Str::uuid()->toString() . '.' . $ext;
            $path = $file->storeAs($folder, $filename, 'public');
        }

        if (! $path) {
            return response()->json(['message' => 'Upload failed.'], 422);
        }

        // If video => enforce <= 30 seconds using getID3
        if ($type === 'video') {
            try {
                $getID3   = new getID3;
                $fullPath = Storage::disk('public')->path($path);
                $info     = $getID3->analyze($fullPath);

                $durationSeconds = isset($info['playtime_seconds'])
                    ? (int) round($info['playtime_seconds'])
                    : 0;

                if ($durationSeconds > 30) {
                    Storage::disk('public')->delete($path);

                    return response()->json([
                        'message'          => 'Video is too long. Max 30 seconds.',
                        'duration_seconds' => $durationSeconds,
                    ], 422);
                }
            } catch (\Throwable $e) {
                Log::error('STORY_VIDEO_DURATION_GETID3_FAILED', [
                    'user_id' => $user->id,
                    'path'    => $path,
                    'error'   => $e->getMessage(),
                ]);

                Storage::disk('public')->delete($path);

                return response()->json([
                    'message' => 'Failed to validate video duration. Contact support.',
                ], 422);
            }
        }

        // Create story (24h expiry)
        $story = Story::create([
            'user_id'    => $user->id,
            'media'      => $path,
            'media_type' => $type,
            'caption'    => $data['caption'] ?? null,
            'view_count' => 0,
            'expires_at' => now()->addDay(),
            'deleted_at' => null,
        ]);

        return response()->json([
            'message' => 'Story uploaded successfully.',
            'story'   => $story,
        ], 201);
    }

    /**
     * GET /api/v1/stories
     * Return active stories (grouped Instagram-style), paginated by story rows.
     *
     * Query: page (default 1), per_page (default 30, max 100)
     */
    public function index(Request $request)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min((int) ($validated['per_page'] ?? 30), 100));
        $page = max(1, (int) ($validated['page'] ?? 1));

        $now = now();

        // ✅ get accepted friend ids
        $friendIds = \App\Models\Friendship::query()
            ->where('status', 'accepted')
            ->where(function ($q) use ($me) {
                $q->where('sender_id', $me->id)
                  ->orWhere('receiver_id', $me->id);
            })
            ->get(['sender_id', 'receiver_id'])
            ->map(function ($row) use ($me) {
                return ((int) $row->sender_id === (int) $me->id)
                    ? (int) $row->receiver_id
                    : (int) $row->sender_id;
            })
            ->unique()
            ->values()
            ->toArray();

        // ✅ include my own stories too
        $allowedUserIds = $friendIds;
        $allowedUserIds[] = (int) $me->id;
        $allowedUserIds = array_values(array_unique($allowedUserIds));

        $storyPaginator = \App\Models\Story::query()
            ->with([
                'user:id,first_name,last_name,profile_image',
            ])
            ->whereIn('user_id', $allowedUserIds)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', $now);
            })
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $stories = $storyPaginator->getCollection();

        $viewedIds = \App\Models\StoryView::query()
            ->where('user_id', $me->id)
            ->whereIn('story_id', $stories->pluck('id'))
            ->pluck('story_id')
            ->all();

        $transformed = $stories->map(function ($story) use ($me, $viewedIds) {
            $isMine = ((int) $story->user_id === (int) $me->id);

            return [
                'id' => $story->id,
                'user_id' => $story->user_id,
                'media_type' => $story->media_type,
                'media_url' => $story->media_url,
                'caption' => $story->caption,
                'view_count' => $story->view_count,
                'created_at' => $story->created_at,
                'expires_at' => $story->expires_at,

                'is_mine' => $isMine,
                'can_viewers' => $isMine,
                'is_viewed' => in_array($story->id, $viewedIds),

                'user' => [
                    'id' => $story->user->id,
                    'first_name' => $story->user->first_name,
                    'last_name' => $story->user->last_name,
                    'profile_image' => $story->user->profile_image,
                    'profile_image_url' => $story->user->profile_image_url ?? null,
                ],
            ];
        });

        $grouped = $transformed
            ->groupBy('user_id')
            ->map(function ($items) {
                return [
                    'user' => $items->first()['user'],
                    'stories' => $items->values(),
                ];
            })
            ->values();

        return response()->json([
            'stories' => $grouped,
            'meta' => [
                'current_page' => $storyPaginator->currentPage(),
                'last_page' => $storyPaginator->lastPage(),
                'per_page' => $storyPaginator->perPage(),
                'total' => $storyPaginator->total(),
                'from' => $storyPaginator->firstItem(),
                'to' => $storyPaginator->lastItem(),
            ],
        ]);
    }

    /**
     * POST /api/v1/stories/{story}/view
     * Mark story viewed by current user (count once)
     */
    public function view(Request $request, Story $story)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // story must be active
        if ($story->deleted_at || ($story->expires_at && $story->expires_at->lte(now()))) {
            return response()->json(['message' => 'Story expired or deleted.'], 404);
        }

        // do not count owner's views
        if ((int) $story->user_id === (int) $user->id) {
            return response()->json(['message' => 'Owner view ignored.']);
        }

        try {
            DB::beginTransaction();

            // prevent duplicate view
            $exists = StoryView::where('story_id', $story->id)
                ->where('user_id', $user->id)
                ->exists();

            if (!$exists) {
                StoryView::create([
                    'story_id'  => $story->id,
                    'user_id'   => $user->id,
                    'viewed_at' => now(),
                ]);

                $story->increment('view_count');
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to mark view.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message'     => 'Viewed.',
            'view_count'  => $story->fresh()->view_count,
        ]);
    }

    /**
     * GET /api/v1/stories/{story}/views
     * Return viewers list (owner only)
     */
    public function views(Request $request, Story $story)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $story->user_id !== (int) $me->id) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $views = StoryView::query()
            ->with('user:id,first_name,last_name,profile_image')
            ->where('story_id', $story->id)
            ->orderByDesc('viewed_at')
            ->get()
            ->map(function ($view) {
                $u = $view->user;

                return [
                    'user' => [
                        'id'                => $u?->id,
                        'first_name'        => $u?->first_name,
                        'last_name'         => $u?->last_name,
                        'profile_image'     => $u?->profile_image,
                        'profile_image_url' => $u?->profile_image_url,
                    ],
                    'viewed_at' => $view->viewed_at,
                ];
            });

        return response()->json([
            'story_id'   => $story->id,
            'view_count' => $story->view_count,
            'views'      => $views,
        ]);
    }

    /**
     * POST /api/v1/stories/{story}/report
     * body: { "reason": "spam", "description": "optional" }
     */
    public function report(Request $request, Story $story)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($story->deleted_at) {
            return response()->json(['message' => 'Story not found.'], 404);
        }

        if ((int) $story->user_id === (int) $me->id) {
            return response()->json(['message' => 'You cannot report your own story.'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (StoryReport::where('reporter_id', $me->id)->where('story_id', $story->id)->exists()) {
            return response()->json(['message' => 'You have already reported this story.'], 422);
        }

        $report = StoryReport::create([
            'reporter_id' => $me->id,
            'story_id' => $story->id,
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Report submitted.',
            'report_id' => $report->id,
        ], 201);
    }

    /**
     * DELETE /api/v1/stories/{story}
     */
    public function destroy(Request $request, Story $story)
    {
        $me = $request->user();

        if (!$me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $story->user_id !== (int) $me->id) {
            return response()->json(['message' => 'Not allowed.'], 403);
        } 

        try {
            DB::beginTransaction();

            if (!empty($story->media)) {
                Storage::disk('public')->delete($story->media);
            }

            $story->update([
                'deleted_at' => now(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to delete story.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message'  => 'Story deleted successfully.',
            'story_id' => $story->id,
        ]);
    }
}