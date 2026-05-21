<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Friends", description="Friend requests and friendships")
 *
 * @OA\Post(
 *     path="/api/v2/friends/request",
 *     tags={"Friends"},
 *     summary="Send friend request",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"receiver_id"},
 *             @OA\Property(property="receiver_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Request sent"),
 *     @OA\Response(response=422, description="Invalid or duplicate request")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/friends/respond",
 *     tags={"Friends"},
 *     summary="Accept or reject friend request",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"friendship_id","action"},
 *             @OA\Property(property="friendship_id", type="integer"),
 *             @OA\Property(property="action", type="string", enum={"accept","reject"})
 *         )
 *     ),
 *     @OA\Response(response=200, description="Updated")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/friends/requests",
 *     tags={"Friends"},
 *     summary="Incoming friend requests",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Pending requests")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/friends/requests/cancel",
 *     tags={"Friends"},
 *     summary="Cancel outgoing friend request",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             required={"receiver_id"},
 *             @OA\Property(property="receiver_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/friends/remove",
 *     tags={"Friends"},
 *     summary="Remove friend",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             required={"user_id"},
 *             @OA\Property(property="user_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Removed")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/friends",
 *     tags={"Friends"},
 *     summary="List friends",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Friends list")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/friends/search",
 *     tags={"Friends"},
 *     summary="Search within friends",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="name", in="query", required=true, @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Matching friends")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/friends/suggested",
 *     tags={"Friends"},
 *     summary="Suggested friends by shared interests",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Suggestions")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/friends/{userId}",
 *     tags={"Friends"},
 *     summary="Friend details",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Friend profile")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/v1/friends/{userId}",
 *     tags={"Friends"},
 *     summary="Friend details (legacy path alias)",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Friend profile")
 * )
 */
final class FriendshipPaths
{
}
