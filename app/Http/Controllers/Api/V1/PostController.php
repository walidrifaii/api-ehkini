<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostReport;
use App\Services\ImageCompressionService;
use App\Support\MediaStorage;
use Illuminate\Http\Request;


class PostController extends Controller
{
    /**
     * POST /api/v1/posts
     * Create post with image
     */
    public function store(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $path = app(ImageCompressionService::class)->storeCompressedJpeg(
            $request->file('image'),
            MediaStorage::diskName(),
            'posts',
            ImageCompressionService::POST_MAX_SIDE
        );

        $post = Post::create([
            'user_id' => $user->id,
            'image'   => $path,
        ]);

        return response()->json([
            'message' => 'Post created successfully.',
            'post'    => $post,
        ], 201);
    }

    /**
     * GET /api/v1/users/{user}/posts
     */
    public function userPosts($userId)
    {
        $posts = Post::where('user_id', $userId)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'posts' => $posts,
        ]);
    }
    
    
    /**
 * DELETE /api/v1/posts/{post}
 * Delete post (owner only)
 */
public function destroy(Request $request, Post $post)
{
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    // ✅ only owner can delete
    if ((int)$post->user_id !== (int)$user->id) {
        return response()->json(['message' => 'Not allowed.'], 403);
    }

    // ✅ delete image file if exists
    if (!empty($post->image)) {
        MediaStorage::delete($post->image);
    }

    $post->delete();

        return response()->json([
            'message' => 'Post deleted successfully.',
        ]);
    }

    /**
     * POST /api/v1/posts/{post}/report
     * body: { "reason": "spam", "description": "optional" }
     */
    public function report(Request $request, Post $post)
    {
        $me = $request->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $post->user_id === (int) $me->id) {
            return response()->json(['message' => 'You cannot report your own post.'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (PostReport::where('reporter_id', $me->id)->where('post_id', $post->id)->exists()) {
            return response()->json(['message' => 'You have already reported this post.'], 422);
        }

        $report = PostReport::create([
            'reporter_id' => $me->id,
            'post_id' => $post->id,
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Report submitted.',
            'report_id' => $report->id,
        ], 201);
    }

}

