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
            'images' => ['required', 'array', 'min:1', 'max:5'],
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
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage() ?: 'No valid face detected',
                    'issues' => [],
                ];
            }
        }

        $best = $faceService->pickBestRegistrationResult($results);
        if (! $best || empty($best['embedding'])) {
            $failed = collect($results)->first(
                fn ($result) => ! empty($result['message'] ?? null) || ! empty($result['issues'] ?? null)
            ) ?? [];
            $message = $failed['message'] ?? 'No valid face detected';
            $issues = $failed['issues'] ?? [];

            return response()->json([
                'success' => false,
                'message' => $message,
                'issues' => $issues,
            ], 400);
        }

        $probeEmbeddings = collect($results)
            ->map(fn (array $result) => $result['embedding'] ?? null)
            ->filter(fn ($embedding) => is_array($embedding) && $embedding !== [])
            ->values()
            ->all();

        $duplicate = $faceService->findDuplicateFaceOwner($probeEmbeddings, $user->id);
        if ($duplicate !== null) {
            Log::warning('face_register.duplicate_blocked', [
                'user_id' => $user->id,
                'existing_user_id' => $duplicate['user_id'],
                'score' => round($duplicate['score'], 4),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This face is already linked to another account.',
                'code' => 'face_already_registered',
            ], 409);
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
            'image' => ['required', 'image', 'max:8192'],
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ]);

        try {
            $result = $faceService->extractEmbedding($request->file('image'));
        } catch (\Throwable $e) {
            Log::error('face_login.extract_error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Face verification service unavailable.',
            ], 503);
        }

        if (! ($result['success'] ?? false) || empty($result['embedding'])) {
            RateLimiter::hit($key, $decayMinutes * 60);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => $result['message'] ?? 'No valid face detected',
            ], 400);
        }

        $probe = $result['embedding'];
        // Face-ID-like tolerance for lighting / angle (0.70). Near-miss 0.88 must pass.
        $threshold = (float) config('face_recognition.similarity_threshold', 0.70);
        $profiles = UserFaceEmbedding::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->where('is_active', 1))
            ->get();

        $bestUser = null;
        $bestScore = 0.0;

        foreach ($profiles as $profile) {
            $storedEmbedding = $profile->getEmbeddingVector();
            if ($storedEmbedding === []) {
                continue;
            }

            $score = $faceService->cosineSimilarity($probe, $storedEmbedding);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUser = $profile->user;
            }
        }

        if (! $bestUser || $bestScore + 0.0001 < $threshold) {
            RateLimiter::hit($key, $decayMinutes * 60);
            Log::warning('face_login.not_matched', [
                'confidence' => $bestScore,
                'threshold' => $threshold,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => 'Face not recognized.',
                'confidence' => round($bestScore, 4),
                'threshold' => $threshold,
            ], 401);
        }

        RateLimiter::clear($key);

        // Multiple devices can stay signed in concurrently — a new login no
        // longer revokes other devices' tokens (matches phone/email login).
        $oldFcmToken = $bestUser->fcm_token;

        if (! empty($data['fcm_token'])) {
            $bestUser->update([
                'fcm_token' => $data['fcm_token'],
                'platform' => $data['platform'] ?? $bestUser->platform,
                'token_updated_at' => now(),
            ]);
        }

        $token = $bestUser->createToken('api')->plainTextToken;

        $this->notifyOtherDeviceOfNewLogin($bestUser, $oldFcmToken, $data['fcm_token'] ?? null);

        return response()->json([
            'success' => true,
            'matched' => true,
            'confidence' => round($bestScore, 4),
            'token' => $token,
            'user' => $bestUser,
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
