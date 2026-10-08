<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
<<<<<<< HEAD
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Csv;
=======
use App\Models\User;
use App\Models\Workspace;
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
        $query = $this->applyFilters(User::whereIn('role', ['customer', 'space_owner']), $request);

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
     * Apply A4.1 filters to a user query.
     * Shared by index() and export() so both honour the same filter set.
     *
     * Also whitelists orderBy columns to prevent SQL injection via the
     * unvalidated `direction` parameter (previously passed straight to
     * orderBy()).
     */
    private function applyFilters($query, Request $request)
    {
        // Search (q) — name/email/phone (phone match ignores formatting)
=======
        $query = User::whereIn('role', ['customer', 'space_owner']);

        // Search
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
        if ($request->has('min_bookings')) {
            $min = $request->integer('min_bookings');
            $query->whereHas('bookings', function($q) use ($min) {
                $q->select(DB::raw('1'))->limit($min);
            }, '>=', $min);
        }

        // Sort — whitelist columns to prevent SQL injection via `direction`
        $sort = $request->get('sort', 'newest');
        $direction = $request->get('direction', 'desc');
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? $direction : 'desc';

        $sortMap = [
            'name' => 'full_name',
            'last_active' => 'updated_at',
            'bookings' => 'bookings_count', // virtual; handled below
            'newest' => 'created_at',
        ];

        $orderColumn = $sortMap[$sort] ?? 'created_at';

        if ($orderColumn === 'bookings_count') {
            $query->withCount('bookings')->orderBy('bookings_count', $direction);
        } else {
            $query->orderBy($orderColumn, $direction);
        }

        return $query;
=======

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
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
                        'name' => $w->title, // column is `title`, not `name`
                        'status' => $w->is_active ? 'active' : 'pending',
                    ])->toArray(),
                    'documents' => $this->documentsFor($user),
=======
                        'name' => $w->name,
                        'status' => $w->is_active ? 'active' : 'pending',
                    ])->toArray(),
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
                ]
            )
        ]);
    }

    /**
     * Update user status
     * PATCH /api/admin/users/{id}/status
<<<<<<< HEAD
     *
     * Valid status values are the DB enum: pending|active|suspended.
     * The legacy value "review" is NOT a legal DB value and was removed.
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
     */
    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
<<<<<<< HEAD
            'status' => 'required|in:pending,active,suspended',
=======
            'status' => 'required|in:active,suspended,review',
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
     * Verify or un-verify user
     * PATCH /api/admin/users/{id}/verify
     *
     * Body (optional): { "verified": boolean }
     * - true or omitted: verify (sets verified_at=now, status=active)
     * - false: un-verify (sets verified_at=null, status=pending)
     *
     * Returns 409 if the requested transition is a no-op.
     */
    public function verify(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'verified' => 'sometimes|boolean',
        ]);

        $wantVerified = $request->boolean('verified', true); // default true = verify

        if ($wantVerified) {
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
        } else {
            if (!$user->verified_at) {
                return response()->json([
                    'message' => 'User is not verified',
                    'errors' => []
                ], 409);
            }

            $user->verified_at = null;
            $user->status = 'pending';
            $user->save();

            return response()->json([
                'data' => [
                    'id' => $user->id,
                    'verified' => false,
                    'status' => 'pending',
                    'message' => 'تم إلغاء توثيق الحساب.',
                ]
            ]);
        }
    }

    /**
     * Build the verification documents array for a user.
     * Returns [{ url, label, name, kind }, ...]
     * - For space_owner: from Document + DocumentFile rows, plus legacy proof_document_url.
     * - For other roles: empty array.
     * Never exposes admin_note or internal review fields.
     */
    private function documentsFor(User $user): array
    {
        if ($user->role !== 'space_owner') {
            return [];
        }

        $documents = [];

        // From Document + DocumentFile
        $userDocuments = Document::where('user_id', $user->id)
            ->with('files')
            ->get();

        foreach ($userDocuments as $doc) {
            foreach ($doc->files as $file) {
                $documents[] = [
                    'url'   => $file->path,
                    'label' => $file->slot_id,
                    'name'  => $file->name,
                    'kind'  => $file->mime_type,
                ];
            }
        }

        // Legacy single-file proof_document_url on users table
        if ($user->proof_document_url) {
            $documents[] = [
                'url'   => $user->proof_document_url,
                'label' => 'legacy',
                'name'  => 'proof_document',
                'kind'  => 'application/octet-stream',
            ];
        }

        return $documents;
=======
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
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
                'suspend' => $user->update([
                    'status' => 'suspended',
                ]) && $user->tokens()->delete(),
=======
                'suspend' => $user->update(['status' => 'suspended']),
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
<<<<<<< HEAD
     *
     * Query: every A4.1 filter + optional ids (csv of ids).
     * Response: text/csv; charset=UTF-8, Content-Disposition attachment.
     * Header: name,email,phone,role,status,verified,bookings,joined
     * UTF-8 with BOM for Excel.
     */
    public function export(Request $request)
    {
        // Build query with shared filters
        $query = $this->applyFilters(User::whereIn('role', ['customer', 'space_owner']), $request);

        // Optional ids filter
        if ($request->has('ids')) {
            $ids = array_map('intval', explode(',', $request->ids));
            $query->whereIn('id', $ids);
        }

        // Use lazy cursor to avoid loading all rows into memory at once.
        // Cap at 50,000 rows as a safety valve (export is an admin action).
        $rows = $query->orderBy('created_at', 'desc')
            ->limit(50000)
            ->lazy()
            ->map(function ($user) {
                return [
                    $user->full_name,
                    $user->email,
                    $user->phone,
                    $user->role,
                    $user->status,
                    $user->verified_at ? 1 : 0,
                    $user->bookings()->count(),
                    $user->created_at->format('Y-m-d'),
                ];
            });

        $header = ['name', 'email', 'phone', 'role', 'status', 'verified', 'bookings', 'joined'];

        return Csv::download('users-' . now()->format('Y-m-d') . '.csv', $header, $rows);
=======
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
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
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
