<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Booking;
use App\Models\Dispute;
use App\Models\PlatformSetting;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOverviewController extends Controller
{
    /**
     * Get admin dashboard stats
     * GET /api/admin/stats
     */
    public function stats()
    {
        $settings = PlatformSetting::current();
        $commissionRate = $settings->commission_rate / 100;

        $totalUsers = User::whereIn('role', ['freelancer', 'owner'])->count();
        $spaceOwners = User::where('role', 'owner')->count();
        $registeredSpaces = Workspace::count();
        $monthlyBookings = Booking::whereMonth('created_at', now()->month)->count();

        $totalRevenue = Booking::whereIn('status', ['completed', 'confirmed'])
            ->sum('total_price');

        $openDisputes = Dispute::where('status', 'open')->count();

        $payoutsPending = Booking::where('status', 'completed')
            ->whereDoesntHave('payment', function($q) {
                $q->where('status', 'paid');
            })
            ->sum('total_price') * (1 - $commissionRate);

        $activeSpaces = Workspace::where('is_active', true)->count();
        $pendingSpaces = Workspace::where('is_active', false)->count();
        $suspendedSpaces = 0; // TODO: add suspended status to workspaces

        return response()->json([
            'data' => [
                'totalUsers' => $totalUsers,
                'spaceOwners' => $spaceOwners,
                'registeredSpaces' => $registeredSpaces,
                'monthlyBookings' => $monthlyBookings,
                'totalRevenue' => (int) $totalRevenue,
                'openDisputes' => $openDisputes,
                'platformCommission' => $commissionRate,
                'payoutsPending' => (int) $payoutsPending,
                'activeSpaces' => $activeSpaces,
                'pendingSpaces' => $pendingSpaces,
                'suspendedSpaces' => $suspendedSpaces,
            ]
        ]);
    }

    /**
     * Get revenue trend chart data
     * GET /api/admin/stats/revenue-trend
     */
    public function revenueTrend(Request $request)
    {
        $months = $request->integer('months', 12);
        $months = min(max($months, 1), 24);

        $startDate = now()->subMonths($months - 1)->startOfMonth();

        $data = Booking::select(
            DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month_key'),
            DB::raw('SUM(total_price) as revenue'),
            DB::raw('COUNT(*) as bookings')
        )
        ->where('created_at', '>=', $startDate)
        ->whereIn('status', ['completed', 'confirmed'])
        ->groupBy('month_key')
        ->orderBy('month_key')
        ->get();

        $arabicMonths = [
            '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس',
            '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو',
            '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر',
            '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
        ];

        $result = $data->map(function ($item) use ($arabicMonths) {
            $monthNum = substr($item->month_key, 5, 2);
            return [
                'month' => $arabicMonths[$monthNum] ?? $monthNum,
                'month_key' => $item->month_key,
                'revenue' => (int) $item->revenue,
                'bookings' => $item->bookings,
            ];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Get recent admin activities
     * GET /api/admin/activities
     */
    public function activities(Request $request)
    {
        $limit = $request->integer('limit', 10);
        $limit = min(max($limit, 1), 50);

        $activities = AdminActivity::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'icon' => $activity->icon,
                    'text' => $activity->text,
                    'time' => $this->formatArabicRelativeTime($activity->created_at),
                    'created_at' => $activity->created_at->toIso8601String(),
                ];
            });

        return response()->json(['data' => $activities]);
    }

    /**
     * Get recent registrations
     * GET /api/admin/recent-registrations
     */
    public function recentRegistrations(Request $request)
    {
        $limit = $request->integer('limit', 5);
        $limit = min(max($limit, 1), 50);

        $users = User::whereIn('role', ['freelancer', 'owner'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'role' => $user->role,
                    'time' => $this->formatArabicRelativeTime($user->created_at),
                    'created_at' => $user->created_at->toIso8601String(),
                ];
            });

        return response()->json(['data' => $users]);
    }

    /**
     * Format Arabic relative time
     */
    private function formatArabicRelativeTime($datetime)
    {
        $diff = now()->diffInMinutes($datetime);

        if ($diff < 1) return 'الآن';
        if ($diff < 60) return "منذ {$diff} دقيقة";

        $hours = floor($diff / 60);
        if ($hours < 24) {
            return $hours == 1 ? 'منذ ساعة' : "منذ {$hours} ساعات";
        }

        $days = floor($hours / 24);
        if ($days == 1) return 'أمس';
        if ($days < 7) return "منذ {$days} أيام";

        return $datetime->format('Y-m-d');
    }
}
