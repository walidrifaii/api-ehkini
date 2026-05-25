<?php

namespace App\Console\Commands;

use App\Support\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMediaFullUrls extends Command
{
    protected $signature = 'media:backfill-full-urls
                            {--dry-run : Show counts only, do not update}';

    protected $description = 'Convert relative image paths in DB to full https URLs (legacy amcserver base)';

    public function handle(): int
    {
        $base = rtrim((string) config('media.legacy_base_url'), '/');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Legacy base: '.$base);
        if ($dryRun) {
            $this->warn('Dry run — no rows will be updated.');
        }

        $tables = [
            ['users', 'profile_image'],
            ['posts', 'image'],
            ['stories', 'media'],
            ['gifts', 'image'],
        ];

        $total = 0;

        foreach ($tables as [$table, $column]) {
            if (! $this->tableHasColumn($table, $column)) {
                $this->warn("Skip {$table}.{$column} (column missing)");

                continue;
            }

            $count = $this->countRowsToUpdate($table, $column);
            $this->line("{$table}.{$column}: {$count} row(s) to update");
            $total += $count;

            if ($dryRun || $count === 0) {
                continue;
            }

            $updated = $this->updateColumn($table, $column, $base);
            $this->info("  → updated {$updated}");
        }

        $this->info($dryRun ? "Would update {$total} row(s) total." : "Done. Updated {$total} row(s) total.");

        return self::SUCCESS;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasTable($table)
            && DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    private function countRowsToUpdate(string $table, string $column): int
    {
        return (int) DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'not like', 'http://%')
            ->where($column, 'not like', 'https://%')
            ->count();
    }

    private function updateColumn(string $table, string $column, string $base): int
    {
        $quotedBase = DB::getPdo()->quote($base.'/');
        $quotedColumn = '`'.str_replace('`', '``', $column).'`';

        return DB::update("
            UPDATE `{$table}`
            SET {$quotedColumn} = CONCAT(
                {$quotedBase},
                TRIM(LEADING '/' FROM {$quotedColumn})
            )
            WHERE {$quotedColumn} IS NOT NULL
              AND {$quotedColumn} != ''
              AND {$quotedColumn} NOT LIKE 'http://%'
              AND {$quotedColumn} NOT LIKE 'https://%'
        ");
    }
}
