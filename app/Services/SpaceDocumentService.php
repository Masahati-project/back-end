<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

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
     * Cloudinary stores a PDF under `raw/upload`, so the disk's own URL builder
     * is used here instead of the image-only delivery path. A missing cloud
     * configuration returns the stored path rather than a broken/500 response.
     */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        try {
            return Storage::disk('cloudinary')->url($path);
        } catch (\Throwable) {
            return $path;
        }
    }

    public static function fileName(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return basename(str_replace('\\', '/', $path));
    }
}
