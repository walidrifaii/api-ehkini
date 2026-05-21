<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * POST /api/v1/media/image/upload
     * Content-Type: multipart/form-data
     * file: image
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120', // 5MB
            ],
        ]);

        $file = $request->file('image');

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();

        // storage/app/public/images
        $path = $file->storeAs('images', $filename, 'public');

        $url = asset('storage/' . $path);

        return response()->json([
            'success'   => true,
            'type'      => 'image',
            'file_name' => $filename,
            'path'      => $path,
            'url'       => $url,
            'size'      => $file->getSize(),
            'mime'      => $file->getMimeType(),
        ]);
    }

    /**
     * POST /api/v1/media/video/upload
     * Content-Type: multipart/form-data
     * file: video
     */
    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => [
                'required',
                'file',
                // common video formats
                'mimes:mp4,mov,m4v,webm,avi,mkv',
                'max:51200', // 50MB
            ],
        ]);

        $file = $request->file('video');

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();

        // storage/app/public/videos
        $path = $file->storeAs('videos', $filename, 'public');

        $url = asset('storage/' . $path);

        return response()->json([
            'success'   => true,
            'type'      => 'video',
            'file_name' => $filename,
            'path'      => $path,
            'url'       => $url,
            'size'      => $file->getSize(),
            'mime'      => $file->getMimeType(),
        ]);
    }
}
