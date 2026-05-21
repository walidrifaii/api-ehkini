<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        $request->validate([
            'voice' => [
                'required',
                'file',
                'mimes:m4a,aac,mp3,wav,ogg,opus',
                'max:10240', // 10MB
            ],
        ]);

        $file = $request->file('voice');

        // Generate clean unique name
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();

        // Store in: storage/app/public/voices
        $path = $file->storeAs('voices', $filename, 'public');

        // Build full URL
        $url = asset('storage/' . $path);

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
