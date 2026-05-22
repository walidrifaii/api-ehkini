<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    public static function diskName(): string
    {
        if (config('media.disk') === 'ftp' && self::ftpConfigured()) {
            return 'media_ftp';
        }

        return 'public';
    }

    /**
     * Public URL for a stored path (e.g. profiles/abc.jpg, images/uuid.jpg).
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return rtrim(config('media.url'), '/') . '/' . ltrim($path, '/');
    }

    public static function delete(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        return Storage::disk(self::diskName())->delete($path);
    }

    /**
     * Local absolute path for tools that need a real file (e.g. getID3 on videos).
     */
    public static function localPath(string $path): string
    {
        $disk = self::diskName();

        if ($disk === 'public') {
            return Storage::disk('public')->path($path);
        }

        $contents = Storage::disk($disk)->get($path);
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . Str::uuid() . '_' . basename($path);
        file_put_contents($tmp, $contents);

        return $tmp;
    }

    public static function releaseTempPath(string $absolutePath): void
    {
        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

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
