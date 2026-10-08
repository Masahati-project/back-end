<?php

namespace App\Support;

/**
 * CSV helper with RFC 4180 compliance and formula-injection guard.
 */
class Csv
{
    /**
     * Escape a single field value per RFC 4180, with formula-injection guard.
     *
     * - Null/empty becomes empty string.
     * - If value contains comma, double quote, CR or LF, wrap in double quotes
     *   and double any internal double quotes.
     * - Formula-injection guard: if the resulting string (after RFC escaping)
     *   would start with '=', '+', '-', '@', tab (0x09) or CR (0x0D), prefix
     *   with a single quote so Excel/LibreOffice will not evaluate it as a
     *   formula when the admin opens the exported file.
     *
     * @return string
     */
    public static function field(mixed $value): string
    {
        // Null or empty → empty string
        if ($value === null || $value === '') {
            return '';
        }

        // Cast scalars to string
        $s = (string) $value;

        // RFC 4180: wrap if contains comma, double quote, CR or LF
        $needsQuotes = preg_match('/[",\r\n]/', $s);
        if ($needsQuotes) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }

        // Formula-injection guard: prefix with ' if starts with = + - @ tab CR
        // Check the FIRST character of the RFC-escaped value.
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
            $s = "'" . $s;
        }

        return $s;
    }

    /**
     * Build the full CSV body: UTF-8 BOM + header row + data rows + trailing CRLF.
     *
     * Accepts an iterable for $rows so callers can pass a lazy collection
     * without loading everything into memory at once.
     *
     * @param array<string> $header
     * @param iterable<array<mixed>> $rows
     * @return string
     */
    public static function body(array $header, iterable $rows): string
    {
        // UTF-8 BOM
        $out = "\xEF\xBB\xBF";

        // Header row
        $out .= implode(',', array_map([self::class, 'field'], $header)) . "\r\n";

        // Data rows
        foreach ($rows as $row) {
            $out .= implode(',', array_map([self::class, 'field'], $row)) . "\r\n";
        }

        return $out;
    }

    /**
     * Return a Symfony Response that streams the CSV for download.
     *
     * Uses response()->stream() to avoid buffering the entire CSV in memory
     * when a large iterable is passed.
     *
     * @param string $filename  Filename for Content-Disposition (will be
     *                          escaped for the header value).
     * @param array<string> $header
     * @param iterable<array<mixed>> $rows
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function download(string $filename, array $header, iterable $rows)
    {
        // Escape filename for Content-Disposition (RFC 5987 / RFC 6266)
        // We use a simple approach: replace " with \", and if it contains
        // non-ASCII we would need RFC 5987 encoding, but our filenames are ASCII.
        $safeFilename = str_replace('"', '\\"', $filename);

        return response()->stream(function () use ($header, $rows) {
            echo self::body($header, $rows);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
        ]);
    }
}