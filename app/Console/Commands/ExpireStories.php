<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Story;
use Illuminate\Support\Facades\Storage;

class ExpireStories extends Command
{
    protected $signature = 'stories:expire';
    protected $description = 'Expire stories older than 24 hours';

    public function handle()
    {
        $now = now();

        $expired = Story::query()
            ->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($expired as $story) {
            // delete media file (optional)
            if (!empty($story->media)) {
                Storage::disk('public')->delete($story->media);
            }

            $story->update(['deleted_at' => $now]);
        }

        $this->info("Expired stories: " . $expired->count());

        return 0;
    }
}