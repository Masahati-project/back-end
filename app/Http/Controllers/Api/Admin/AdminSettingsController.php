<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    /**
     * Get platform settings
     * GET /api/admin/settings
     */
    public function index()
    {
        $settings = PlatformSetting::current();

        return response()->json([
            'data' => [
                'commission_rate' => (float) $settings->commission_rate,
                'booking_grace_period_hours' => $settings->booking_grace_period_hours,
                'auto_approve_bookings' => $settings->auto_approve_bookings,
                'currency' => $settings->currency,
                'updated_at' => $settings->updated_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Update platform settings
     * PUT /api/admin/settings
     */
    public function update(Request $request)
    {
        $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100',
            'booking_grace_period_hours' => 'required|integer|min:0',
            'auto_approve_bookings' => 'required|boolean',
        ]);

        $settings = PlatformSetting::current();
        $settings->update([
            'commission_rate' => $request->commission_rate,
            'booking_grace_period_hours' => $request->booking_grace_period_hours,
            'auto_approve_bookings' => $request->auto_approve_bookings,
        ]);

        return response()->json([
            'data' => [
                'commission_rate' => (float) $settings->commission_rate,
                'booking_grace_period_hours' => $settings->booking_grace_period_hours,
                'auto_approve_bookings' => $settings->auto_approve_bookings,
                'currency' => $settings->currency,
                'updated_at' => $settings->updated_at->toIso8601String(),
            ]
        ]);
    }
}
