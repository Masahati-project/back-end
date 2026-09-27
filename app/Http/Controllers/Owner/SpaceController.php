<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\OwnerDocument;
use App\Traits\HandlesBase64Images;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SpaceController extends Controller
{
    use HandlesBase64Images;

    /**
     * عرض جميع المساحات الخاصة بالمالك الحالي
     */
    public function index(Request $request)
    {
        $spaces = Space::where('user_id', $request->user()->id)->latest()->get();
        return response()->json(['spaces' => $spaces], 200);
    }

    /**
     * إضافة مساحة جديدة
     */
    public function store(Request $request)
    {
        // 1. التحقق من التوثيق
        $docStatus = OwnerDocument::where('user_id', $request->user()->id)->value('status');
        if ($docStatus !== 'approved') {
            return response()->json([
                'message' => 'يجب التوثيق واعتماد المستندات قبل إضافة المساحات.'
            ], 403);
        }

        // 2. التحقق من المدخلات (Validation)
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'location'    => 'nullable|string',
            'capacity'    => 'nullable|integer',
            'image'       => 'nullable|string', // Base64 String
        ]);

        $validatedData['user_id'] = $request->user()->id;
        $validatedData['status'] = 'pending';

        // 3. رفع الصورة في حال وجودها
        if ($request->filled('image')) {
            $validatedData['image'] = $this->uploadBase64Image($request->input('image'), 'spaces');
        }

        $space = Space::create($validatedData);

        return response()->json([
            'message' => 'تمت إضافة المساحة بنجاح وفي انتظار المراجعة.',
            'space'   => $space
        ], 201);
    }

    /**
     * تحديث بيانات المساحة
     */
    public function update(Request $request, $id)
    {
        $space = Space::where('user_id', $request->user()->id)->find($id);

        if (!$space) {
            return response()->json(['message' => 'المساحة غير موجودة أو لا تملك صلاحية تعديلها.'], 404);
        }

        $validatedData = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|required|numeric|min:0',
            'location'    => 'nullable|string',
            'capacity'    => 'nullable|integer',
            'image'       => 'nullable|string',
        ]);

        // معالجة الصورة في حال تم إرسال صورة جديدة
        if ($request->filled('image')) {
            $validatedData['image'] = $this->uploadBase64Image($request->input('image'), 'spaces');
        }

        $space->update($validatedData);

        return response()->json([
            'message' => 'تم تحديث البيانات بنجاح.',
            'space'   => $space
        ], 200);
    }

    /**
     * تفعيل أو تعطيل المساحة
     */
    public function toggleActive(Request $request, $id)
    {
        $space = Space::where('user_id', $request->user()->id)->find($id);

        if (!$space) {
            return response()->json(['message' => 'المساحة غير موجودة.'], 404);
        }

        $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);

        $space->update([
            'is_active' => $isActive,
            'status'    => $isActive ? 'active' : 'inactive'
        ]);

        return response()->json([
            'message' => 'تم تغيير حالة المساحة بنجاح.',
            'space'   => $space
        ], 200);
    }

    /**
     * حذف المساحة
     */
    public function destroy(Request $request, $id)
    {
        $space = Space::where('user_id', $request->user()->id)->find($id);

        if (!$space) {
            return response()->json(['message' => 'المساحة غير موجودة أو تم حذفها بالفعل.'], 404);
        }

        $space->delete();

        return response()->json(['message' => 'تم حذف المساحة بنجاح.'], 200);
    }
}