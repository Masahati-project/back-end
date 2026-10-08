<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
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

    private function applyFilters($query, Request $request)
    {
        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('full_name', 'like', "%$q%")
                   ->orWhere('email', 'like', "%$q%")
                   ->orWhere('phone', 'like', "%$q%");
            });
        }

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
        if ($request->has('min_bookings')) {
            $min = $request->integer('min_bookings');
            $query->whereHas('bookings', function($q) use ($min) {
                $q->select(DB::raw('1'))->limit($min);
            }, '>=', $min);
        }

        $sort = $request->get('sort', 'newest');
        $direction = $request->get('direction', 'desc');
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? $direction : 'desc';

        $sortMap = [
            'name' => 'full_name',
            'last_active' => 'updated_at',
            'bookings' => 'bookings_count',
            'newest' => 'created_at',
        ];

        $orderColumn = $sortMap[$sort] ?? 'created_at';

        if ($orderColumn === 'bookings_count') {
            $query->withCount('bookings')->orderBy('bookings_count', $direction);
        } else {
            $query->orderBy($orderColumn, $direction);
        }

        return $query;
    }

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
                'activeFreelancers' => (clone $query)->where('role', 'customer')->where('status', 'active')->count(),
                'owners' => (clone $query)->where('role', 'space_owner')->count(),
                'pendingVerif' => (clone $query)->whereNull('verified_at')->count(),
                'suspended' => (clone $query)->where('status', 'suspended')->count(),
            ]
        ]);
    }

    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'data' => array_merge(
                $this->formatUserRow($user),
                [
                    'spaces' => $user->workspaces->map(fn($w) => [
                        'id' => $w->id,
                        'name' => $w->title,
                        'status' => $w->is_active ? 'active' : 'pending',
                    ])->toArray(),
                    'documents' => $this->documentsFor($user),
                ]
            )
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'status' => 'required|in:pending,active,suspended',
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

    public function verify(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'verified' => 'sometimes|boolean',
        ]);

        $wantVerified = $request->boolean('verified', true);

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

    private function documentsFor(User $user): array
    {
        if ($user->role !== 'space_owner') {
            return [];
        }

        $documents = [];

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

        if ($user->proof_document_url) {
            $documents[] = [
                'url'   => $user->proof_document_url,
                'label' => 'legacy',
                'name'  => 'proof_document',
                'kind'  => 'application/octet-stream',
            ];
        }

        return $documents;
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|min:1|max:120',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|string|max:30',
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
                'suspend' => $user->update([
                    'status' => 'suspended',
                ]) && $user->tokens()->delete(),
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

    public function export(Request $request)
    {
        $query = $this->applyFilters(User::whereIn('role', ['customer', 'space_owner']), $request);

        if ($request->has('ids')) {
            $ids = array_map('intval', explode(',', $request->ids));
            $query->whereIn('id', $ids);
        }

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