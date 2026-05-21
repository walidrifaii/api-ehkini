<?php

use Illuminate\Support\Facades\Route;

Route::get('/make-storage-link', function () {

    $target = storage_path('app/public');
    $link   = public_path('storage');

    // If already exists
    if (file_exists($link)) {
        return response()->json([
            'success' => true,
            'message' => 'storage link already exists',
            'link' => $link,
            'target' => $target,
        ]);
    }

    // Try to create symlink
    try {
        symlink($target, $link);

        return response()->json([
            'success' => true,
            'message' => 'storage link created successfully',
            'link' => $link,
            'target' => $target,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'link' => $link,
            'target' => $target,
        ], 500);
    }
});

