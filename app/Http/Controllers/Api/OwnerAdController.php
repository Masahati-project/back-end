<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerAdController extends Controller
{
    use OwnerAuthorization;


    public function index()
    {
        $this->ensureOwnerRole();

        $ads = Ad::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($ad) => $this->formatAdResponse($ad));

        return response()->json(['ads' => $ads]);
    }

    public function store(Request $request)
    {
        $this->ensureOwnerRole();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'link' => 'nullable|string|url',
            'image' => 'nullable|string',
            'target' => 'required|string',
            'schedule' => 'nullable|json',
        ]);

        $imagePath = null;
        if (!empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'ads');
        }

        $ad = Ad::create([
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'link' => $validated['link'],
            'image' => $imagePath,
            'target' => $validated['target'],
            'status' => 'draft',
            'schedule' => $validated['schedule'],
        ]);

        return response()->json([
            'message' => 'تم إنشاء الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'link' => 'nullable|string|url',
            'image' => 'nullable|string',
            'target' => 'string',
            'status' => 'in:draft,published,archived',
            'schedule' => 'nullable|json',
        ]);

        $imagePath = null;
        if (isset($validated['image']) && !empty($validated['image'])) {
            $imagePath = ImageService::storeBase64Image($validated['image'], 'ads');
        }

        $updateData = [
            'title' => $validated['title'] ?? $ad->title,
            'description' => $validated['description'] ?? $ad->description,
            'link' => $validated['link'] ?? $ad->link,
            'target' => $validated['target'] ?? $ad->target,
            'status' => $validated['status'] ?? $ad->status,
            'schedule' => $validated['schedule'] ?? $ad->schedule,
        ];

        if ($imagePath) {
            $updateData['image'] = $imagePath;
        }

        $ad->update($updateData);

        return response()->json([
            'message' => 'تم تحديث الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ]);
    }

    public function destroy(string $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $ad->delete();

        return response()->json(['message' => 'تم حذف الإعلان.']);
    }

    public function publish(Request $request, string $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $ad->update([
            'status' => 'published',
            'sent_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم نشر الإعلان.',
            'ad' => $this->formatAdResponse($ad),
        ]);
    }

    private function formatAdResponse(Ad $ad)
    {
        return [
            'id' => $ad->id,
            'ad_id' => $ad->id,
            'title' => $ad->title,
            'description' => $ad->description,
            'notes' => $ad->description,
            'link' => $ad->link,
            'url' => $ad->link,
            'image' => $ad->image,
            'target' => $ad->target,
            'space_id' => $ad->space_id,
            'space' => $ad->space_id,
            'status' => $ad->status,
            'created_at' => $ad->created_at?->toIso8601String(),
            'created' => $ad->created_at?->toIso8601String(),
            'sent_at' => $ad->sent_at?->toIso8601String(),
            'impressions' => $ad->impressions,
            'impressions_count' => $ad->impressions,
            'schedule' => $ad->schedule,
        ];
    }
}
