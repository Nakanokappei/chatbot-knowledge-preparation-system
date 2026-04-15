<?php

namespace App\Console\Commands;

use App\Console\Concerns\FormatsFileSize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Remove stale temporary CSV files produced by DatasetWizardController.
 *
 * The wizard creates `/tmp/csv_*`, `/tmp/csv_utf8_*`, and `/tmp/csv_re_*`
 * files during upload, re-encoding, and preview flows. The happy path
 * `unlink()`s them, but exceptions or interrupted requests can leak files
 * that permanently occupy the container's ephemeral storage.
 *
 * This command scans the OS temp directory and removes leaked files older
 * than a grace period (default: 1 hour). Safe to run on a schedule or ad-hoc.
 *
 * Usage:
 *   php artisan kps:cleanup-temp-csv           # dry-run, 1h threshold
 *   php artisan kps:cleanup-temp-csv --apply   # actually delete
 *   php artisan kps:cleanup-temp-csv --apply --older-than=3600  # seconds
 */
class CleanupTempCsvCommand extends Command
{
    use FormatsFileSize;

    protected $signature = 'kps:cleanup-temp-csv
                            {--apply : Perform deletion (without this flag, dry-run only)}
                            {--older-than=3600 : Minimum age in seconds before a file is eligible for deletion}';

    protected $description = 'Remove stale /tmp/csv_* scratch files left behind by the dataset wizard.';

    public function handle(): int
    {
        // Prefixes used by DatasetWizardController's tempnam() calls
        $prefixes = ['csv_', 'csv_utf8_', 'csv_re_'];
        $tempDir = sys_get_temp_dir();
        $olderThan = (int) $this->option('older-than');
        $apply = (bool) $this->option('apply');
        $now = time();

        $candidates = [];
        // Scan the temp directory manually so we can be deterministic about
        // which prefixes are considered "ours". glob() patterns would also
        // work but are less readable when listing multiple prefixes.
        foreach (scandir($tempDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $matches = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($entry, $p)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $path = $tempDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            $age = $now - (int) filemtime($path);
            if ($age < $olderThan) {
                continue;
            }
            $candidates[] = ['path' => $path, 'age' => $age, 'size' => filesize($path)];
        }

        if (empty($candidates)) {
            $this->info("No stale temp CSV files found in {$tempDir} older than {$olderThan}s.");
            return self::SUCCESS;
        }

        // Show what will (or would) be done so operators can sanity-check the
        // list before committing to deletion.
        $this->info(($apply ? 'Deleting' : 'Dry-run (no deletion)') . " {$tempDir}:");
        $totalBytes = 0;
        foreach ($candidates as $c) {
            $this->line(sprintf('  %s  (%s, age %s)', $c['path'], $this->humanBytes($c['size']), $this->humanDuration($c['age'])));
            $totalBytes += $c['size'];
        }
        $this->info(sprintf('Total: %d files, %s', count($candidates), $this->humanBytes($totalBytes)));

        if (!$apply) {
            $this->warn('Re-run with --apply to actually delete these files.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($candidates as $c) {
            if (@unlink($c['path'])) {
                $deleted++;
            } else {
                $failed++;
                Log::warning('kps:cleanup-temp-csv failed to delete', ['path' => $c['path']]);
            }
        }
        $this->info("Deleted {$deleted} files, {$failed} failures.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // humanBytes() and humanDuration() come from the FormatsFileSize trait.
}
