<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpecialRequestRequest;
use App\Models\SpecialRequest;
use Illuminate\Http\Request;

class SpecialRequestController extends Controller
{
    public function index(Request $request)
    {
        $requests = SpecialRequest::with(['user', 'offers'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'requests' => $requests,
            'message' => 'تم جلب الطلبات الخاصة بنجاح',
        ], 200);
    }

    public function store(StoreSpecialRequestRequest $request)
    {
        $specialRequest = SpecialRequest::create(array_merge(
            $request->validated(),
            ['user_id' => $request->user()->id, 'status' => 'open']
        ));

        return response()->json([
            'request' => $specialRequest,
            'message' => 'تم إنشاء الطلب الخاص بنجاح',
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $specialRequest = SpecialRequest::with(['user', 'offers'])
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'request' => $specialRequest,
            'message' => 'تم جلب الطلب الخاص بنجاح',
        ], 200);
    }

    public function acceptOffer(Request $request, $id)
    {
        $specialRequest = SpecialRequest::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $specialRequest->update(['status' => 'accepted']);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'message' => 'تم قبول العرض بنجاح',
        ], 200);
    }

    public function rejectOffer(Request $request, $id)
    {
        $specialRequest = SpecialRequest::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $specialRequest->update(['status' => 'rejected']);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'message' => 'تم رفض العرض بنجاح',
        ], 200);
    }

    public function closeRequest(Request $request, $id)
    {
        $specialRequest = SpecialRequest::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $specialRequest->update(['status' => 'closed']);

        return response()->json([
            'request' => $specialRequest->fresh(),
            'message' => 'تم إغلاق الطلب الخاص بنجاح',
        ], 200);
    }
}
