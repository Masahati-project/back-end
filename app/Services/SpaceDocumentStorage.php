<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Stores a listing's proof file and returns the value persisted in the
 * database: a Cloudinary public ID (production) or a disk-relative path
 * (tests/local disks).
 *
 * Laravel's Cloudinary disk adapter uploads the file as a stream. For `raw`
 * resources Cloudinary then names the asset after the PHP temp file, appending
 * `.tmp`, while `store()` reports the requested `.pdf` name — so the stored
 * path does not resolve. Uploading through the SDK with a local file path and
 * an explicit filename keeps the extension Cloudinary delivers.
 */
class SpaceDocumentStorage
{
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * @param  string  $directory  e.g. "documents" or "space_documents/12"
     */
    public static function store(UploadedFile $file, string $directory): string
    {
        $directory = trim($directory, '/');
        $extension = self::extension($file);
        $id = 'proof_'.Str::uuid();
        $name = $id.'.'.$extension;
        $resourceType = in_array($extension, self::IMAGE_EXTENSIONS, true) ? 'image' : 'raw';

        $disk = Storage::disk('cloudinary');

        // A faked/local disk (the test suite, local development) cannot talk to
        // the Cloudinary upload API; writing through it keeps those flows real
        // files on disk.
        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $path = $file->storeAs($directory, $name, 'cloudinary');

            if (! $path) {
                throw new RuntimeException('تعذّر حفظ المستند على الخادم.');
            }

            return $path;
        }

        // The filesystem adapter normally applies this prefix; the SDK call
        // below bypasses the adapter, so apply it explicitly.
        $prefix = trim((string) config('filesystems.disks.cloudinary.prefix', ''), '/');
        $remoteDirectory = $prefix !== '' ? $prefix.'/'.$directory : $directory;

        try {
            $result = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                'public_id' => $remoteDirectory.'/'.$id,
                'filename' => $name,
                'resource_type' => $resourceType,
                'type' => 'upload',
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('تعذّر حفظ المستند على الخادم.', 0, $e);
        }

        $publicId = $result['public_id'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            throw new RuntimeException('تعذّر حفظ المستند على الخادم.');
        }

        // Image assets come back without the format in their public ID while
        // the delivery URL needs it; append the format Cloudinary reported so
        // the URL builder can choose image/upload instead of raw/upload.
        if (! str_contains(basename($publicId), '.')) {
            $publicId .= '.'.($result['format'] ?? $extension);
        }

        return $publicId;
    }

    /**
     * The extension comes from the *detected* mime type where possible, so a
     * client-supplied name cannot choose the stored type.
     */
    private static function extension(UploadedFile $file): string
    {
        $extension = self::MIME_EXTENSIONS[$file->getMimeType()] ?? strtolower((string) $file->extension());

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return in_array($extension, ['pdf', 'png', 'jpg', 'webp'], true) ? $extension : 'pdf';
    }
}
