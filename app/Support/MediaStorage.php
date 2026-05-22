<?php

namespace App\Support;

use App\Services\ImageKitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    public static function diskName(): string
    {
        if (self::usesImageKit()) {
            return 'imagekit';
        }

        if (config('media.disk') === 'ftp' && self::ftpConfigured()) {
            return 'media_ftp';
        }

        return 'public';
    }

    public static function usesImageKit(): bool
    {
        return config('media.disk') === 'imagekit' && self::imageKitConfigured();
    }

    public static function imageKitConfigured(): bool
    {
        return filled(config('media.imagekit.public_key'))
            && filled(config('media.imagekit.private_key'))
            && filled(config('media.imagekit.url_endpoint'));
    }

    /**
     * Public URL for a stored path (e.g. profiles/abc.jpg, images/uuid.jpg).
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (self::usesImageKit()) {
            return app(ImageKitService::class)->url(ltrim($path, '/'));
        }

        return rtrim(config('media.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Save an uploaded file; returns DB path (relative).
     */
    public static function storeUploadedFile(
        UploadedFile $file,
        string $folder,
        ?string $filename = null
    ): string {
        $filename = $filename ?? Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        if (self::usesImageKit()) {
            return app(ImageKitService::class)->uploadUploadedFile($file, $folder, $filename);
        }

        return $file->storeAs($folder, $filename, self::diskName());
    }

    /**
     * Save raw bytes (e.g. compressed JPEG from ImageCompressionService).
     */
    public static function putBinary(string $relativePath, string $contents): bool
    {
        $relativePath = ltrim($relativePath, '/');

        if (self::usesImageKit()) {
            app(ImageKitService::class)->uploadBinary($relativePath, $contents);

            return true;
        }

        return Storage::disk(self::diskName())->put($relativePath, $contents);
    }

    public static function delete(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return false;
        }

        if (self::usesImageKit()) {
            return app(ImageKitService::class)->delete($path);
        }

        return Storage::disk(self::diskName())->delete($path);
    }

    /**
     * Local absolute path for tools that need a real file (e.g. getID3 on videos).
     */
    public static function localPath(string $path): string
    {
        if (self::usesImageKit()) {
            $url = self::url($path);
            $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'_'.basename($path);
            $response = Http::timeout(120)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('Could not download media from ImageKit for processing.');
            }
            file_put_contents($tmp, $response->body());

            return $tmp;
        }

        $disk = self::diskName();

        if ($disk === 'public') {
            return Storage::disk('public')->path($path);
        }

        $contents = Storage::disk($disk)->get($path);
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'_'.basename($path);
        file_put_contents($tmp, $contents);

        return $tmp;
    }

    public static function releaseTempPath(string $absolutePath): void
    {
        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $tmpDir) && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    public static function ftpConfigured(): bool
    {
        return filled(config('filesystems.disks.media_ftp.host'))
            && filled(config('filesystems.disks.media_ftp.username'));
    }
}
