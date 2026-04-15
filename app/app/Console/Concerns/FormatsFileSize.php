<?php

namespace App\Console\Concerns;

/**
 * Shared output formatters for cleanup-style artisan commands.
 *
 * Both kps:cleanup-temp-csv and kps:cleanup-orphan-csv list candidate
 * files with size/age annotations before deciding whether to delete.
 * Keeping these formatters in one place avoids the previous drift where
 * one command had GB support and the other didn't.
 */
trait FormatsFileSize
{
    /**
     * Format a byte count as B / KB / MB / GB. Single decimal place is
     * enough precision for an operator scanning a deletion plan; we don't
     * need accountant-grade exactness here.
     */
    protected function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.1f GB', $bytes / 1024 / 1024 / 1024);
        }
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / 1024 / 1024);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return "{$bytes} B";
    }

    /**
     * Format a non-negative duration in seconds as a short human string
     * such as "2h 15m" or "47m" or "12s". Used to show file age relative
     * to the cleanup threshold.
     */
    protected function humanDuration(int $seconds): string
    {
        if ($seconds >= 3600) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return "{$h}h {$m}m";
        }
        if ($seconds >= 60) {
            return intdiv($seconds, 60) . 'm';
        }
        return "{$seconds}s";
    }
}
