<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

// Public, share-friendly profile page (what "Share profile" links to from the app).
// Deliberately minimal — only name/photo/location/bio, never phone/email.
Route::get('/profile/{id}', function (int $id) {
    $user = User::query()->where('id', $id)->where('is_active', 1)->first();

    if (! $user) {
        abort(404);
    }

    $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    $name = $name !== '' ? $name : 'Ehkini user';

    $descriptionParts = array_filter([
        trim((string) $user->location),
        trim((string) $user->about_me),
    ]);
    $description = $descriptionParts !== []
        ? implode(' · ', $descriptionParts)
        : 'View this profile on Ehkini.';

    $imageUrl = $user->profile_image_url;

    $safeName = e($name);
    $safeDescription = e($description);
    $safeImage = $imageUrl ? e($imageUrl) : null;

    $avatarHtml = $safeImage
        ? "<img src=\"{$safeImage}\" alt=\"{$safeName}\" style=\"width:120px;height:120px;border-radius:50%;object-fit:cover;\">"
        : '<div style="width:120px;height:120px;border-radius:50%;background:#eee;display:flex;align-items:center;justify-content:center;font-size:40px;color:#999;">'
            . strtoupper(substr($safeName, 0, 1)) . '</div>';

    $ogImageTag = $safeImage ? "<meta property=\"og:image\" content=\"{$safeImage}\">" : '';

    $html = <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{$safeName} on Ehkini</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta property="og:title" content="{$safeName} on Ehkini">
<meta property="og:description" content="{$safeDescription}">
{$ogImageTag}
<meta property="og:type" content="profile">
<style>
  body { font-family: Arial, sans-serif; background: #faf7f5; margin: 0; padding: 40px 20px; display: flex; justify-content: center; }
  .card { background: #fff; border-radius: 16px; padding: 32px; max-width: 360px; width: 100%; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  h1 { font-size: 20px; margin: 16px 0 4px; color: #1e2749; }
  p { color: #666; font-size: 14px; line-height: 1.5; }
</style>
</head>
<body>
  <div class="card">
    {$avatarHtml}
    <h1>{$safeName}</h1>
    <p>{$safeDescription}</p>
    <p style="margin-top:24px;font-size:12px;color:#aaa;">Shared from the Ehkini app</p>
  </div>
</body>
</html>
HTML;

    return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
});

// Old API/mobile clients used /storage/app/public/... — serve files from the real storage path.
Route::get('/storage/app/public/{path}', function (string $path) {
    $path = str_replace(['..', '\\'], '', $path);
    $fullPath = storage_path('app/public/' . $path);

    if (! File::isFile($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath);
})->where('path', '.*');

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

