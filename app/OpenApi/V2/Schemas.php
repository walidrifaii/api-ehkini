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
 *
 * @OA\Schema(
 *     schema="ProfileUpdateJsonRequest",
 *     type="object",
 *     description="All fields optional. Send only fields you want to update.",
 *     @OA\Property(property="country_id", type="integer", example=1, nullable=true),
 *     @OA\Property(property="first_name", type="string", maxLength=100),
 *     @OA\Property(property="last_name", type="string", maxLength=100),
 *     @OA\Property(
 *         property="date_of_birth",
 *         type="string",
 *         format="date",
 *         example="1995-06-15",
 *         description="Must be at least 18 years ago (YYYY-MM-DD)."
 *     ),
 *     @OA\Property(property="location", type="string", maxLength=255, example="Beirut, Lebanon", description="Free text location."),
 *     @OA\Property(property="bio", type="string", maxLength=2000, description="Alias for about_me (profile bio)."),
 *     @OA\Property(property="about_me", type="string", maxLength=2000, description="Profile bio (same as bio)."),
 *     @OA\Property(property="gender", type="string", enum={"male","female"}),
 *     @OA\Property(property="occupation", type="string", maxLength=150),
 *     @OA\Property(property="education", type="string", maxLength=150),
 *     @OA\Property(property="interests", type="array", @OA\Items(type="integer"), description="Interest IDs from interests table.")
 * )
 *
 * @OA\Schema(
 *     schema="ProfileUpdateResponse",
 *     type="object",
 *     @OA\Property(property="message", type="string", example="Profile updated successfully."),
 *     @OA\Property(
 *         property="user",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="first_name", type="string"),
 *         @OA\Property(property="last_name", type="string"),
 *         @OA\Property(property="date_of_birth", type="string", format="date", nullable=true),
 *         @OA\Property(property="age", type="integer", nullable=true),
 *         @OA\Property(property="location", type="string", nullable=true),
 *         @OA\Property(property="about_me", type="string", nullable=true),
 *         @OA\Property(property="bio", type="string", nullable=true, description="Same value as about_me."),
 *         @OA\Property(property="profile_image_url", type="string", nullable=true),
 *         @OA\Property(property="country_id", type="integer", nullable=true)
 *     )
 * )
 */
final class Schemas
{
}
