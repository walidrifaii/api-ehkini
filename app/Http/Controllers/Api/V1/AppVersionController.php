<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    public function check(): JsonResponse
    {
        return response()->json([
    'latest_version_name' => env('APP_LATEST_VERSION_NAME'),
    'latest_version_code' => (int) env('APP_LATEST_VERSION_CODE'),
    'force_update' => filter_var(env('APP_FORCE_UPDATE'), FILTER_VALIDATE_BOOLEAN),
    'update_url_android' => env('APP_ANDROID_URL'),
    'update_url_ios' => env('APP_IOS_URL'),
]);

    }
}
