<?php

namespace App\Services;

use App\Models\UserFaceEmbedding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FaceRecognitionService
{
    public function getRandomChallenge(): string
    {
        $response = $this->client()->get($this->url('/face/challenge'));

        if (! $response->successful()) {
            throw new RuntimeException('Face AI service unavailable.');
        }

        return (string) ($response->json('challenge') ?? 'blink');
    }

    public function registerEmbedding(int $userId, UploadedFile $image, ?string $challenge = null, ?UploadedFile $priorImage = null): array
    {
        $request = $this->client()->attach(
            'image',
            fopen($image->getRealPath(), 'r'),
            $image->getClientOriginalName()
        );

        if ($priorImage) {
            $request = $request->attach(
                'prior_image',
                fopen($priorImage->getRealPath(), 'r'),
                $priorImage->getClientOriginalName()
            );
        }

        $payload = ['user_id' => $userId];
        if ($challenge) {
            $payload['challenge'] = $challenge;
        }

        $response = $request->post($this->url('/face/register'), $payload);

        return $this->parseAiResponse($response->json(), $response->status());
    }

    public function verifyEmbedding(
        UploadedFile $image,
        array $storedEmbedding,
        ?string $challenge = null,
        ?UploadedFile $priorImage = null,
        ?UploadedFile $blinkImage = null,
    ): array {
        $request = $this->client()->attach(
            'image',
            fopen($image->getRealPath(), 'r'),
            $image->getClientOriginalName()
        );

        if ($priorImage) {
            $request = $request->attach(
                'prior_image',
                fopen($priorImage->getRealPath(), 'r'),
                $priorImage->getClientOriginalName()
            );
        }

        if ($blinkImage) {
            $request = $request->attach(
                'blink_image',
                fopen($blinkImage->getRealPath(), 'r'),
                $blinkImage->getClientOriginalName()
            );
        }

        $payload = ['stored_embedding' => json_encode($storedEmbedding)];
        if ($challenge) {
            $payload['challenge'] = $challenge;
        }

        $response = $request->post($this->url('/face/verify'), $payload);

        return $this->parseAiResponse($response->json(), $response->status());
    }

    public function pickBestRegistrationResult(array $results): ?array
    {
        $valid = array_values(array_filter($results, fn ($item) => ! empty($item['embedding'] ?? null)));
        if ($valid === []) {
            return null;
        }

        usort($valid, function (array $a, array $b) {
            $scoreA = (float) ($a['quality_score'] ?? 0);
            $scoreB = (float) ($b['quality_score'] ?? 0);
            if ($scoreA === $scoreB) {
                return (float) ($b['det_score'] ?? 0) <=> (float) ($a['det_score'] ?? 0);
            }

            return $scoreB <=> $scoreA;
        });

        return $valid[0];
    }

    public function extractEmbedding(UploadedFile $image): array
    {
        $response = $this->client()
            ->attach(
                'image',
                fopen($image->getRealPath(), 'r'),
                $image->getClientOriginalName()
            )
            ->post($this->url('/face/extract'));

        return $this->parseAiResponse($response->json(), $response->status());
    }

    public function cosineSimilarity(array $probe, array $reference): float
    {
        $length = min(count($probe), count($reference));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normProbe = 0.0;
        $normReference = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += (float) $probe[$i] * (float) $reference[$i];
            $normProbe += (float) $probe[$i] * (float) $probe[$i];
            $normReference += (float) $reference[$i] * (float) $reference[$i];
        }

        if ($normProbe <= 0.0 || $normReference <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normProbe) * sqrt($normReference));
    }

    /**
     * @return array{user_id: int, score: float}|null
     */
    public function findDuplicateFaceOwner(array $probeEmbedding, int $excludeUserId): ?array
    {
        $threshold = (float) config('face_recognition.duplicate_threshold', 0.82);

        $profiles = UserFaceEmbedding::query()
            ->where('user_id', '!=', $excludeUserId)
            ->get();

        $bestUserId = null;
        $bestScore = 0.0;

        foreach ($profiles as $profile) {
            $storedEmbedding = $profile->getEmbeddingVector();
            if ($storedEmbedding === []) {
                continue;
            }

            $score = $this->cosineSimilarity($probeEmbedding, $storedEmbedding);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUserId = (int) $profile->user_id;
            }
        }

        if ($bestUserId === null || $bestScore < $threshold) {
            return null;
        }

        return [
            'user_id' => $bestUserId,
            'score' => $bestScore,
        ];
    }

    protected function client()
    {
        $request = Http::timeout(60)->acceptJson();
        $apiKey = config('face_recognition.api_key');
        if ($apiKey) {
            $request = $request->withHeaders(['X-API-Key' => $apiKey]);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return config('face_recognition.service_url').$path;
    }

    protected function parseAiResponse(?array $payload, int $status): array
    {
        if (! is_array($payload)) {
            Log::warning('face_ai.invalid_response', ['status' => $status]);
            throw new RuntimeException('Face AI service returned an invalid response.');
        }

        if ($status === 422) {
            return [
                'success' => false,
                'message' => is_array($payload['detail'] ?? null)
                    ? ($payload['detail']['message'] ?? 'Liveness check failed')
                    : 'Liveness check failed',
            ];
        }

        if ($status >= 400) {
            $detail = $payload['detail'] ?? $payload;
            $message = is_array($detail)
                ? ($detail['message'] ?? 'No valid face detected')
                : (string) $detail;

            return [
                'success' => false,
                'message' => $message,
                'issues' => is_array($detail) ? ($detail['issues'] ?? []) : [],
            ];
        }

        return $payload;
    }
}
