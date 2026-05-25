<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use ImageKit\ImageKit;

class ImageKitService
{
    private ImageKit $client;

    public function __construct()
    {
        $this->client = new ImageKit(
            config('media.imagekit.public_key'),
            config('media.imagekit.private_key'),
            config('media.imagekit.url_endpoint')
        );
    }

    public function uploadUploadedFile(UploadedFile $file, string $folder, string $fileName): string
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new \RuntimeException('Could not read uploaded file.');
        }

        return $this->uploadStream(fopen($realPath, 'r'), $folder, $fileName);
    }

    public function uploadBinary(string $relativePath, string $contents): string
    {
        $relativePath = ltrim($relativePath, '/');
        $folder = dirname($relativePath);
        $fileName = basename($relativePath);

        if ($folder === '.' || $folder === '') {
            return $this->uploadStream(base64_encode($contents), '', $fileName);
        }

        return $this->uploadStream(base64_encode($contents), $folder, $fileName);
    }

    /**
     * @param resource|string $file Stream resource, or base64 string for binary
     */
    private function uploadStream($file, string $folder, string $fileName): string
    {
        $folderPath = $this->folderPath($folder);

        $options = [
            'file' => $file,
            'fileName' => $fileName,
            'useUniqueFileName' => false,
        ];

        if ($folderPath !== '') {
            $options['folder'] = $folderPath;
        }

        $response = $this->client->uploadFile($options);

        if ($response->error !== null) {
            Log::error('ImageKit upload failed', ['error' => $response->error]);
            throw new \RuntimeException('ImageKit upload failed.');
        }

        $filePath = $response->result->filePath ?? $response->result->name ?? null;
        if (! $filePath) {
            throw new \RuntimeException('ImageKit upload returned no file path.');
        }

        if (config('media.store_full_url_in_db') && ! empty($response->result->url)) {
            return (string) $response->result->url;
        }

        return ltrim((string) $filePath, '/');
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $endpoint = rtrim((string) config('media.imagekit.url_endpoint'), '/');
        $prefix = trim((string) config('media.imagekit.folder_prefix'), '/');

        if ($prefix !== '' && ! str_starts_with($path, $prefix.'/')) {
            $path = $prefix.'/'.$path;
        }

        return $endpoint.'/'.$path;
    }

    public function delete(string $path): bool
    {
        $path = ltrim($path, '/');
        $fileName = basename($path);
        $dir = dirname($path);

        $parameters = [
            'searchQuery' => 'name="'.$fileName.'"',
        ];

        if ($dir !== '.' && $dir !== '') {
            $parameters['path'] = '/'.$dir;
        } else {
            $folder = $this->folderPath('');
            if ($folder !== '') {
                $parameters['path'] = $folder;
            }
        }

        $list = $this->client->listFiles($parameters);

        if ($list->error !== null) {
            Log::warning('ImageKit list before delete failed', ['path' => $path, 'error' => $list->error]);

            return false;
        }

        $files = $list->result ?? [];
        if (! is_array($files)) {
            return false;
        }

        foreach ($files as $file) {
            $filePath = ltrim((string) ($file->filePath ?? ''), '/');
            if ($filePath === $path || str_ends_with($filePath, '/'.$path)) {
                $delete = $this->client->deleteFile($file->fileId ?? null);

                return $delete->error === null;
            }
        }

        return false;
    }

    private function folderPath(string $folder): string
    {
        $prefix = trim((string) config('media.imagekit.folder_prefix'), '/');
        $folder = trim($folder, '/');

        if ($prefix === '' && $folder === '') {
            return '';
        }

        if ($prefix === '') {
            return '/'.$folder;
        }

        if ($folder === '' || $folder === '.') {
            return '/'.$prefix;
        }

        return '/'.$prefix.'/'.$folder;
    }
}
