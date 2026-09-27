<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\OwnerDocument;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function show(Request $request)
    {
        $doc = OwnerDocument::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['status' => 'none', 'files' => []]
        );

        return response()->json($doc);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $doc = OwnerDocument::firstOrCreate(['user_id' => $user->id]);

        if (in_array($doc->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'الطلبات قيد المراجعة أو المقبولة لا يمكن تعديلها.'], 403);
        }

        $uploadedFiles = $doc->files ?? [];

        // معالجة كل ملف مرفق بناءً على الـ key الخاص به (مثل assets, cert, proof)
        foreach ($request->allFiles() as $slotId => $file) {
            $path = $file->store('documents', 'public');
            $uploadedFiles[$slotId] = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'type' => $file->getClientMimeType(),
                'path' => '/storage/' . $path
            ];
        }

        $doc->update([
            'status' => 'pending',
            'files' => $uploadedFiles,
            'submitted_at' => now(),
        ]);

        return response()->json($doc);
    }
}