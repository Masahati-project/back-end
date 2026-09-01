<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. الحماية: التأكد من تسجيل الدخول
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // 2. حساب البيانات بأمان
        $bookingsCount = $user->bookings()->count();
        $savedSpacesCount = $user->favorites()->count();
        $totalMinutes = $user->studySessions()->sum('duration') ?? 0;
        $studyHours = round($totalMinutes / 60);

        $goal = $user->studyGoal;
        $targetHours = $goal?->target_hours ?? 0;
        $percentage = 0;

        if ($targetHours > 0) {
            $percentage = round(($studyHours / $targetHours) * 100);
        }

        // 3. إرجاع الاستجابة
        return response()->json([
            'bookingsCount' => $bookingsCount,
            'savedSpacesCount' => $savedSpacesCount,
            'studyHours' => $studyHours,
            'targetHours' => $targetHours,
            'percentage' => $percentage,
        ]);
    }
}