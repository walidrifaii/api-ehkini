<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Users", description="User listing, profiles, discovery, safety")
 *
 * @OA\Get(
 *     path="/api/v2/users",
 *     tags={"Users"},
 *     summary="List users (paginated)",
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20)),
 *     @OA\Response(response=200, description="User list with cursor pagination")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/users/search",
 *     tags={"Users"},
 *     summary="Search users (public; enhanced filters in v2)",
 *     @OA\Parameter(name="name", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="keyword", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="gender", in="query", @OA\Schema(type="string", enum={"male","female"})),
 *     @OA\Parameter(name="country_id", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="country", in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="age", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="min_age", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="max_age", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="interested", in="query", description="Interest IDs (comma-separated or array)", @OA\Schema(type="string")),
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Search results"),
 *     @OA\Response(response=422, description="No search criteria provided")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/users/{id}",
 *     tags={"Users"},
 *     summary="Public user profile by ID",
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="User", @OA\JsonContent(
 *         @OA\Property(property="user", ref="#/components/schemas/UserPublic")
 *     )),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/user/{id}",
 *     tags={"Users"},
 *     summary="Public user profile (alias)",
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="User")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/users/discover-by-country",
 *     tags={"Users"},
 *     summary="Discover users by country (v2, auth)",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20, maximum=100)),
 *     @OA\Response(response=200, description="Paginated discoverable users"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/users/search/last",
 *     tags={"Users"},
 *     summary="Get last saved user search filters (v2, auth)",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Saved search and clicked user IDs")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/users/search/last",
 *     tags={"Users"},
 *     summary="Clear saved user search (v2, auth)",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Cleared")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/users/search/click",
 *     tags={"Users"},
 *     summary="Record search result profile click (v2, auth)",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"user_id"},
 *             @OA\Property(property="user_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Recorded")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/users/search/click",
 *     tags={"Users"},
 *     summary="Remove user from recent search clicks (v2, auth)",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="user_id", in="query", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Removed")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/users/block",
 *     tags={"Users"},
 *     summary="Block a user",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             required={"user_id"},
 *             @OA\Property(property="user_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Blocked")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/users/unblock",
 *     tags={"Users"},
 *     summary="Unblock a user",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(@OA\JsonContent(
 *         required={"user_id"},
 *         @OA\Property(property="user_id", type="integer")
 *     )),
 *     @OA\Response(response=200, description="Unblocked")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/users/report",
 *     tags={"Users"},
 *     summary="Report a user",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             required={"user_id","reason"},
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="reason", type="string", maxLength=100),
 *             @OA\Property(property="description", type="string", maxLength=2000)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Reported")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/users/blocked",
 *     tags={"Users"},
 *     summary="List blocked users",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Blocked users list")
 * )
 */
final class UserPaths
{
}
