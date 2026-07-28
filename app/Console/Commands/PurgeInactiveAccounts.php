<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserAccountPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeInactiveAccounts extends Command
{
    protected $signature = 'accounts:purge-inactive {--days=30 : Soft-deleted accounts older than this many days}';

    protected $description = 'Permanently delete soft-deleted (inactive) accounts after the grace period';

    public function handle(UserAccountPurgeService $purge): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $users = User::query()
            ->where('is_active', 0)
            ->where(function ($q) use ($cutoff) {
                $q->where('deactivated_at', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        // Legacy rows without deactivated_at
                        $q2->whereNull('deactivated_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->get();

        $deleted = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $purge->purge($user);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('accounts.purge_inactive_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info("Purged {$deleted} inactive account(s) older than {$days} days. Failed: {$failed}.");

        return self::SUCCESS;
    }
}
