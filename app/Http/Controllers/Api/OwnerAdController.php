<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\ImageService;
use App\Traits\OwnerAuthorization;
use App\Traits\ResolvesAdExpiry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class OwnerAdController extends Controller
{
    use OwnerAuthorization;
    use ResolvesAdExpiry;

    private const SCAN_LIMIT = 200;
    private const OPEN_LIMIT = 50;
    private const PUBLISHED_LIMIT = 200;

    public function index()
    {
        $this->ensureOwnerRole();

        $ads = Ad::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($ad) => $this->formatAdResponse($ad));

        return response()->json(['ads' => $ads]);
    }

    public function open()
    {
        $this->ensureOwnerRole();

        $now = Carbon::now();

        $formatted = $this->runningAds(Auth::id(), $now)
            ->take(self::OPEN_LIMIT)
            ->values()
            ->map(function (Ad $ad) {
                return $this->formatAdResponse($ad) + [
                    'expires_at' => $this->resolveAdExpiry($ad)?->toIso8601String(),
                ];
            });

        return response()->json([
            'ads' => $formatted,
            'open_ads' => $formatted,
            'count' => $formatted->count(),
            'message' => 'تم جلب الإعلانات الجارية بنجاح.',
        ]);
    }

    public function published()
    {
        $this->ensureOwnerRole();

        $ads = Ad::where('user_id', Auth::id())
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PUBLISHED_LIMIT)
            ->get()
            ->map(fn ($ad) => $this->formatAdResponse($ad));

        return response()->json([
            'ads' => $ads,
            'published_ads' => $ads,
            'count' => $ads->count(),
            'message' => 'تم جلب الإعلانات المنشورة بنجاح.',
        ]);
    }

    public function stats()
    {
        $this->ensureOwnerRole();

        $ownerId = Auth::id();

        $byStatus = [
            'draft' => Ad::where('user_id', $ownerId)->where('status', 'draft')->count(),
            'published' => Ad::where('user_id', $ownerId)->where('status', 'published')->count(),
            'archived' => Ad::where('user_id', $ownerId)->where('status', 'archived')->count(),
        ];

        $total = Ad::where('user_id', $ownerId)->count();

        $impressions = (int) Ad::where('user_id', $ownerId)->sum('impressions');

        $running = $this->runningAds($ownerId, Carbon::now())->count();

        $payload = [
            'total' => $total,
            'total_ads' => $total,
            'draft' => $byStatus['draft'],
            'published' => $byStatus['published'],
            'archived' => $byStatus['archived'],
            'by_status' => $byStatus,
            'running' => $running,
            'impressions' => $impressions,
            'total_impressions' => $impressions,
        ];

        return response()->json([
            'message' => 'تم جلب إحصائيات الإعلانات بنجاح.',
            'stats' => $payload,
            'data' => $payload,
        ]);
    }

    private function runningAds($ownerId, Carbon $now)
    {
        return Ad::where('user_id', $ownerId)
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->filter(fn (Ad $ad) => $this->adIsRunning($ad, $now));
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

    public function update(Request $request, int $id)
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

    public function destroy(int $id)
    {
        $this->ensureOwnerRole();
        $ad = $this->ensureOwnsAd($id);

        $ad->delete();

        return response()->json(['message' => 'تم حذف الإعلان.']);
    }

    public function publish(Request $request, int $id)
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