<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    /**
     * What used to land in the image columns instead of a path. `Storage::disk()
     * ->put()` answers a boolean and the old code returned it as-is, so rows were
     * seeded with a truthy marker rather than a file; the frontend must never see
     * one of these as an image URL.
     */
    private const BOOLEAN_PLACEHOLDERS = ['1', '0', 'true', 'false'];

    public static function storeBase64Image($base64String, $directory = 'images')
    {
        if (empty($base64String)) {
            return null;
        }

        if (str_starts_with($base64String, 'data:image/')) {
            preg_match('/data:image\/([a-zA-Z0-9+\/]+);base64,(.+)/', $base64String, $matches);

            if (count($matches) !== 3) {
                throw new \Exception('Invalid base64 image format');
            }

            $mimeType = $matches[1];
            $data = $matches[2];
        } else {
            $data = $base64String;
            $mimeType = 'jpeg';
        }

        $extensions = [
            'jpeg' => 'jpg',
            'jpg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
        ];

        if (!isset($extensions[$mimeType])) {
            throw new \Exception('Invalid image type. Allowed types: jpeg, jpg, png, gif, webp');
        }

        $extension = $extensions[$mimeType];

        $imageData = base64_decode($data, true);

        if ($imageData === false) {
            throw new \Exception('Failed to decode base64 image');
        }

        $maxSize = 5 * 1024 * 1024;
        if (strlen($imageData) > $maxSize) {
            throw new \Exception('Image size exceeds maximum allowed size of 5MB');
        }

        $filename = $directory.'/'.Str::uuid().'.'.$extension;

        $stored = Storage::disk('cloudinary')->put($filename, $imageData);

        // `put()` answers true/false, never a path — the flags the callers now
        // rely on. Only a successful upload may be reported as persisted.
        if (!$stored) {
            return null;
        }

        return $filename;
    }

    /**
     * The public address for a stored image — the full delivery URL when the
     * cloud name is configured, otherwise the stored path as-is so existing
     * clients keep working in an unconfigured environment. Null when the value
     * is not an address at all (legacy boolean upload markers, empty strings).
     *
     * Deliberately string-built rather than `Storage::url()`: the cloudinary
     * Flysystem adapter answers URLs by calling the Admin API per asset, which
     * is one remote request per image inside every listing list. The delivery
     * domain is stable (`https://res.cloudinary.com/{cloud}/image/upload/{path}`),
     * so the path stored at upload time is all that is needed.
     */
    public static function url($path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        // Already a URL (a location stored verbatim) — hand it through.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // The boolean an old write left behind must never resurface as a URL.
        if (in_array(strtolower($path), self::BOOLEAN_PLACEHOLDERS, true)) {
            return null;
        }

        $cloud = self::cloudName();

        if ($cloud === null) {
            // No cloud name is configured (a local or test environment) — the
            // best we can still offer is the stored path, exactly what the API
            // returned historically so existing clients keep working.
            return $path;
        }

        return 'https://res.cloudinary.com/'.$cloud.'/image/upload/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The cloud name, from the disk config or — when only CLOUDINARY_URL was
     * provided — pulled out of the credential string itself
     * ("cloudinary://<key>:<secret>@cloud_name").
     */
    private static function cloudName(): ?string
    {
        $disk = (array) config('filesystems.disks.cloudinary', []);

        $cloud = $disk['cloud'] ?? null;

        if (is_string($cloud) && trim($cloud) !== '') {
            return trim($cloud);
        }

        $raw = $disk['url'] ?? config('cloudinary.cloud_url');

        if (is_string($raw) && str_contains($raw, '@')) {
            $candidate = substr($raw, (int) strrpos($raw, '@') + 1);
            $candidate = (string) (strtok($candidate, '?') ?: '');
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    public static function validateBase64Image($base64String)
    {
        if (empty($base64String)) {
            return true;
        }

        if (str_starts_with($base64String, 'data:image/')) {
            preg_match('/data:image\/([a-zA-Z0-9+\/]+);base64,(.+)/', $base64String, $matches);

            return count($matches) === 3;
        }

        return base64_decode($base64String, true) !== false;
    }
}
