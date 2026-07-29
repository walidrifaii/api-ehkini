<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Api\V2\AuthController;
use App\Models\User;
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
        $images = array_values($request->file('images', []) ?: []);

        $results = $faceService->registerEmbeddingsParallel($user->id, $images, $challenge);

        $best = $faceService->pickBestRegistrationResult($results);
        $successCount = collect($results)
            ->filter(fn ($result) => ! empty($result['embedding'] ?? null))
            ->count();

        Log::info('face_register.batch_done', [
            'user_id' => $user->id,
            'total' => count($results),
            'success' => $successCount,
            'mode' => 'parallel',
        ]);

        if (! $best || empty($best['embedding'])) {
            $failed = collect($results)->first(
                fn ($result) => empty($result['embedding'] ?? null)
                    && (! empty($result['message'] ?? null) || ! empty($result['issues'] ?? null))
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

        $faceService->upsertGalleryEmbedding((int) $user->id, $best['embedding']);

        $avgQuality = (float) ($best['quality_score'] ?? 0);

        return response()->json([
            'success' => true,
            'message' => 'Face registered successfully.',
            'quality_score' => $best['quality_score'] ?? null,
            'frames_used' => $successCount,
            'high_quality' => $avgQuality >= 0.55,
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
        $threshold = (float) config('face_recognition.similarity_threshold', 0.55);

        $bestUser = null;
        $bestScore = 0.0;
        $matchSource = 'php';

        $identified = $faceService->identifyEmbedding($probe, $threshold);
        if (is_array($identified) && ($identified['matched'] ?? false) && ! empty($identified['user_id'])) {
            $candidate = User::query()
                ->where('id', (int) $identified['user_id'])
                ->where('is_active', 1)
                ->first();
            if ($candidate) {
                $bestUser = $candidate;
                $bestScore = (float) ($identified['score'] ?? 0);
                $matchSource = 'faiss';
            }
        }

        // Rebuild gallery once if empty, then retry identify.
        if (! $bestUser && (int) ($identified['gallery_size'] ?? 0) === 0) {
            $faceService->rebuildGalleryFromDatabase();
            $identified = $faceService->identifyEmbedding($probe, $threshold);
            if (is_array($identified) && ($identified['matched'] ?? false) && ! empty($identified['user_id'])) {
                $candidate = User::query()
                    ->where('id', (int) $identified['user_id'])
                    ->where('is_active', 1)
                    ->first();
                if ($candidate) {
                    $bestUser = $candidate;
                    $bestScore = (float) ($identified['score'] ?? 0);
                    $matchSource = 'faiss_rebuilt';
                }
            }
        }

        if (! $bestUser) {
            $local = $faceService->findBestMatchLocal($probe);
            $bestUser = $local['user'];
            $bestScore = (float) $local['score'];
            $matchSource = 'php';
        }

        if (! $bestUser || $bestScore + 0.0001 < $threshold) {
            RateLimiter::hit($key, $decayMinutes * 60);
            Log::warning('face_login.not_matched', [
                'score' => $bestScore,
                'threshold' => $threshold,
                'source' => $matchSource,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'matched' => false,
                'message' => 'Face not recognized.',
                'score' => round($bestScore, 4),
                'threshold' => $threshold,
            ], 401);
        }

        RateLimiter::clear($key);

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
            'score' => round($bestScore, 4),
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
