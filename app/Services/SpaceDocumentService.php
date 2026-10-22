<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The proof-of-ownership files attached to a listing, shaped for the admin
 * review screen.
 *
 * Two places can hold a file: the per-space verification rows written by the
 * document upload endpoint, and the `space_document_url` column on the listing
 * itself, which predates those rows and is still written on create. Both are
 * represented here so the admin sees every file that was ever submitted.
 */
class SpaceDocumentService
{
    /**
     * A flat list — one entry per file, not one entry per verification row.
     *
     * The admin document list reads a flat `url`/`path`, so nesting files under
     * a parent object would leave the review button with nothing to open.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(Workspace $space): array
    {
        $documents = [];

        foreach ($space->spaceDocuments()->orderByDesc('id')->get() as $verification) {
            if (blank($verification->proof_document_url)) {
                continue;
            }

            $documents[] = self::format(
                $space,
                $verification->proof_document_url,
                [
                    'id' => $verification->id,
                    'status' => $verification->status,
                    'uploaded_at' => optional($verification->created_at)->toIso8601String(),
                ]
            );
        }

        // Older listings predate the upload endpoint and only carry the column.
        if (blank($space->space_document_url)) {
            return $documents;
        }

        $alreadyListed = collect($documents)->contains(
            fn (array $document) => $document['path'] === $space->space_document_url
        );

        if (! $alreadyListed) {
            $documents[] = self::format(
                $space,
                $space->space_document_url,
                [
                    'id' => null,
                    'status' => 'pending',
                    'uploaded_at' => optional($space->created_at)->toIso8601String(),
                ]
            );
        }

        return $documents;
    }

    /**
     * Keep only the requested review state. An unknown value is treated as "no
     * filter" so the admin screen cannot accidentally hide every document.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    public static function filterByStatus(array $documents, ?string $status): array
    {
        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return $documents;
        }

        return array_values(array_filter(
            $documents,
            fn (array $document) => ($document['status'] ?? null) === $status
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function format(Workspace $space, string $path, array $overrides): array
    {
        return array_merge([
            'id' => null,
            'kind' => 'proof',
            'label' => 'إثبات الملكية',
            'name' => self::fileName($path),
            'url' => self::url($path),
            'path' => $path,
            'uploaded_at' => null,
            'space_id' => $space->id,
            'space_title' => $space->title,
            'owner_id' => $space->owner_id,
            'owner_name' => $space->owner?->full_name,
            'status' => 'pending',
        ], $overrides);
    }

    /**
     * The public delivery address for a stored document.
     *
     * A PDF is uploaded as a Cloudinary `raw` asset, while an image lands under
     * `image/upload`; building the URL from the extension avoids the disk
     * adapter's Admin API round-trip (one remote request per document) and keeps
     * the link correct for both kinds of file.
     *
     * The configured disk URL is used when no cloud name is available — a local
     * or test disk — and the stored path is returned as a last resort rather
     * than a broken/500 response.
     */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $disk = (array) config('filesystems.disks.cloudinary', []);
        $cloud = self::cloudName($disk);

        if ($cloud !== null) {
            $prefix = trim((string) ($disk['prefix'] ?? ''), '/');
            $publicPath = $prefix !== '' && ! str_starts_with($path, $prefix.'/')
                ? $prefix.'/'.$path
                : $path;

            $resource = self::isImage($publicPath) ? 'image' : 'raw';

            return 'https://res.cloudinary.com/'.$cloud.'/'.$resource.'/upload/'.$publicPath;
        }

        $base = $disk['url'] ?? null;

        if (is_string($base) && trim($base) !== '') {
            return rtrim(trim($base), '/').'/'.$path;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $disk
     */
    private static function cloudName(array $disk): ?string
    {
        $cloud = $disk['cloud'] ?? null;

        if (is_string($cloud) && trim($cloud) !== '') {
            return trim($cloud);
        }

        $raw = $disk['url'] ?? config('cloudinary.cloud_url');

        if (is_string($raw) && str_contains($raw, '@')) {
            $candidate = substr($raw, (int) strrpos($raw, '@') + 1);
            $candidate = trim(explode('?', $candidate, 2)[0]);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function isImage(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],
            true
        );
    }

    public static function fileName(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return basename(str_replace('\\', '/', $path));
    }
}
