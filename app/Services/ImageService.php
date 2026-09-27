<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ImageService
{
    public static function storeBase64Image($base64String, $directory = 'images')
    {
        if (empty($base64String)) {
            return null;
        }

        // Check if it's a data URL
        if (str_starts_with($base64String, 'data:image/')) {
            // Extract MIME type and base64 data
            preg_match('/data:image\/([a-zA-Z0-9+\/]+);base64,(.+)/', $base64String, $matches);

            if (count($matches) !== 3) {
                throw new \Exception('Invalid base64 image format');
            }

            $mimeType = $matches[1];
            $data = $matches[2];
        } else {
            // Assume it's raw base64
            $data = $base64String;
            $mimeType = 'jpeg';
        }

        // Map MIME type to file extension
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

        // Decode image data
        $imageData = base64_decode($data, true);

        if ($imageData === false) {
            throw new \Exception('Failed to decode base64 image');
        }

        // Validate file size (5MB max)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if (strlen($imageData) > $maxSize) {
            throw new \Exception('Image size exceeds maximum allowed size of 5MB');
        }

        $filename = $directory . '/' . Str::uuid() . '.' . $extension;

        // Store on Cloudinary
        $path = Storage::disk('cloudinary')->put($filename, $imageData);

        return $path;
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
