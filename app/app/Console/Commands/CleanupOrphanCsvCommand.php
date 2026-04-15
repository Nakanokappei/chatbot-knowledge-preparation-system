<?php

namespace App\Console\Commands;

use App\Console\Concerns\FormatsFileSize;
use App\Models\Dataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remove orphaned CSV objects from the csv-uploads/ prefix on the csv disk.
 *
 * A CSV is considered orphaned when no row in the `datasets` table references
 * it via `schema_json.stored_path` or `schema_json.raw_path`. This can happen
 * when:
 *   - A dataset was deleted before schema_json had `raw_path` populated,
 *     so DatasetWizardController::destroy() missed the .raw sibling.
 *   - The user abandoned the configure flow and the dataset row was later
 *     removed by another path (or hard-deleted by an admin).
 *   - Legacy uploads from when the prefix was `tenant1_` instead of
 *     `workspace1_` — no dataset row now claims those.
 *
 * Defaults to dry-run. Pass --apply to actually delete. --show-all prints
 * every orphan, otherwise the list is truncated for readability.
 *
 * Usage:
 *   php artisan kps:cleanup-orphan-csv                 # dry-run, preview
 *   php artisan kps:cleanup-orphan-csv --show-all      # dry-run, full list
 *   php artisan kps:cleanup-orphan-csv --apply         # delete
 */
class CleanupOrphanCsvCommand extends Command
{
    use FormatsFileSize;

    protected $signature = 'kps:cleanup-orphan-csv
                            {--apply : Perform deletion (without this flag, dry-run only)}
                            {--show-all : Print every orphan path instead of truncating}
                            {--prefix=csv-uploads/ : S3/disk prefix to scan}';

    protected $description = 'Remove CSV objects under csv-uploads/ that no dataset record references.';

    public function handle(): int
    {
        $disk = Storage::disk('csv');
        $prefix = rtrim($this->option('prefix'), '/') . '/';
        $apply = (bool) $this->option('apply');
        $showAll = (bool) $this->option('show-all');

        // Collect every key under the prefix. allFiles() is transparent over
        // both local and s3 drivers, so this command works the same way in
        // dev (local disk) and prod (S3).
        $this->info("Scanning disk='csv' prefix='{$prefix}' ...");
        $allKeys = collect($disk->allFiles($prefix))->map(fn ($k) => ltrim($k, '/'));
        $this->info("Found " . $allKeys->count() . " object(s) under {$prefix}");

        // Build the set of paths currently claimed by a dataset. We walk the
        // JSON fields defensively because schema_json may be stored either as
        // a real JSON column (psql) or a stringified blob depending on how
        // the record was written over the project's lifetime.
        $activePaths = [];
        foreach (Dataset::query()->get(['id', 'name', 'schema_json']) as $ds) {
            $schema = $ds->schema_json;
            if (is_string($schema)) {
                $schema = json_decode($schema, true) ?: [];
            } elseif (!is_array($schema)) {
                $schema = [];
            }
            foreach (['stored_path', 'raw_path'] as $key) {
                if (!empty($schema[$key])) {
                    $activePaths[] = ltrim((string) $schema[$key], '/');
                }
            }
        }
        $activeSet = array_flip($activePaths);
        $this->info("Datasets reference " . count($activeSet) . " active path(s)");

        // Orphan = exists on disk, not referenced by any dataset.
        $orphans = $allKeys->reject(fn ($k) => isset($activeSet[$k]))->values();

        if ($orphans->isEmpty()) {
            $this->info('✅ No orphan CSV objects found.');
            return self::SUCCESS;
        }

        // Fetch size for each orphan so operators can see what space they're
        // about to reclaim. Use size() per-object (s3 HeadObject or local
        // filesize) — cheap for the size-of-bucket we expect here (<100).
        $totalBytes = 0;
        $rows = [];
        foreach ($orphans as $key) {
            $size = 0;
            try {
                $size = (int) $disk->size($key);
            } catch (\Throwable $e) {
                // Key listed but headobject failed — still report, just no size
                $this->warn("  size() failed for {$key}: " . $e->getMessage());
            }
            $totalBytes += $size;
            $rows[] = [$key, $this->humanBytes($size)];
        }

        $this->line('');
        $this->info(($apply ? '🗑  Deleting' : 'Dry-run (no deletion)') . ' ' . $orphans->count() . ' orphan(s):');
        $display = $showAll ? $rows : array_slice($rows, 0, 15);
        foreach ($display as [$key, $humanSize]) {
            $this->line("  {$key}  ({$humanSize})");
        }
        if (!$showAll && count($rows) > 15) {
            $this->line('  ... ' . (count($rows) - 15) . ' more (use --show-all to list them all)');
        }
        $this->info('Total: ' . $orphans->count() . ' files, ' . $this->humanBytes($totalBytes));

        if (!$apply) {
            $this->warn('Re-run with --apply to actually delete these objects.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($orphans as $key) {
            try {
                if ($disk->delete($key)) {
                    $deleted++;
                } else {
                    $failed++;
                    Log::warning('kps:cleanup-orphan-csv delete returned false', ['key' => $key]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('kps:cleanup-orphan-csv delete threw', [
                    'key' => $key, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->info("Deleted {$deleted} object(s), {$failed} failure(s).");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // humanBytes() comes from the FormatsFileSize trait.
}
