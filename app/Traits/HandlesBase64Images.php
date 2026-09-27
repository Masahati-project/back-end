<?php

namespace App\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

trait HandlesBase64Images
{
    /**
     * Decode a base64 image string and store it on the public disk.
     *
     * @param string|null $base64String
     * @param string $folder
     * @return string|null
     */
    public function uploadBase64Image(?string $base64String, string $folder = 'spaces'): ?string
    {
        // إذا كانت القيمة فارغة أو ليست صيغة Data URL بـ Base64، ارجع القيمة كما هي
        if (!$base64String || !preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            return $base64String;
        }

        // استخراج الجزء المشفر بـ Base64 فقط
        $data = substr($base64String, strpos($base64String, ',') + 1);
        $data = base64_decode($data);

        if ($data === false) {
            return null;
        }

        // تحديد امتداد الصورة (jpg, png, etc.)
        $extension = strtolower($type[1]);
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // توليد اسم فريد للملف وتحديد مسار الحفظ
        $fileName = $folder . '/' . Str::random(25) . '.' . $extension;

        // تخزين الصورة على القرص العام public storage
        Storage::disk('public')->put($fileName, $data);

        // إرجاع المسار النسبي المطلوبة من قبل الـ Frontend
        return '/storage/' . $fileName;
    }
}