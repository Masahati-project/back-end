<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        $offers = Offer::where('user_id', $request->user()->id)->latest()->get();
        return response()->json(['offers' => $offers]);
    }
}