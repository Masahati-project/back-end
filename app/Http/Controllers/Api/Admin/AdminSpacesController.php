<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminSpacesController extends Controller
{
    /**
     * The four states a space can be in, in the order the admin queue shows them.
     *
     * Rejection and suspension are deliberately separate: a rejected space never
     * went live, a suspended one did and can be brought back. Collapsing them —
     * which is what the old `status` filter did, treating everything that was not
     * "active" as one bucket — is what made the rejected queue unusable.
     */
    public const STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    /**
     * Narrows a query to one lifecycle state.
     *
     * `active` additionally requires the owner's own listing toggle, because a
     * space the owner has taken off the market is not live even while the admin
     * decision stands.
     */
    private function applyStatusFilter($query, ?string $status): void
    {
        if ($status === null || $status === '' || ! in_array($status, self::STATUSES, true)) {
            return;
        }

        if ($status === 'active') {
            $query->where('workspaces.status', 'active')
                ->where('workspaces.is_active', true);

            return;
        }

        $query->where('workspaces.status', $status);
    }

    /**
     * The state reported to the admin UI.
     *
     * Read straight off the column. Deriving it from `is_active` — as the old
     * formatter did with `$space->is_active ? 'active' : 'pending'` — reported
     * every suspended and rejected space as "pending", which is why the rejected
     * tab looked permanently empty.
     */
    private function resolveStatus(Workspace $space): string
    {
        return in_array($space->status, self::STATUSES, true) ? $space->status : 'pending';
    }

    public function index(Request $request)
    {
        $query = Workspace::with('owner', 'reviews');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                   ->orWhere('location', 'like', "%$q%")
                   ->orWhereHas('owner', function($o) use ($q) {
                       $o->where('full_name', 'like', "%$q%");
                   });
            });
        }

        // Any of the four states, or none at all for "everything".
        $this->applyStatusFilter($query, $request->query('status'));

        if ($request->has('neighborhood')) {
            $query->where('location', 'like', '%' . $request->neighborhood . '%');
        }

        if ($request->has('owner_id')) {
            $query->where('owner_id', $request->owner_id);
        }

        $sort = $request->get('sort', 'newest');
        match($sort) {
            'rating' => $query->with('reviews')->orderByDesc('rating'),
            'price' => $query->orderBy('price'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min($request->integer('per_page', 20), 100);
        $spaces = $query->paginate($perPage);

        return response()->json([
            'data' => $spaces->map(fn($s) => $this->formatSpaceRow($s))->toArray(),
            'meta' => [
                'page' => $spaces->currentPage(),
                'per_page' => $spaces->perPage(),
                'total' => $spaces->total(),
                'last_page' => $spaces->lastPage(),
            ]
        ]);
    }

    public function stats(Request $request)
    {
        $query = Workspace::query();

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                   ->orWhere('location', 'like', "%$q%");
            });
        }

        // Each count starts from a clean copy. Chaining onto the same builder
        // would make every tally below inherit the previous one's constraint —
        // "pending" would silently be counted as "pending AND is_active".
        $all = (clone $query)->count();

        $byStatus = [];
        foreach (self::STATUSES as $status) {
            $byStatus[$status] = (clone $query)->where('workspaces.status', $status)->count();
        }

        return response()->json([
            'data' => [
                'all' => $all,
                'pending' => $byStatus['pending'],
                'active' => $byStatus['active'],
                'suspended' => $byStatus['suspended'],
                'rejected' => $byStatus['rejected'],
            ]
        ]);
    }

    public function show($id)
    {
        $space = Workspace::with('owner', 'reviews', 'bookings')->findOrFail($id);

        $detail = array_merge(
            $this->formatSpaceDetail($space),
            [
                'recent_bookings' => $space->bookings()->latest()->take(5)->get()->toArray(),
                'recent_reviews' => $space->reviews()->latest()->take(5)->get()->toArray(),
            ]
        );

        // The approval flow's whole point is that the admin can open the deed or
        // lease behind the listing. `documents` is what the "فتح المستند" button
        // reads, so it is emitted at the top level and repeated under `space` —
        // both shapes are in the contract.
        $detail['documents'] = $this->spaceDocuments($space);

        return response()->json([
            'data' => $detail,
            'space' => $detail,
        ]);
    }

    /**
     * The proof files attached to a listing, for the admin approval screen.
     *
     * Every entry carries a `url` and an absolute one, because that is the only
     * field the frontend actually consumes before rendering the open-document
     * button. Two places can hold a file: the per-space verification row (the
     * upload endpoint's record) and the column on the listing itself, which
     * predates it and is still written by older clients.
     *
     * @return list<array<string, mixed>>
     */
    private function spaceDocuments(Workspace $space): array
    {
        $documents = [];

        foreach ($space->spaceDocuments()->orderByDesc('id')->get() as $verification) {
            if (blank($verification->proof_document_url)) {
                continue;
            }

            $documents[] = [
                'kind' => 'proof',
                'name' => $verification->proof_document_url,
                'url' => $this->absoluteDocumentUrl($verification->proof_document_url),
                'path' => $verification->proof_document_url,
                'status' => $verification->status,
                'uploaded_at' => optional($verification->created_at)->toIso8601String(),
            ];
        }

        // Older listings predate the upload endpoint and only carry the column.
        if (blank($space->space_document_url)) {
            return $documents;
        }

        $alreadyListed = collect($documents)->contains(
            fn (array $document) => $document['path'] === $space->space_document_url
        );

        if (! $alreadyListed) {
            $documents[] = [
                'kind' => 'proof',
                'name' => $space->space_document_url,
                'url' => $this->absoluteDocumentUrl($space->space_document_url),
                'path' => $space->space_document_url,
                'status' => 'pending',
                'uploaded_at' => optional($space->created_at)->toIso8601String(),
            ];
        }

        return $documents;
    }

    /**
     * Files live on the cloudinary disk and are stored as relative paths, while
     * older rows and client-supplied values can already hold an absolute URL.
     * Normalise both into something an <a href> can open.
     */
    private function absoluteDocumentUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('cloudinary')->url($path);
    }

    public function update(Request $request, $id)
    {
        $space = Workspace::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|min:1|max:80',
            'neighborhood' => 'sometimes|string|min:1|max:80',
            'price' => 'sometimes|numeric|min:1|max:100000',
            'capacity' => 'sometimes|integer|min:1|max:1000',
            'image' => 'sometimes|nullable|string|max:300',
        ]);

        if ($request->has('name')) {
            $space->title = $request->name;
        }
        if ($request->has('neighborhood')) {
            $space->location = $request->neighborhood;
        }
        if ($request->has('image') && $request->image) {
            // TODO: handle image update
        }

        $space->save();

        return response()->json([
            'data' => $this->formatSpaceDetail($space)
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $space = Workspace::with('owner')->findOrFail($id);

        $request->validate([
            // All four states, including `rejected`. Rejecting a pending listing
            // is a decision the contract requires the backend to accept and keep;
            // truncating it as an unknown value is what made the rejected queue
            // unusable. Suspension is likewise distinct from rejection.
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);

        $status = $request->status;

        // The decision is recorded on the status column. Only flipping is_active
        // here was the core defect: the catalogue filters on status, so an
        // approved space stayed "pending" forever and never went public.
        $space->status = $status;

        // The owner's own listing toggle follows the admin decision, so an
        // approved space is live and a pulled one is not. Re-approving a space
        // the owner had since switched off must not silently re-list it, so a
        // space that is being approved keeps the owner's toggle exactly as they
        // left it and only `active` from a non-active state re-lists it.
        if ($space->is_active === false && $status === 'active') {
            $space->is_active = true;
        } elseif ($status !== 'active') {
            $space->is_active = false;
        }

        $space->save();

        $this->syncVerificationStatus($space, $status);
        $this->notifyOwnerOfDecision($space, $status);

        return response()->json([
            'data' => [
                'id' => $space->id,
                'status' => $status,
                'message' => 'تم تحديث حالة المساحة.',
            ]
        ]);
    }

    /**
     * Keeps the listing's proof document in step with the admin decision, so the
     * approval screen and the owner's document list cannot disagree.
     */
    private function syncVerificationStatus(Workspace $space, string $status): void
    {
        $verificationStatus = match ($status) {
            'active' => 'approved',
            'rejected' => 'rejected',
            // A suspension is about the listing, not the deed, so the document
            // keeps whatever review state it already had.
            default => null,
        };

        if ($verificationStatus === null) {
            return;
        }

        $space->verification()->update(['status' => $verificationStatus]);
    }

    /**
     * Tells the owner what happened to their listing.
     *
     * Requirement 3.7: every admin decision has to reach the owner's inbox. The
     * message carries the state rather than a generic "updated" so the owner sees
     * a rejection as a rejection.
     */
    private function notifyOwnerOfDecision(Workspace $space, string $status): void
    {
        $label = match ($status) {
            'active' => 'تمت الموافقة على مساحتك «' . $space->title . '» وهي الآن متاحة للحجز.',
            'suspended' => 'تم إيقاف مساحتك «' . $space->title . '» مؤقتاً من قبل الإدارة.',
            'rejected' => 'تم رفض مساحتك «' . $space->title . '» من قبل الإدارة.',
            default => 'مساحتك «' . $space->title . '» عادت بانتظار المراجعة.',
        };

        $type = match ($status) {
            'active' => 'space_request',
            'suspended' => 'space_request',
            default => 'owner_verification',
        };

        NotificationService::create($space->owner_id, $type, $label);
    }

    public function destroy($id)
    {
        $space = Workspace::findOrFail($id);

        if ($space->bookings()->where('bookings.status', 'confirmed')->exists()) {
            return response()->json([
                'message' => 'Cannot delete space with upcoming bookings',
                'errors' => []
            ], 409);
        }

        $space->delete();

        return response()->noContent();
    }

    public function export(Request $request)
    {
        $query = Workspace::with('owner');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%");
            });
        }

        $spaces = $query->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "id,name,neighborhood,owner,price,status,rating,bookings,capacity\n";

        foreach ($spaces as $space) {
            $rating = round($space->reviews()->avg('rating') ?? 0, 1);
            $csv .= implode(',', [
                $space->id,
                $space->title,
                $space->location,
                $space->owner->full_name,
                $this->spacePrice($space),
                $this->resolveStatus($space),
                $rating,
                $space->bookings()->count(),
                $this->spaceCapacity($space),
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="spaces-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    private function formatSpaceRow($space)
    {
        $rating = round($space->reviews()->avg('rating') ?? 0, 1);
        $price = $this->spacePrice($space);
        $capacity = $this->spaceCapacity($space);
        $status = $this->resolveStatus($space);

        return [
            'id' => $space->id,
            'space_id' => $space->id,
            'name' => $space->title,
            'title' => $space->title,
            'neighborhood' => $space->location,
            'owner' => $space->owner->full_name,
            'price' => $price,
            // The admin's own listing toggle, kept separate from the lifecycle
            // state above: a space can be approved while its owner has it paused.
            'is_active' => (bool) $space->is_active,
            'status' => $status,
            'rating' => $rating,
            'bookings' => $space->bookings()->count(),
            'capacity' => $capacity,
            'image' => $space->images()->first()?->image_url,
        ];
    }

    private function formatSpaceDetail($space)
    {
        $price = $this->spacePrice($space);
        $capacity = $this->spaceCapacity($space);

        return [
            'id' => $space->id,
            'space_id' => $space->id,
            'name' => $space->title,
            'title' => $space->title,
            'neighborhood' => $space->location,
            'location' => $space->location,
            'owner' => $space->owner->full_name,
            'owner_id' => $space->owner_id,
            'price' => $price,
            'is_active' => (bool) $space->is_active,
            'status' => $this->resolveStatus($space),
            'rating' => round($space->reviews()->avg('rating') ?? 0, 1),
            'bookings' => $space->bookings()->count(),
            'capacity' => $capacity,
            'image' => $space->images()->first()?->image_url,
            'description' => $space->description,
            'created_at' => $space->created_at->toIso8601String(),
        ];
    }

    /**
     * The hourly price a space is actually listed at, or null when it has no
     * unit or no hourly pricing row.
     *
     * Returning the fallback 15 / 24 these two helpers used to invent meant the
     * admin queue showed a price and capacity for every listing, including the
     * ones that have neither — and hid the genuinely missing ones behind a
     * plausible-looking number.
     */
    private function spacePrice(Workspace $space): mixed
    {
        $pricing = $space->units()
            ->join('pricing', 'pricing.unit_id', '=', 'units.id')
            ->where('pricing.price_type', 'hourly')
            ->orderByDesc('pricing.created_at')
            ->select('pricing.price')
            ->first();

        return $pricing?->price;
    }

    private function spaceCapacity(Workspace $space): mixed
    {
        $unit = $space->units()->orderBy('id')->first();

        return $unit?->capacity;
    }
}