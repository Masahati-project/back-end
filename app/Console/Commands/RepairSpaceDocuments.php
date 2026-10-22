<?php

namespace App\Console\Commands;

use App\Models\SpaceOwnerVerification;
use App\Models\Workspace;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * Older proof files were stored with the name Laravel *requested* on the
 * Cloudinary disk (for example `documents/hash.pdf`). The disk uploads raw
 * files as streams, so Cloudinary actually named them `documents/hash.tmp`.
 * Those rows therefore point at an asset that does not exist.
 *
 * This command finds the real asset by its path prefix and replaces the stored
 * value with Cloudinary's delivery URL. It is idempotent: correct rows and
 * absolute URLs are left alone.
 */
class RepairSpaceDocuments extends Command
{
    protected $signature = 'documents:repair {--dry-run : Show the changes without saving them}';

    protected $description = 'Repair stored proof document paths that do not resolve on Cloudinary';

    public function handle(): int
    {
        $disk = Storage::disk('cloudinary');

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $this->warn('The cloudinary disk is local; there are no remote assets to repair.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;
        $unresolved = 0;

        Workspace::query()
            ->whereNotNull('space_document_url')
            ->where('space_document_url', '<>', '')
            ->orderBy('id')
            ->each(function (Workspace $space) use ($dryRun, &$repaired, &$unresolved) {
                $path = (string) $space->space_document_url;
                $resolved = $this->resolve($path);

                if ($resolved === null) {
                    $this->warn("Space {$space->id}: could not resolve {$path}");
                    $unresolved++;

                    return;
                }

                if ($resolved === $path) {
                    return;
                }

                $this->line("Space {$space->id}: {$path} -> {$resolved}");

                if (! $dryRun) {
                    $space->forceFill(['space_document_url' => $resolved])->save();
                }

                $repaired++;
            });

        SpaceOwnerVerification::query()
            ->whereNotNull('proof_document_url')
            ->where('proof_document_url', '<>', '')
            ->orderBy('id')
            ->each(function (SpaceOwnerVerification $document) use ($dryRun, &$repaired, &$unresolved) {
                $path = (string) $document->proof_document_url;
                $resolved = $this->resolve($path);

                if ($resolved === null) {
                    $this->warn("Document {$document->id}: could not resolve {$path}");
                    $unresolved++;

                    return;
                }

                if ($resolved === $path) {
                    return;
                }

                $this->line("Document {$document->id}: {$path} -> {$resolved}");

                if (! $dryRun) {
                    $document->forceFill(['proof_document_url' => $resolved])->save();
                }

                $repaired++;
            });

        // Listings created before per-space document rows only carry the column.
        // Give that file a row too, so the admin decision updates its status and
        // the document queue has a stable id for it.
        $createdRows = 0;

        Workspace::query()
            ->whereNotNull('space_document_url')
            ->where('space_document_url', '<>', '')
            ->orderBy('id')
            ->each(function (Workspace $space) use ($dryRun, &$createdRows) {
                $path = (string) $space->space_document_url;

                $exists = SpaceOwnerVerification::where('workspace_id', $space->id)
                    ->where('proof_document_url', $path)
                    ->exists();

                if ($exists) {
                    return;
                }

                $status = match ($space->status) {
                    'active' => 'approved',
                    'rejected' => 'rejected',
                    default => 'pending',
                };

                $this->line("Space {$space->id}: ".($dryRun ? 'would create' : 'creating')." a verification row for {$path}");

                if (! $dryRun) {
                    SpaceOwnerVerification::create([
                        'user_id' => $space->owner_id,
                        'workspace_id' => $space->id,
                        'proof_document_url' => $path,
                        'status' => $status,
                        'verified_at' => $status === 'approved' ? now() : null,
                    ]);
                }

                $createdRows++;
            });

        $action = $dryRun ? 'would repair' : 'repaired';

        $this->info("{$action} {$repaired} document path(s); {$unresolved} unresolved; {$createdRows} verification row(s) ".($dryRun ? 'would be created' : 'created').'.');

        return self::SUCCESS;
    }

    /**
     * Return the stored value when it is correct, or Cloudinary's real delivery
     * URL when the asset was saved under a different name.
     */
    private function resolve(string $path): ?string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        try {
            // Throws NotFound when the asset does not exist under this path.
            Storage::disk('cloudinary')->url($path);

            return $path;
        } catch (Throwable) {
            // Fall through to the Admin API lookup.
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $directory = dirname($normalized);
        $base = pathinfo($normalized, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        $prefix = ($directory !== '.' ? $directory.'/' : '').$base;

        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
        $resourceTypes = $isImage ? ['image', 'raw'] : ['raw', 'image'];

        foreach ($resourceTypes as $resourceType) {
            try {
                $assets = Cloudinary::adminApi()->assets([
                    'prefix' => $prefix,
                    'max_results' => 20,
                    'resource_type' => $resourceType,
                    'type' => 'upload',
                ]);

                $candidates = [];

                foreach ($assets->offsetGet('resources') ?? [] as $asset) {
                    $publicId = $asset['public_id'] ?? null;

                    if (! is_string($publicId) || $publicId === '') {
                        continue;
                    }

                    // Match the same basename only, so `documents/abc` does not
                    // accidentally resolve to `documents/abc-old`.
                    if ($publicId !== $prefix && ! str_starts_with($publicId, $prefix.'.')) {
                        continue;
                    }

                    if (! empty($asset['secure_url'])) {
                        $candidates[] = $asset;
                    }
                }

                if ($candidates === []) {
                    continue;
                }

                // Prefer the extension that was requested, then a `.tmp` asset
                // (the old stream-upload name), then anything else with the same
                // basename.
                usort($candidates, function (array $a, array $b) use ($prefix, $extension) {
                    $score = static function (array $asset) use ($prefix, $extension): int {
                        $publicId = (string) $asset['public_id'];

                        return match (true) {
                            $extension !== '' && $publicId === $prefix.'.'.$extension => 0,
                            $publicId === $prefix.'.tmp' => 1,
                            default => 2,
                        };
                    };

                    return $score($a) <=> $score($b);
                });

                return (string) $candidates[0]['secure_url'];
            } catch (Throwable $e) {
                $this->warn("Cloudinary lookup failed for {$path} ({$resourceType}): ".$e->getMessage());
            }
        }

        return null;
    }
}
