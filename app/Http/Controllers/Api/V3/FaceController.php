<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Api\V2\AuthController;
use App\Models\UserFaceEmbedding;
use App\Services\FaceRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class FaceController extends AuthController
{
    public function registerFace(Request $request, FaceRecognitionService $faceService)
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:3'],
            'images.*' => ['required', 'image', 'max:8192'],
            'challenge' => ['nullable', 'string', 'in:blink,turn_left,turn_right,look_up,smile'],
            'prior_image' => ['nullable', 'image', 'max:8192'],
        ]);

        $user = $request->user();
        $challenge = $data['challenge'] ?? null;
        $priorImage = $request->file('prior_image');
        $results = [];

        foreach ($request->file('images', []) as $image) {
            try {
                $results[] = $faceService->registerEmbedding(
                    $user->id,
                    $image,
                    $challenge,
                    $priorImage
                );
            } catch (\Throwable $e) {
                Log::warning('face_register.image_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $best = $faceService->pickBestRegistrationResult($results);
        if (! $best || empty($best['embedding'])) {
            $message = $results[0]['message'] ?? 'No valid face detected';
            $issues = $results[0]['issues'] ?? [];

            return response()->json([
                'success' => false,
                'message' => $message,
                'issues' => $issues,
            ], 400);
        }

        $record = UserFaceEmbedding::query()->firstOrNew(['user_id' => $user->id]);
        $record->quality_score = $best['quality_score'] ?? null;
        $record->enrolled_at = now();
        $record->setEmbeddingVector($best['embedding']);
        $record->save();

        return response()->json([
            'success' => true,
            'message' => 'Face registered successfully.',
            'quality_score' => $best['quality_score'] ?? null,
        ]);
    }

    public function loginFace(Request $request, FaceRecognitionService $faceService)
    {
        $key = 'face-login:'.$request->ip();
        $maxAttempts = (int) config('face_recognition.login_rate_limit', 5);
        $decayMinutes = (int) config('face_recognition.login_rate_decay_minutes', 1);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many face login attempts. Please try again later.',
            ], 429);
        }

        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:6'],
            'phone' => ['required', 'string', 'max:30'],
            'image' => ['required', 'image', 'max:8192'],
            'challenge' => ['nullable', 'string', 'in:blink,turn_left,turn_right,look_up,smile'],
            'prior_image' => ['nullable', 'image', 'max:8192'],
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ]);

        $user = $this->findUserByCountryAndPhone($data['country_code'], $data['phone']);

        if (! $user) {
            RateLimiter::hit($key, $decayMinutes * 60);
            Log::warning('face_login.user_not_found', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => 'Face not recognized.',
            ], 401);
        }

        if ((int) $user->is_active === 0) {
            return response()->json(['message' => 'Account is deactivated.'], 403);
        }

        $faceProfile = UserFaceEmbedding::query()->where('user_id', $user->id)->first();
        if (! $faceProfile) {
            RateLimiter::hit($key, $decayMinutes * 60);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => 'No face profile found for this account.',
            ], 404);
        }

        $storedEmbedding = $faceProfile->getEmbeddingVector();
        if ($storedEmbedding === []) {
            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => 'Face profile is invalid. Please register your face again.',
            ], 400);
        }

        try {
            $result = $faceService->verifyEmbedding(
                $request->file('image'),
                $storedEmbedding,
                $data['challenge'] ?? null,
                $request->file('prior_image')
            );
        } catch (\Throwable $e) {
            Log::error('face_login.ai_error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Face verification service unavailable.',
            ], 503);
        }

        if (! ($result['success'] ?? false)) {
            RateLimiter::hit($key, $decayMinutes * 60);
            Log::warning('face_login.failed', [
                'user_id' => $user->id,
                'message' => $result['message'] ?? 'verification failed',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => $result['message'] ?? 'No valid face detected',
            ], 400);
        }

        $matched = (bool) ($result['matched'] ?? false);
        $confidence = (float) ($result['confidence'] ?? $result['score'] ?? 0);

        if (! $matched) {
            RateLimiter::hit($key, $decayMinutes * 60);
            Log::warning('face_login.not_matched', [
                'user_id' => $user->id,
                'confidence' => $confidence,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'matched' => false,
                'confidence' => $confidence,
            ], 401);
        }

        RateLimiter::clear($key);

        $user->tokens()->delete();

        if (! empty($data['fcm_token'])) {
            $user->update([
                'fcm_token' => $data['fcm_token'],
                'platform' => $data['platform'] ?? $user->platform,
                'token_updated_at' => now(),
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'matched' => true,
            'confidence' => $confidence,
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function challenge(FaceRecognitionService $faceService)
    {
        try {
            $challenge = $faceService->getRandomChallenge();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Face AI service unavailable.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'challenge' => $challenge,
        ]);
    }

    public function status(Request $request)
    {
        $hasFace = UserFaceEmbedding::query()
            ->where('user_id', $request->user()->id)
            ->exists();

        return response()->json([
            'success' => true,
            'has_face' => $hasFace,
        ]);
    }
}
