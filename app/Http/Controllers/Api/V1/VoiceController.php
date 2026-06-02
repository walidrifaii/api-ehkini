<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoiceController extends Controller
{
    /**
     * POST /api/v1/voice/upload
     * Content-Type: multipart/form-data
     * file: voice
     */
    public function upload(Request $request)
    {
        // Accept any audio file; the upload server enforces the real type by
        // content. Strict mimes:m4a often fails because PHP detects m4a as
        // video/mp4 or application/octet-stream.
        $request->validate([
            'voice' => [
                'required',
                'file',
                'max:10240', // 10MB
            ],
        ]);

        $file = $request->file('voice');

        $extension = $file->getClientOriginalExtension()
            ?: ($file->guessExtension() ?: 'm4a');

        $filename = Str::uuid()->toString() . '.' . $extension;

        try {
            $path = MediaStorage::storeUploadedFile($file, 'voices', $filename);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Voice upload failed.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $url = MediaStorage::url($path);

        return response()->json([
            'success' => true,
            'file_name' => $filename,
            'path' => $path,
            'url' => $url,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
    }
}
