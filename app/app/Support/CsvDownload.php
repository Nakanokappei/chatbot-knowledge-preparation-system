<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Single-source CSV-as-download helper.
 *
 * Every export endpoint that ships a CSV (KU exports, dataset rows with
 * cluster names, bulk admin exports) was repeating the same five-line
 * dance: open php://temp, write a UTF-8 BOM so Excel autodetects the
 * encoding, fputcsv() the rows, slurp the buffer back, then build a
 * Response with the right Content-Type and Content-Disposition headers.
 *
 * Centralising it here keeps the BOM (which Excel needs) and the
 * Content-Disposition (which determines whether the browser opens or
 * downloads) consistent across every endpoint, and lets us swap out
 * the implementation later (e.g. streaming for very large exports)
 * without touching every controller.
 */
class CsvDownload
{
    /**
     * Build a download Response for a CSV file.
     *
     * @param array<int, mixed>          $headers  Column header row (single array of strings).
     * @param iterable<int, array<int, mixed>> $rows  Each row is an array of cell values, in the same order as $headers.
     * @param string                     $filename Filename without `.csv` extension. The extension is appended.
     */
    public static function make(array $headers, iterable $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM so Microsoft Excel autodetects encoding correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }
}
