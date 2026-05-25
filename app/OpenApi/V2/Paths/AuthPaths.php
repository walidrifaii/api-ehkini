<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Auth", description="Registration, login, password recovery, profile")
 * @OA\Tag(name="Countries", description="Country list (v2)")
 *
 * @OA\Post(
 *     path="/api/v2/register",
 *     tags={"Auth"},
 *     summary="Register (complete flow alias)",
 *     description="Same as POST /register/complete — full registration after OTP verification.",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"first_name","last_name","country_code","phone","password","verified_token","date_of_birth"},
 *             @OA\Property(property="first_name", type="string"),
 *             @OA\Property(property="last_name", type="string"),
 *             @OA\Property(property="country_code", type="string", example="+961"),
 *             @OA\Property(property="country_id", type="integer", nullable=true),
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="password", type="string", format="password"),
 *             @OA\Property(property="verified_token", type="string"),
 *             @OA\Property(property="date_of_birth", type="string", format="date"),
 *             @OA\Property(property="gender", type="string", enum={"male","female"}),
 *             @OA\Property(property="location", type="string"),
 *             @OA\Property(property="occupation", type="string"),
 *             @OA\Property(property="education", type="string"),
 *             @OA\Property(property="about_me", type="string"),
 *             @OA\Property(property="fcm_token", type="string"),
 *             @OA\Property(property="platform", type="string", enum={"android","ios","web"}),
 *             @OA\Property(property="interests", type="array", @OA\Items(type="integer"))
 *         )
 *     ),
 *     @OA\Response(response=201, description="Account created", @OA\JsonContent(ref="#/components/schemas/AuthTokenResponse")),
 *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
 * )
 *
 * @OA\Post(
 *     path="/api/v2/register/send-otp",
 *     tags={"Auth"},
 *     summary="Send registration OTP (v2)",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"country_code","phone"},
 *             @OA\Property(property="country_code", type="string"),
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP sent"),
 *     @OA\Response(response=422, description="Phone already exists")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/register/verify-otp",
 *     tags={"Auth"},
 *     summary="Verify registration OTP (v2)",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"country_code","phone","otp_token","code"},
 *             @OA\Property(property="country_code", type="string"),
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp_token", type="string"),
 *             @OA\Property(property="code", type="string", example="123456")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP verified")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/register/complete",
 *     tags={"Auth"},
 *     summary="Complete registration after OTP (v2)",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"first_name","last_name","country_code","phone","password","verified_token","date_of_birth"},
 *             @OA\Property(property="first_name", type="string"),
 *             @OA\Property(property="last_name", type="string"),
 *             @OA\Property(property="country_code", type="string"),
 *             @OA\Property(property="country_id", type="integer", nullable=true),
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="password", type="string"),
 *             @OA\Property(property="verified_token", type="string"),
 *             @OA\Property(property="date_of_birth", type="string", format="date"),
 *             @OA\Property(property="gender", type="string", enum={"male","female"}),
 *             @OA\Property(property="interests", type="array", @OA\Items(type="integer"))
 *         )
 *     ),
 *     @OA\Response(response=201, description="Created", @OA\JsonContent(ref="#/components/schemas/AuthTokenResponse"))
 * )
 *
 * @OA\Post(
 *     path="/api/v2/login",
 *     tags={"Auth"},
 *     summary="Login",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"country_code","phone","password"},
 *             example={
 *                 "country_code": "+961",
 *                 "phone": "70657961",
 *                 "password": "your_password"
 *             },
 *             @OA\Property(property="country_code", type="string", example="+961", description="E.164 prefix, e.g. +961"),
 *             @OA\Property(property="phone", type="string", example="70657961", description="National number without country prefix"),
 *             @OA\Property(property="password", type="string", format="password", example="your_password"),
 *             @OA\Property(property="fcm_token", type="string", nullable=true),
 *             @OA\Property(property="platform", type="string", enum={"android","ios","web"}, nullable=true),
 *             @OA\Property(property="location", type="string", nullable=true)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Success", @OA\JsonContent(ref="#/components/schemas/AuthTokenResponse")),
 *     @OA\Response(response=401, description="Invalid credentials"),
 *     @OA\Response(response=422, description="Validation error (invalid JSON body or missing fields)", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
 * )
 *
 * @OA\Post(
 *     path="/api/v2/check-phone",
 *     tags={"Auth"},
 *     summary="Check if phone exists and age eligibility",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"country_code","phone","date_of_birth"},
 *             @OA\Property(property="country_code", type="string"),
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="date_of_birth", type="string", format="date")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Check result")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/forgot-password/send-otp",
 *     tags={"Auth"},
 *     summary="Send forgot-password OTP",
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="country_code", type="string"),
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/forgot-password/verify-otp",
 *     tags={"Auth"},
 *     summary="Verify forgot-password OTP",
 *     @OA\Response(response=200, description="Verified")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/forgot-password/reset-password",
 *     tags={"Auth"},
 *     summary="Reset password after OTP",
 *     @OA\Response(response=200, description="Password reset")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/countries",
 *     tags={"Countries"},
 *     summary="List countries (v2)",
 *     @OA\Response(response=200, description="Country list")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/me",
 *     tags={"Auth"},
 *     summary="Current authenticated user",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="User profile"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/logout",
 *     tags={"Auth"},
 *     summary="Logout (revoke token)",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/account/deactivate",
 *     tags={"Auth"},
 *     summary="Deactivate account (soft delete)",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Account deactivated")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/profile/update",
 *     tags={"Auth"},
 *     summary="Update profile",
 *     description="Update bio, birthday, location (text), name, gender, country, interests, or profile photo. All fields are optional.

**Validation rules**
| Field | Rule |
|-------|------|
| date_of_birth | Valid date; user must be **at least 18** years old |
| bio / about_me | Max **2000** characters |
| location | Free text, max **255** characters |
| profile_image | Multipart only: JPG, JPEG, PNG, WEBP; max **2 MB (2048 KB)** |

Optional header `X-App-Language: en` or `ar` (or `?lang=ar`) for localized validation messages.",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/ProfileUpdateJsonRequest")
 *         ),
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 allOf={@OA\Schema(ref="#/components/schemas/ProfileUpdateJsonRequest")},
 *                 @OA\Property(
 *                     property="profile_image",
 *                     type="string",
 *                     format="binary",
 *                     description="Profile photo. Max 2 MB (2048 KB). Allowed: JPG, JPEG, PNG, WEBP."
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Profile updated successfully", @OA\JsonContent(ref="#/components/schemas/ProfileUpdateResponse")),
 *     @OA\Response(response=401, description="Unauthenticated", @OA\JsonContent(ref="#/components/schemas/MessageResponse")),
 *     @OA\Response(response=403, description="Account deactivated", @OA\JsonContent(ref="#/components/schemas/MessageResponse")),
 *     @OA\Response(
 *         response=422,
 *         description="Validation error (e.g. under 18, image too large, invalid image type)",
 *         @OA\JsonContent(
 *             ref="#/components/schemas/ValidationError",
 *             @OA\Examples(
 *                 example="date_of_birth_under_18",
 *                 summary="Age under 18",
 *                 value={
 *                     "message": "The given data was invalid.",
 *                     "errors": {
 *                         "date_of_birth": {
 *                             "You must be at least 18 years old to use this service."
 *                         }
 *                     }
 *                 }
 *             ),
 *             @OA\Examples(
 *                 example="profile_image_too_large",
 *                 summary="Profile image exceeds max size",
 *                 value={
 *                     "message": "The given data was invalid.",
 *                     "errors": {
 *                         "profile_image": {
 *                             "Profile image is too large. Maximum allowed size is 2 MB (2048 KB)."
 *                         }
 *                     }
 *                 }
 *             ),
 *             @OA\Examples(
 *                 example="profile_image_invalid_type",
 *                 summary="Invalid profile image format",
 *                 value={
 *                     "message": "The given data was invalid.",
 *                     "errors": {
 *                         "profile_image": {
 *                             "Profile image must be JPG, JPEG, PNG, or WEBP."
 *                         }
 *                     }
 *                 }
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Post(
 *     path="/api/v2/profile/phone/send-otp-new",
 *     tags={"Auth"},
 *     summary="Send OTP to change phone number",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/profile/phone/confirm-new",
 *     tags={"Auth"},
 *     summary="Confirm new phone with OTP",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Phone updated")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/profile/password/send-otp",
 *     tags={"Auth"},
 *     summary="Send OTP to change password",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/profile/password/update",
 *     tags={"Auth"},
 *     summary="Update password with OTP",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Password updated")
 * )
 *
 * @OA\Delete(
 *     path="/api/v2/profile/image",
 *     tags={"Auth"},
 *     summary="Delete profile image",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Image removed")
 * )
 */
final class AuthPaths
{
}
