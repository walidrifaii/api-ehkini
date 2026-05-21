<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Posts", description="User posts")
 * @OA\Tag(name="Stories", description="Stories (24h content)")
 *
 * @OA\Get(
 *     path="/api/v2/users/{user}/posts",
 *     tags={"Posts"},
 *     summary="Get posts for a user",
 *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Posts list")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/posts",
 *     tags={"Posts"},
 *     summary="Create post (image)",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 required={"image"},
 *                 @OA\Property(property="image", type="string", format="binary"),
 *                 @OA\Property(property="caption", type="string")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=201, description="Post created")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/posts/{post}",
 *     tags={"Posts"},
 *     summary="Delete post",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="post", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/posts/{post}/report",
 *     tags={"Posts"},
 *     summary="Report a post",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="post", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(@OA\JsonContent(@OA\Property(property="reason", type="string"))),
 *     @OA\Response(response=200, description="Reported")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/stories",
 *     tags={"Stories"},
 *     summary="List active stories",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Stories feed")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/stories",
 *     tags={"Stories"},
 *     summary="Upload story",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="media", type="string", format="binary"),
 *                 @OA\Property(property="type", type="string", enum={"image","video"})
 *             )
 *         )
 *     ),
 *     @OA\Response(response=201, description="Story created")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/stories/{story}/view",
 *     tags={"Stories"},
 *     summary="Mark story as viewed",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="story", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="View recorded")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/stories/{story}/views",
 *     tags={"Stories"},
 *     summary="List story viewers",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="story", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Viewers list")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/stories/{story}/report",
 *     tags={"Stories"},
 *     summary="Report a story",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="story", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Reported")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/stories/{story}",
 *     tags={"Stories"},
 *     summary="Delete story",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="story", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 */
final class PostStoryPaths
{
}
