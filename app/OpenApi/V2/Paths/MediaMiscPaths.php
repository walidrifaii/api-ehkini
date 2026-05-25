<?php

namespace App\OpenApi\V2\Paths;

/**
 * @OA\Tag(name="Media", description="File uploads")
 * @OA\Tag(name="App", description="App config, translations, pages")
 * @OA\Tag(name="Language", description="Localization")
 * @OA\Tag(name="Agora", description="Voice/video tokens")
 *
 * @OA\Post(
 *     path="/api/v2/voice/upload",
 *     tags={"Media"},
 *     summary="Upload voice message",
 *     @OA\RequestBody(
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="audio", type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Upload URL or file info")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/media/image/upload",
 *     tags={"Media"},
 *     summary="Upload image",
 *     @OA\RequestBody(
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(@OA\Property(property="image", type="string", format="binary"))
 *         )
 *     ),
 *     @OA\Response(response=200, description="Image uploaded")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/media/video/upload",
 *     tags={"Media"},
 *     summary="Upload video",
 *     @OA\RequestBody(
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(@OA\Property(property="video", type="string", format="binary"))
 *         )
 *     ),
 *     @OA\Response(response=200, description="Video uploaded")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/app/version",
 *     tags={"App"},
 *     summary="Check app version / force update",
 *     @OA\Parameter(name="platform", in="query", @OA\Schema(type="string", enum={"android","ios"})),
 *     @OA\Parameter(name="version", in="query", @OA\Schema(type="string")),
 *     @OA\Response(response=200, description="Version check result")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/pages/{slug}",
 *     tags={"App"},
 *     summary="Get static page content",
 *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string", example="privacy")),
 *     @OA\Response(response=200, description="Page HTML or JSON content")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/translations/{lang}",
 *     tags={"App"},
 *     summary="Get translation strings",
 *     description="Returns all strings from DB (`translation_keys` + `translation_values`) for the given language code. Add a language by inserting into `languages` (is_active=1) and translation rows — no code deploy required.",
 *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string", example="fr"), description="Must match an active `languages.code` (e.g. en, ar, fr)"),
 *     @OA\Response(response=200, description="Translation key-value map (mobile + api.* keys)"),
 *     @OA\Response(response=404, description="Language not found or inactive", @OA\JsonContent(ref="#/components/schemas/MessageResponse"))
 * )
 *
 * @OA\Get(
 *     path="/api/v2/interests",
 *     tags={"App"},
 *     summary="List interests",
 *     @OA\Response(response=200, description="Interests list")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/dictionary",
 *     tags={"Language"},
 *     summary="Language dictionary",
 *     @OA\Response(response=200, description="Dictionary entries")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/languages",
 *     tags={"Language"},
 *     summary="Available languages",
 *     @OA\Response(response=200, description="Languages list")
 * )
 *
 * @OA\Post(
 *     path="/api/v2/language/change",
 *     tags={"Language"},
 *     summary="Change user language",
 *     security={{"sanctum":{}}},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="language_id", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Language changed")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/language/current",
 *     tags={"Language"},
 *     summary="Current user language",
 *     security={{"sanctum":{}}},
 *     @OA\Response(response=200, description="Current language")
 * )
 *
 * @OA\Get(
 *     path="/api/v2/agora/token",
 *     tags={"Agora"},
 *     summary="Generate Agora RTC token",
 *     @OA\Parameter(name="channel", in="query", required=true, @OA\Schema(type="string")),
 *     @OA\Parameter(name="uid", in="query", @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Token and expiry")
 * )
 */
final class MediaMiscPaths
{
}
