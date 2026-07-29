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

    public function registerEmbeddingsParallel(int $userId, array $images, ?string $challenge = null): array
    {
        if ($images === []) {
            return [];
        }

        $apiKey = config('face_recognition.api_key');
        $url = $this->url('/face/register');

        $responses = Http::pool(function ($pool) use ($images, $userId, $challenge, $apiKey, $url) {
            $requests = [];
            foreach ($images as $index => $image) {
                $pending = $pool->as((string) $index)
                    ->timeout(120)
                    ->acceptJson();

                if ($apiKey) {
                    $pending = $pending->withHeaders(['X-API-Key' => $apiKey]);
                }

                $pending = $pending->attach(
                    'image',
                    fopen($image->getRealPath(), 'r'),
                    $image->getClientOriginalName() ?: ('face_'.$index.'.jpg')
                );

                $payload = ['user_id' => $userId];
                if ($challenge) {
                    $payload['challenge'] = $challenge;
                }

                $requests[] = $pending->post($url, $payload);
            }

            return $requests;
        });

        $results = [];
        foreach ($images as $index => $image) {
            $response = $responses[(string) $index] ?? null;
            try {
                if ($response === null) {
                    $results[] = [
                        'success' => false,
                        'message' => 'Face AI service unavailable.',
                        'issues' => [],
                    ];
                    continue;
                }

                if ($response instanceof \Throwable) {
                    throw $response;
                }

                $results[] = $this->parseAiResponse($response->json(), $response->status());
            } catch (\Throwable $e) {
                Log::warning('face_register.parallel_image_failed', [
                    'user_id' => $userId,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage() ?: 'No valid face detected',
                    'issues' => [],
                ];
            }
        }

        return $results;
    }

    public function identifyEmbedding(array $probe, ?float $threshold = null): ?array
    {
        $payload = ['embedding' => array_values($probe)];
        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }

        try {
            $response = $this->client()->post($this->url('/face/identify'), $payload);
            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json)) {
                return null;
            }

            return $json;
        } catch (\Throwable $e) {
            Log::warning('face_identify.failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function upsertGalleryEmbedding(int $userId, array $embedding): void
    {
        try {
            $this->client()->post($this->url('/face/gallery/upsert'), [
                'user_id' => $userId,
                'embedding' => array_values($embedding),
            ]);
        } catch (\Throwable $e) {
            Log::warning('face_gallery.upsert_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function rebuildGalleryFromDatabase(): int
    {
        $items = [];
        $profiles = UserFaceEmbedding::query()->get(['user_id', 'embedding']);
        foreach ($profiles as $profile) {
            $vector = $profile->getEmbeddingVector();
            if ($vector === []) {
                continue;
            }
            $items[] = [
                'user_id' => (int) $profile->user_id,
                'embedding' => array_values($vector),
            ];
        }

        try {
            $response = $this->client()->post($this->url('/face/gallery/rebuild'), [
                'items' => $items,
            ]);
            if (! $response->successful()) {
                return 0;
            }

            return (int) ($response->json('size') ?? count($items));
        } catch (\Throwable $e) {
            Log::warning('face_gallery.rebuild_failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Brute-force PHP fallback when FAISS gallery is empty / unavailable.
     *
     * @return array{user: \App\Models\User|null, score: float}
     */
    public function findBestMatchLocal(array $probe): array
    {
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

            $score = $this->cosineSimilarity($probe, $storedEmbedding);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUser = $profile->user;
            }
        }

        return ['user' => $bestUser, 'score' => $bestScore];
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

        $best = $valid[0];
        $averaged = $this->averageEmbeddings(
            array_map(fn (array $item) => $item['embedding'], $valid)
        );

        // Prefer the sharpest frame, lightly blended with the pose average so
        // frontal login still matches after multi-angle enroll.
        if ($averaged !== [] && is_array($best['embedding'] ?? null)) {
            $best['embedding'] = $this->blendEmbeddings($best['embedding'], $averaged, 0.75);
        }

        $best['quality_score'] = collect($valid)
            ->avg(fn (array $item) => (float) ($item['quality_score'] ?? 0));

        return $best;
    }

    /**
     * @param  array<int, float|int|string>  $primary
     * @param  array<int, float|int|string>  $secondary
     * @return array<int, float>
     */
    public function blendEmbeddings(array $primary, array $secondary, float $primaryWeight = 0.75): array
    {
        $a = array_map('floatval', array_values($primary));
        $b = array_map('floatval', array_values($secondary));
        $dim = min(count($a), count($b));
        if ($dim === 0) {
            return $a !== [] ? $a : $b;
        }

        $w = max(0.0, min(1.0, $primaryWeight));
        $out = [];
        for ($i = 0; $i < $dim; $i++) {
            $out[] = ($a[$i] * $w) + ($b[$i] * (1.0 - $w));
        }

        $norm = 0.0;
        foreach ($out as $value) {
            $norm += $value * $value;
        }
        $norm = sqrt($norm);
        if ($norm <= 0.0) {
            return $out;
        }

        return array_map(fn (float $value) => $value / $norm, $out);
    }

    /**
     * Average L2-normalized pose embeddings so login works from left/right/top/bottom.
     *
     * @param  array<int, array<int, float|int|string>>  $embeddings
     * @return array<int, float>
     */
    public function averageEmbeddings(array $embeddings): array
    {
        $vectors = [];
        $dim = null;

        foreach ($embeddings as $embedding) {
            if (! is_array($embedding) || $embedding === []) {
                continue;
            }

            $values = array_map('floatval', array_values($embedding));
            if ($dim === null) {
                $dim = count($values);
            }
            if (count($values) !== $dim) {
                continue;
            }

            $norm = 0.0;
            foreach ($values as $value) {
                $norm += $value * $value;
            }
            $norm = sqrt($norm);
            if ($norm <= 0.0) {
                continue;
            }

            $vectors[] = array_map(fn (float $value) => $value / $norm, $values);
        }

        if ($vectors === [] || $dim === null) {
            return [];
        }

        $sum = array_fill(0, $dim, 0.0);
        foreach ($vectors as $vector) {
            for ($i = 0; $i < $dim; $i++) {
                $sum[$i] += $vector[$i];
            }
        }

        $count = count($vectors);
        $avg = array_map(fn (float $value) => $value / $count, $sum);

        $norm = 0.0;
        foreach ($avg as $value) {
            $norm += $value * $value;
        }
        $norm = sqrt($norm);
        if ($norm <= 0.0) {
            return $avg;
        }

        return array_map(fn (float $value) => $value / $norm, $avg);
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
     * @param  array<int, array<int, float|int|string>>|array<int, float|int|string>  $probeEmbeddings
     * @return array{user_id: int, score: float}|null
     */
    public function findDuplicateFaceOwner(array $probeEmbeddings, int $excludeUserId): ?array
    {
        if ($probeEmbeddings === []) {
            return null;
        }

        // Allow a single flat embedding vector.
        if (is_numeric($probeEmbeddings[0] ?? null)) {
            $probeEmbeddings = [$probeEmbeddings];
        }

        $threshold = (float) config(
            'face_recognition.duplicate_threshold',
            config('face_recognition.similarity_threshold', 0.80)
        );

        $profiles = UserFaceEmbedding::query()
            ->where('user_id', '!=', $excludeUserId)
            ->get(['id', 'user_id', 'embedding']);

        if ($profiles->isEmpty()) {
            return null;
        }

        $bestUserId = null;
        $bestScore = 0.0;

        foreach ($probeEmbeddings as $probeEmbedding) {
            if (! is_array($probeEmbedding) || $probeEmbedding === []) {
                continue;
            }

            foreach ($profiles as $profile) {
                $storedEmbedding = $profile->getEmbeddingVector();
                if ($storedEmbedding === []) {
                    Log::warning('face_register.empty_stored_embedding', [
                        'profile_id' => $profile->id,
                        'user_id' => $profile->user_id,
                    ]);
                    continue;
                }

                $score = $this->cosineSimilarity($probeEmbedding, $storedEmbedding);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestUserId = (int) $profile->user_id;
                }
            }
        }

        if ($bestUserId === null || $bestScore < $threshold) {
            Log::info('face_register.duplicate_check_passed', [
                'exclude_user_id' => $excludeUserId,
                'best_score' => round($bestScore, 4),
                'threshold' => $threshold,
                'profiles_checked' => $profiles->count(),
            ]);

            return null;
        }

        return [
            'user_id' => $bestUserId,
            'score' => $bestScore,
        ];
    }

    protected function client()
    {
        $request = Http::timeout(120)->acceptJson();
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
