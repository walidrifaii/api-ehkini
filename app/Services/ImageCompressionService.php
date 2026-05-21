<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resize and JPEG-encode uploads via Intervention Image (GD driver), with fallback to raw storage.
 */
class ImageCompressionService
{
    /** Max width/height (px) for profile photos */
    public const PROFILE_MAX_SIDE = 1024;

    /** Feed post images */
    public const POST_MAX_SIDE = 1600;

    /** Story images (vertical-friendly cap) */
    public const STORY_IMAGE_MAX_SIDE = 1080;

    /** Chat / generic media uploads */
    public const MEDIA_MAX_SIDE = 1600;

    /** JPEG quality 1–100 (balance size vs. artifacts) */
    public const JPEG_QUALITY = 82;

    /**
     * Resize (fit within box), encode as JPEG, store on disk.
     * On failure or missing GD, stores the original upload unchanged.
     *
     * @return string Path relative to disk root (e.g. profiles/uuid.jpg)
     */
    public function storeCompressedJpeg(
        UploadedFile $file,
        string $disk,
        string $directory,
        int $maxSide,
        int $quality = self::JPEG_QUALITY
    ): string {
        $directory = trim($directory, '/');
        $uuid = (string) Str::uuid();
        $filename = $uuid.'.jpg';
        $relativePath = $directory.'/'.$filename;

        if (! extension_loaded('gd')) {
            return $file->store($directory, $disk);
        }

        try {
            $path = $file->getRealPath();
            if ($path === false || ! is_readable($path)) {
                return $file->store($directory, $disk);
            }

            $manager = new ImageManager(new Driver());
            $image = $manager->read($path);
            $image->scaleDown(width: $maxSide, height: $maxSide);

            $q = max(1, min(100, $quality));
            $binary = (string) $image->toJpeg($q);

            if ($binary === '') {
                return $file->store($directory, $disk);
            }

            if (! Storage::disk($disk)->put($relativePath, $binary)) {
                return $file->store($directory, $disk);
            }

            return $relativePath;
        } catch (Throwable) {
            return $file->store($directory, $disk);
        }
    }
}
