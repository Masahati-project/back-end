<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Traits\HandlesBase64Images;
use Illuminate\Http\Request;

class AdController extends Controller
{
    use HandlesBase64Images;

    public function index(Request $request)
    {
        $ads = Ad::where('user_id', $request->user()->id)->latest()->get();
        return response()->json(['ads' => $ads]);
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $data['user_id'] = $request->user()->id;
        $data['status'] = 'draft';
        $data['image'] = $this->uploadBase64Image($request->input('image'), 'ads');

        $ad = Ad::create($data);

        return response()->json([
            'message' => 'تم إنشاء الإعلان كمسودة.',
            'ad' => $ad
        ]);
    }

    public function update(Request $request, $id)
    {
        $ad = Ad::where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();
        
        $data = $request->all();
        if ($request->has('image')) {
            $data['image'] = $this->uploadBase64Image($request->input('image'), 'ads');
        }

        $ad->update($data);

        return response()->json([
            'message' => 'تم تحديث الإعلان.',
            'ad' => $ad
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $ad = Ad::where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();
        $ad->delete();

        return response()->json(['message' => 'تم حذف الإعلان بنجاح.']);
    }

    public function publish(Request $request, $id)
    {
        $ad = Ad::where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();

        $ad->update([
            'status' => 'published',
            'sent_at' => now()
        ]);

        return response()->json([
            'message' => 'تم نشر الإعلان بنجاح.',
            'ad' => $ad
        ]);
    }
}