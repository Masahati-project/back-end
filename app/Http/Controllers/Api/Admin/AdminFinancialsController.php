<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinancialsController extends Controller
{
    /**
     * Get financial summary
     * GET /api/admin/financials/summary
     */
    public function summary(Request $request)
    {
        $request->validate([
            'range' => 'required|in:today,week,month,year',
            'from' => 'required_if:range,custom|date',
            'to' => 'required_if:range,custom|date',
        ]);

        $range = $request->range;
        $settings = PlatformSetting::current();
        $commissionRate = $settings->commission_rate / 100;

        $query = Booking::whereIn('status', ['completed', 'confirmed']);

        match($range) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereMonth('created_at', now()->month),
            'year' => $query->whereYear('created_at', now()->year),
        };

        $revenue = (int) $query->sum('total_price');
        $bookings = (clone $query)->count();
        $commission = (int) ($revenue * $commissionRate);
        $payouts = (int) ($revenue - $commission);

        return response()->json([
            'data' => [
                'range' => $range,
                'revenue' => $revenue,
                'bookings' => $bookings,
                'commission' => $commission,
                'payouts' => $payouts,
                'payouts_pending' => 8240,
                'commission_rate' => $commissionRate,
            ]
        ]);
    }

    /**
     * Get daily revenue chart
     * GET /api/admin/financials/daily
     */
    public function daily(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $from = $request->from;
        $to = $request->to;

        $bookings = Booking::selectRaw('DATE(created_at) as date, SUM(total_price) as revenue, COUNT(*) as bookings')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['completed', 'confirmed'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $series = [];
        $currentDate = \Carbon\Carbon::parse($from);
        while ($currentDate->lte(\Carbon\Carbon::parse($to))) {
            $booking = $bookings->firstWhere('date', $currentDate->format('Y-m-d'));
            $series[] = [
                'date' => $currentDate->format('Y-m-d'),
                'revenue' => $booking ? (int) $booking->revenue : 0,
                'bookings' => $booking ? $booking->bookings : 0,
                'pending' => 0,
            ];
            $currentDate->addDay();
        }

        return response()->json([
            'data' => [
                'from' => $from,
                'to' => $to,
                'coverage' => ['from' => $from, 'to' => $to],
                'series' => $series,
                'totals' => [
                    'revenue' => collect($series)->sum('revenue'),
                    'bookings' => collect($series)->sum('bookings'),
                    'pending' => 8240,
                ],
            ]
        ]);
    }

    /**
     * Get commission breakdown
     * GET /api/admin/financials/commission-breakdown
     */
    public function commissionBreakdown()
    {
        $settings = PlatformSetting::current();
        $revenue = (int) Booking::whereIn('status', ['completed', 'confirmed'])
            ->whereMonth('created_at', now()->month)
            ->sum('total_price');

        $commission = (int) ($revenue * ($settings->commission_rate / 100));
        $payouts = $revenue - $commission;

        return response()->json([
            'data' => [
                ['label' => 'حجوزات', 'amount' => $revenue, 'key' => 'bookings'],
                ['label' => "عمولة المنصة ({$settings->commission_rate}%)", 'amount' => $commission, 'key' => 'commission'],
                ['label' => 'مستحقات الملاك', 'amount' => $payouts, 'key' => 'owner_payouts'],
                ['label' => 'مدفوعات معلقة', 'amount' => 8240, 'key' => 'pending_payouts'],
            ]
        ]);
    }

    /**
     * Get transactions
     * GET /api/admin/financials/transactions
     */
    public function transactions(Request $request)
    {
        $range = $request->get('range', 'month');

        $query = Booking::with('user', 'unit.workspace.owner')
            ->whereIn('status', ['completed', 'confirmed']);

        match($range) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereMonth('created_at', now()->month),
            'year' => $query->whereYear('created_at', now()->year),
        };

        $perPage = min($request->integer('per_page', 20), 100);
        $bookings = $query->paginate($perPage);

        return response()->json([
            'data' => $bookings->map(fn($b) => [
                'id' => $b->id,
                'ref' => $b->ref ?: 'BK-' . $b->id,
                'date' => $b->created_at->format('Y-m-d'),
                'space' => $b->unit->workspace->title,
                'owner' => $b->unit->workspace->owner->full_name,
                'user' => $b->user->full_name,
                'hours' => $b->start_datetime->diffInHours($b->end_datetime),
                'amount' => (int) $b->total_price,
                'status' => $b->status,
            ])->toArray(),
            'meta' => [
                'page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ]
        ]);
    }

    /**
     * Export transactions to CSV
     * GET /api/admin/financials/export
     */
    public function export(Request $request)
    {
        $query = Booking::with('user', 'unit.workspace.owner')
            ->whereIn('status', ['completed', 'confirmed']);

        $range = $request->get('range', 'month');
        match($range) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereMonth('created_at', now()->month),
            'year' => $query->whereYear('created_at', now()->year),
        };

        $bookings = $query->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "ref,date,space,owner,user,hours,amount,status\n";

        foreach ($bookings as $b) {
            $hours = $b->start_datetime->diffInHours($b->end_datetime);
            $csv .= implode(',', [
                $b->ref ?: 'BK-' . $b->id,
                $b->created_at->format('Y-m-d'),
                $b->unit->workspace->title,
                $b->unit->workspace->owner->full_name,
                $b->user->full_name,
                $hours,
                (int) $b->total_price,
                $b->status,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="financials-' . $range . '-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
