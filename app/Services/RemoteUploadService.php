<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uploads media to a remote upload endpoint (upload.php) protected by a bearer token.
 *
 * The endpoint accepts multipart/form-data (field "file") and responds with JSON:
 *   {"success":true,"path":"image\/<name>.jpg","category":"image"}
 * The returned "path" is relative; full URLs are built from media.remote.public_base.
 */
class RemoteUploadService
{
    public function uploadUploadedFile(UploadedFile $file, string $folder, string $fileName): string
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new \RuntimeException('Could not read uploaded file.');
        }

        return $this->uploadMultipart((string) file_get_contents($realPath), $fileName, $folder, $this->mimeFor($fileName));
    }

    public function uploadBinary(string $relativePath, string $contents): string
    {
        $relativePath = ltrim($relativePath, '/');

        return $this->uploadMultipart($contents, basename($relativePath), dirname($relativePath), $this->mimeFor($relativePath));
    }

    private function uploadMultipart(string $contents, string $fileName, string $folder, string $mime): string
    {
        $endpoint = (string) config('media.remote.endpoint');
        $token = (string) config('media.remote.token');

        if ($endpoint === '') {
            throw new \RuntimeException('Remote upload endpoint is not configured.');
        }

        $folder = trim($folder, '/');
        $form = [];
        if ($folder !== '' && $folder !== '.') {
            $form['folder'] = $folder;
        }
        if ($token !== '') {
            $form['token'] = $token;
        }

        $request = Http::timeout(120)->asMultipart();
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request
            ->attach('file', $contents, $fileName, ['Content-Type' => $mime])
            ->post($endpoint, $form);

        $json = $response->json();

        if (! $response->successful() || ! ($json['success'] ?? false)) {
            $remoteError = is_array($json) ? ($json['error'] ?? $json['message'] ?? null) : null;
            Log::error('Remote upload failed', [
                'status' => $response->status(),
                'fileName' => $fileName,
                'body' => $response->body(),
            ]);
            throw new \RuntimeException($remoteError ?: 'Remote upload failed.');
        }

        $path = $json['path'] ?? null;

        if (! $path) {
            Log::error('Remote upload returned unexpected payload', ['body' => $response->body()]);
            throw new \RuntimeException('Remote upload returned no file path.');
        }

        return $this->url(ltrim((string) $path, '/'));
    }

    /**
     * Build the public URL for a stored path.
     *
     * The endpoint returns a relative path whose first segment is the singular
     * category (e.g. "image/<file>"), while files are publicly served from the
     * pluralized folder (e.g. "/images/<file>"). This maps singular -> plural
     * and is safe to call on already-plural paths.
     */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) (config('media.remote.public_base') ?: config('media.url')), '/');

        $segments = explode('/', $path, 2);
        if (count($segments) === 2) {
            $dir = $this->publicDir($segments[0]);

            return $base.'/'.$dir.'/'.$segments[1];
        }

        return $base.'/'.$path;
    }

    /**
     * Resolve a Content-Type for the upload part from the file extension.
     *
     * The upload server validates the declared MIME type, so we must send an
     * explicit, allowed Content-Type. Notably .m4a must be sent as audio/mp4
     * (PHP usually detects it as video/mp4, which the server rejects).
     */
    private function mimeFor(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return [
            // images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            // video
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            // audio / voice
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/mp4',
        ][$ext] ?? 'application/octet-stream';
    }

    private function publicDir(string $dir): string
    {
        $dir = trim($dir, '/');
        $map = (array) config('media.remote.public_dirs', []);

        // Map the singular category (e.g. "image" -> "images", "voice" -> "voice").
        // Unknown / already-mapped segments pass through unchanged so the lookup
        // stays idempotent when called on a previously built path.
        return $map[$dir] ?? $dir;
    }

    public function delete(string $path): bool
    {
        // The remote endpoint exposes no documented delete API; nothing to do.
        Log::info('Remote upload delete skipped (no remote delete API).', ['path' => $path]);

        return false;
    }
}
