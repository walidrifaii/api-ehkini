<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Notifications", description="In-app and push notifications")
 *
 * @OA\Get(
 *     path="/api/v2/notifications",
 *     tags={"Notifications"},
 *     summary="List notifications",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="cursor", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Notifications list")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/notifications/read",
 *     tags={"Notifications"},
 *     summary="Mark notifications as read",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="notification_ids", type="array", @OA\Items(type="integer")),
 *             @OA\Property(property="all", type="boolean")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Marked read")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/notifications/{notification}",
 *     tags={"Notifications"},
 *     summary="Delete notification",
 *     security={{"sanctum":{}}},
 *     @OA\Parameter(name="notification", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/test-notification",
 *     tags={"Notifications"},
 *     summary="Send test push to current user",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/test-notification-all",
 *     tags={"Notifications"},
 *     summary="Broadcast test push (admin/dev)",
 *     @OA\Response(response=200, description="Broadcast sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/chat/notify",
 *     tags={"Notifications"},
 *     summary="Send chat message notification",
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="receiver_id", type="integer"),
 *             @OA\Property(property="sender_id", type="integer"),
 *             @OA\Property(property="message", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Notification sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/call/notify",
 *     tags={"Notifications"},
 *     summary="Incoming call notification",
 *     @OA\RequestBody(@OA\JsonContent(type="object")),
 *     @OA\Response(response=200, description="Sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/call/end",
 *     tags={"Notifications"},
 *     summary="Call ended notification",
 *     @OA\RequestBody(@OA\JsonContent(type="object")),
 *     @OA\Response(response=200, description="Sent")
 * )
 */
final class NotificationPaths
{
}
