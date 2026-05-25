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

    public static function storeFullUrlInDb(): bool
    {
        return (bool) config('media.store_full_url_in_db', false);
    }

    /**
     * Build full public URL from a relative storage path.
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
     * Value to persist in the database after upload.
     */
    public static function forDatabase(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (! self::storeFullUrlInDb()) {
            return $path;
        }

        return self::url($path) ?? $path;
    }

    /**
     * Full URL using legacy amcserver base (for one-time DB backfill).
     */
    public static function legacyFullUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        return rtrim((string) config('media.legacy_base_url'), '/').'/'.$relativePath;
    }

    /**
     * Strip known URL bases back to a relative path (for delete / storage ops).
     */
    public static function relativePath(?string $stored): ?string
    {
        if (! $stored) {
            return null;
        }

        if (! str_starts_with($stored, 'http://') && ! str_starts_with($stored, 'https://')) {
            return ltrim($stored, '/');
        }

        foreach (self::knownUrlBases() as $base) {
            $base = rtrim($base, '/');
            if (str_starts_with($stored, $base.'/')) {
                return ltrim(substr($stored, strlen($base)), '/');
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function knownUrlBases(): array
    {
        $bases = array_filter([
            config('media.legacy_base_url'),
            config('media.url'),
            config('media.imagekit.url_endpoint'),
            rtrim((string) config('media.legacy_base_url'), '/').'/public',
            'https://amcserver.com/app/taaruf/storage/app/public',
            'https://amcserver.com/app/taaruf/public',
        ]);

        return array_values(array_unique($bases));
    }

    /**
     * Save an uploaded file; returns value for DB column.
     */
    public static function storeUploadedFile(
        UploadedFile $file,
        string $folder,
        ?string $filename = null
    ): string {
        $filename = $filename ?? Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        if (self::usesImageKit()) {
            $path = app(ImageKitService::class)->uploadUploadedFile($file, $folder, $filename);
        } else {
            $path = $file->storeAs($folder, $filename, self::diskName());
        }

        return self::forDatabase($path);
    }

    /**
     * Save raw bytes; returns value for DB column.
     */
    public static function putBinary(string $relativePath, string $contents): string
    {
        $relativePath = ltrim($relativePath, '/');

        if (self::usesImageKit()) {
            $path = app(ImageKitService::class)->uploadBinary($relativePath, $contents);
        } else {
            Storage::disk(self::diskName())->put($relativePath, $contents);
            $path = $relativePath;
        }

        return self::forDatabase($path);
    }

    public static function delete(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        $relative = self::relativePath($path);

        if ($relative === null) {
            return false;
        }

        if (self::usesImageKit()) {
            return app(ImageKitService::class)->delete($relative);
        }

        return Storage::disk(self::diskName())->delete($relative);
    }

    /**
     * Local absolute path for tools that need a real file (e.g. getID3 on videos).
     */
    public static function localPath(string $path): string
    {
        $relative = self::relativePath($path) ?? $path;

        if (self::usesImageKit() || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $url = self::url($relative) ?? $path;
            $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'_'.basename($relative);
            $response = Http::timeout(120)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('Could not download media for processing.');
            }
            file_put_contents($tmp, $response->body());

            return $tmp;
        }

        $disk = self::diskName();

        if ($disk === 'public') {
            return Storage::disk('public')->path($relative);
        }

        $contents = Storage::disk($disk)->get($relative);
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'_'.basename($relative);
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
