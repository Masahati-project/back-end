<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsersController extends Controller
{
    /**
     * List users with filters
     * GET /api/admin/users
     */
    public function index(Request $request)
    {
        $query = User::whereIn('role', ['customer', 'space_owner']);

        // Search
        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('full_name', 'like', "%$q%")
                   ->orWhere('email', 'like', "%$q%")
                   ->orWhere('phone', 'like', "%$q%");
            });
        }

        // Filters
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('verified')) {
            $isVerified = $request->verified === 'verified';
            $query->whereNotNull($isVerified ? 'verified_at' : DB::raw('null'));
        }
        if ($request->has('joined_from')) {
            $query->whereDate('created_at', '>=', $request->joined_from);
        }
        if ($request->has('joined_to')) {
            $query->whereDate('created_at', '<=', $request->joined_to);
        }

        // Sort
        $sort = $request->get('sort', 'newest');
        $direction = $request->get('direction', 'desc');

        match($sort) {
            'name' => $query->orderBy('full_name', $direction),
            'last_active' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('created_at', $direction),
        };

        $perPage = min($request->integer('per_page', 10), 100);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->map(fn($u) => $this->formatUserRow($u))->toArray(),
            'meta' => [
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ]
        ]);
    }

    /**
     * Get user stats
     * GET /api/admin/users/stats
     */
    public function stats(Request $request)
    {
        $query = User::whereIn('role', ['customer', 'space_owner']);

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('full_name', 'like', "%$q%")
                   ->orWhere('email', 'like', "%$q%");
            });
        }

        return response()->json([
            'data' => [
                'total' => $query->count(),
                'activeCustomers' => (clone $query)->where('role', 'customer')->where('status', 'active')->count(),
                // Alias kept for the existing admin panel; the role used to be
                // called "freelancer" before the rename to "customer".
                'activeFreelancers' => (clone $query)->where('role', 'customer')->where('status', 'active')->count(),
                'owners' => (clone $query)->where('role', 'space_owner')->count(),
                'pendingVerif' => (clone $query)->whereNull('verified_at')->count(),
                'suspended' => (clone $query)->where('status', 'suspended')->count(),
            ]
        ]);
    }

    /**
     * Get user detail
     * GET /api/admin/users/{id}
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'data' => array_merge(
                $this->formatUserRow($user),
                [
                    'spaces' => $user->workspaces->map(fn($w) => [
                        'id' => $w->id,
                        'name' => $w->name,
                        'status' => $w->is_active ? 'active' : 'pending',
                    ])->toArray(),
                ]
            )
        ]);
    }

    /**
     * Update user status
     * PATCH /api/admin/users/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'status' => 'required|in:active,suspended,review',
        ]);

        $user->status = $request->status;
        if ($request->status === 'suspended') {
            $user->tokens()->delete();
        }
        $user->save();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'status' => $user->status,
                'message' => 'تم تحديث حالة المستخدم.',
            ]
        ]);
    }

    /**
     * Verify user
     * PATCH /api/admin/users/{id}/verify
     */
    public function verify($id)
    {
        $user = User::findOrFail($id);

        if ($user->verified_at) {
            return response()->json([
                'message' => 'User already verified',
                'errors' => []
            ], 409);
        }

        $user->verified_at = now();
        $user->status = 'active';
        $user->save();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'verified' => true,
                'status' => 'active',
                'message' => 'تم توثيق الحساب.',
            ]
        ]);
    }

    /**
     * Update user
     * PATCH /api/admin/users/{id}
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|min:1|max:120',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|string|max:30',
            // The admin panel still sends the legacy names, so both spellings are
            // accepted here and normalised to the values the users.role enum holds.
            'role' => 'sometimes|in:customer,space_owner,admin,freelancer,owner',
        ]);

        if ($request->has('name')) {
            $user->full_name = $request->name;
        }
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        if ($request->has('role')) {
            $user->role = match ($request->role) {
                'freelancer' => 'customer',
                'owner' => 'space_owner',
                default => $request->role,
            };
        }

        $user->save();

        return response()->json([
            'data' => $this->formatUserRow($user)
        ]);
    }

    /**
     * Delete user
     * DELETE /api/admin/users/{id}
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->bookings()->where('status', '!=', 'completed')->exists()) {
            return response()->json([
                'message' => 'Cannot delete user with active bookings',
                'errors' => []
            ], 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    /**
     * Bulk status update
     * POST /api/admin/users/bulk-status
     */
    public function bulkStatus(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|in:suspend,activate,verify',
        ]);

        $users = User::whereIn('id', $request->ids)->get();

        $users->each(function($user) use ($request) {
            match($request->action) {
                'suspend' => $user->update(['status' => 'suspended']),
                'activate' => $user->update(['status' => 'active']),
                'verify' => $user->update(['verified_at' => now(), 'status' => 'active']),
            };
        });

        return response()->json([
            'data' => [
                'updated' => $users->count(),
                'ids' => $request->ids,
            ]
        ]);
    }

    /**
     * Export users to CSV
     * GET /api/admin/users/export
     */
    public function export(Request $request)
    {
        $query = User::whereIn('role', ['customer', 'space_owner']);

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('full_name', 'like', "%$q%")
                   ->orWhere('email', 'like', "%$q%");
            });
        }

        $users = $query->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "name,email,phone,role,status,verified,bookings,joined\n";

        foreach ($users as $user) {
            $verified = $user->verified_at ? 1 : 0;
            $bookings = $user->bookings()->count();
            $csv .= implode(',', [
                $user->full_name,
                $user->email,
                $user->phone,
                $user->role,
                $user->status,
                $verified,
                $bookings,
                $user->created_at->format('Y-m-d'),
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    private function formatUserRow($user)
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'verified' => (bool) $user->verified_at,
            'joined' => $user->created_at->format('Y-m-d'),
            'lastActive' => $this->formatArabicRelativeTime($user->updated_at),
            'online' => $user->updated_at->diffInMinutes(now()) < 5,
            'bookings' => $user->bookings()->count(),
            'activity' => [0, 0, 0, 0, 0, 0, 0],
        ];
    }

    private function formatArabicRelativeTime($datetime)
    {
        $diff = now()->diffInMinutes($datetime);
        if ($diff < 1) return 'متصلاً الآن';
        if ($diff < 60) return "منذ $diff دقيقة";
        $hours = floor($diff / 60);
        if ($hours < 24) return "منذ $hours ساعات";
        return $datetime->format('Y-m-d');
    }
}
