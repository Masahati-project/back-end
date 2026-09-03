<?php

namespace App\Http\Controllers;

use App\Models\Space;
use Illuminate\Http\Request;

class SpaceController extends Controller
{
    public function index()
    {
        return response()->json(Space::all());
    }

    public function store(Request $request)
    {
        $space = Space::create([
            'name' => $request->name,
            'description' => $request->description,
            'location' => $request->location,
            'capacity' => $request->capacity,
            'price_per_hour' => $request->price_per_hour,
            'image' => $request->image,
            'is_available' => $request->is_available,
        ]);

        return response()->json($space, 201);
    }
}