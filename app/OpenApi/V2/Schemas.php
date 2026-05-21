<?php

namespace App\OpenApi\V2;

/**
 * @OA\Schema(
 *     schema="MessageResponse",
 *     type="object",
 *     @OA\Property(property="message", type="string", example="OK")
 * )
 *
 * @OA\Schema(
 *     schema="ValidationError",
 *     type="object",
 *     @OA\Property(property="message", type="string", example="The given data was invalid."),
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         additionalProperties=@OA\Schema(type="array", @OA\Items(type="string"))
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="UserPublic",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="first_name", type="string"),
 *     @OA\Property(property="last_name", type="string"),
 *     @OA\Property(property="full_name", type="string"),
 *     @OA\Property(property="profile_image_url", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="AuthTokenResponse",
 *     type="object",
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="token", type="string", example="1|abc..."),
 *     @OA\Property(property="user", type="object")
 * )
 */
final class Schemas
{
}
