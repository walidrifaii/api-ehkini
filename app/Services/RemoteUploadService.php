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

        return $this->uploadMultipart((string) file_get_contents($realPath), $fileName, $folder);
    }

    public function uploadBinary(string $relativePath, string $contents): string
    {
        $relativePath = ltrim($relativePath, '/');

        return $this->uploadMultipart($contents, basename($relativePath), dirname($relativePath));
    }

    private function uploadMultipart(string $contents, string $fileName, string $folder): string
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
            ->attach('file', $contents, $fileName)
            ->post($endpoint, $form);

        if (! $response->successful()) {
            Log::error('Remote upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Remote upload failed.');
        }

        $json = $response->json();
        $path = $json['path'] ?? null;

        if (! ($json['success'] ?? false) || ! $path) {
            Log::error('Remote upload returned unexpected payload', ['body' => $response->body()]);
            throw new \RuntimeException('Remote upload returned no file path.');
        }

        return ltrim((string) $path, '/');
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $base = rtrim((string) (config('media.remote.public_base') ?: config('media.url')), '/');

        return $base.'/'.$path;
    }

    public function delete(string $path): bool
    {
        // The remote endpoint exposes no documented delete API; nothing to do.
        Log::info('Remote upload delete skipped (no remote delete API).', ['path' => $path]);

        return false;
    }
}
