<?php

namespace App\Console\Commands;

use App\Services\FaceRecognitionService;
use Illuminate\Console\Command;

class RebuildFaceGallery extends Command
{
    protected $signature = 'faces:rebuild-gallery';

    protected $description = 'Rebuild the Face AI FAISS gallery from DB embeddings';

    public function handle(FaceRecognitionService $faceService): int
    {
        $this->info('Rebuilding face gallery...');
        $size = $faceService->rebuildGalleryFromDatabase();
        $this->info("Gallery size: {$size}");

        return self::SUCCESS;
    }
}
