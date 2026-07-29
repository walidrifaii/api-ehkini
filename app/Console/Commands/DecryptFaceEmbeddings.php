<?php

namespace App\Console\Commands;

use App\Models\UserFaceEmbedding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class DecryptFaceEmbeddings extends Command
{
    protected $signature = 'faces:decrypt-embeddings {--dry-run : Show what would change without writing}';

    protected $description = 'Convert legacy Crypt-encrypted face embeddings to plain JSON (no hashing)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $converted = 0;
        $alreadyPlain = 0;
        $failed = 0;

        UserFaceEmbedding::query()->orderBy('id')->chunkById(100, function ($rows) use ($dryRun, &$converted, &$alreadyPlain, &$failed) {
            foreach ($rows as $row) {
                $raw = $row->getRawOriginal('embedding');
                if (! is_string($raw) || $raw === '') {
                    $failed++;
                    continue;
                }

                $asJson = json_decode($raw, true);
                if (is_array($asJson)) {
                    $alreadyPlain++;
                    continue;
                }

                try {
                    $decoded = json_decode(Crypt::decryptString($raw), true);
                    if (! is_array($decoded)) {
                        $failed++;
                        continue;
                    }
                    if (isset($decoded['embedding']) && is_array($decoded['embedding'])) {
                        $decoded = $decoded['embedding'];
                    }
                    $vector = array_map('floatval', array_values($decoded));
                    if (count($vector) < 64) {
                        $failed++;
                        continue;
                    }

                    if (! $dryRun) {
                        $row->setEmbeddingVector($vector);
                        $row->save();
                    }
                    $converted++;
                } catch (\Throwable $e) {
                    $this->warn("user_id={$row->user_id}: {$e->getMessage()}");
                    $failed++;
                }
            }
        });

        $this->info("plain_already={$alreadyPlain} converted={$converted} failed={$failed}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
