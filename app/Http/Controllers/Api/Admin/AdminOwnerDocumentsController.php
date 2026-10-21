<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use App\Services\SpaceDocumentService;
use Illuminate\Http\Request;

/**
 * The admin's review queue for an owner's proof of identity.
 *
 * This does not decide whether an owner may list a space — owners register
 * active and POST /owner/spaces straight away, and the listing they create is
 * what sits in `pending` until it is approved. What this records is the admin's
 * conclusion about the owner's own document.
 *
 * Approving still writes both halves together, so the document's decision and
 * the account's `status` cannot disagree about a user the admin has suspended.
 */
class AdminOwnerDocumentsController extends Controller
{
    /**
     * The queue: owners with proof awaiting a decision.
     *
     * Defaults to `pending` because that is what an admin opens this screen for.
     */
    public function index(Request $request)
    {
        // The admin's space preview asks for one listing's proof files:
        // GET /api/admin/documents?space_id=12. That is a different question
        // from the owner-verification queue below, so it gets a flat,
        // file-per-entry response the preview adapter can read directly.
        if ($request->has('space_id')) {
            return $this->documentsForSpace($request);
        }

        $status = $request->query('status', 'pending');

        if (! in_array($status, ['none', 'pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        $documents = Document::with(['files', 'owner'])
            ->where('status', $status)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $documents->getCollection()->map(fn (Document $document) => $this->formatDocument($document))->values(),
            'meta' => [
                'page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'last_page' => $documents->lastPage(),
            ],
        ]);
    }

    /**
     * The proof files attached to one listing, as a flat list.
     *
     * Both `documents` and `data` are emitted because the preview reads one of
     * them depending on the client version; the adapter normalises whichever
     * key it finds. The status filter is optional so the preview can show the
     * evidence after an approve/reject decision, not only while pending.
     */
    private function documentsForSpace(Request $request)
    {
        $validated = $request->validate([
            'space_id' => ['required', 'integer', 'min:1'],
        ]);

        $space = Workspace::with('owner')->find($validated['space_id']);

        if (!$space) {
            return response()->json([
                'message' => 'المساحة غير موجودة.',
                'documents' => [],
                'data' => [],
            ], 404);
        }

        $status = $request->query('status');
        $status = is_string($status) ? $status : null;

        $documents = SpaceDocumentService::filterByStatus(
            SpaceDocumentService::listFor($space),
            $status
        );

        return response()->json([
            'message' => 'تم جلب مستندات المساحة بنجاح.',
            'space_id' => $space->id,
            'space_title' => $space->title,
            'owner_id' => $space->owner_id,
            'owner_name' => $space->owner?->full_name,
            'documents' => $documents,
            'data' => $documents,
            'meta' => [
                'total' => count($documents),
            ],
        ]);
    }

    /**
     * Everything one owner has submitted, across every submission.
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        $documents = Document::where('user_id', $user->id)
            ->with('files')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Document $document) => $this->formatDocument($document))
            ->values();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
                'proof_document' => $user->proof_document_url,
                'documents' => $documents,
            ],
        ]);
    }

    /**
     * Record the decision.
     *
     * Body: { "status": "approved" | "rejected", "note": "..." (optional) }
     */
    public function updateStatus(Request $request, $id)
    {
        $user = User::where('role', 'space_owner')->findOrFail($id);

        $request->validate([
            'status' => 'required|in:approved,rejected',
            'note' => 'nullable|string|max:1000',
        ]);

        $status = $request->input('status');
        $note = $request->input('note');

        $document = Document::where('user_id', $user->id)
            ->with('files')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($status === 'approved' && (! $document || $document->files->isEmpty())) {
            return response()->json([
                'message' => 'لا توجد مستندات مرفوعة واعتمادها.',
                'errors' => ['status' => ['لا يمكن الاعتماد قبل رفع مستند.']],
            ], 422);
        }

        $document?->update([
            'status' => $status,
            'note' => $note,
            'reviewed_at' => now(),
        ]);

        if ($status === 'approved') {
            $user->forceFill([
                'status' => 'active',
                'verified_at' => $user->verified_at ?? now(),
            ])->save();
        }

        NotificationService::create(
            $user->id,
            'owner_verification',
            $status === 'approved'
                ? 'تمت الموافقة على مستند إثبات الملكية.'
                : 'تم رفض مستند إثبات الملكية. يرجى رفع مستند صحيح.'
                . ($note ? ' السبب: ' . $note : ''),
        );

        return response()->json([
            'message' => 'تم تحديث حالة التوثيق.',
            'data' => [
                'id' => $user->id,
                'document_status' => $status,
                'user_status' => $user->fresh()->status,
                'note' => $note,
            ],
        ]);
    }

    /**
     * The document as the review screen needs it.
     *
     * `url` is the field the "فتح المستند" control reads, and every entry
     * carries one — a row whose file has no resolvable URL would render a button
     * that opens nothing.
     */
    private function formatDocument(Document $document): array
    {
        return [
            'id' => $document->id,
            'user_id' => $document->user_id,
            'owner_name' => $document->owner?->full_name,
            'owner_email' => $document->owner?->email,
            'status' => $document->status,
            'note' => $document->note,
            'review_note' => $document->note,
            'submitted_at' => optional($document->submitted_at)->toIso8601String(),
            'reviewed_at' => optional($document->reviewed_at)->toIso8601String(),
            'files' => $document->files->map(fn ($file) => [
                'id' => $file->id,
                'slot' => $file->slot_id,
                'name' => $file->name,
                'size' => $file->size,
                'type' => $file->mime_type,
                'url' => $this->fileUrl($file->path),
                'path' => $file->path,
            ])->values(),
        ];
    }

    /**
     * Cloudinary paths become absolute URLs; values already absolute pass
     * through untouched.
     */
    private function fileUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk('cloudinary')->url($path);
        } catch (\Throwable) {
            // A missing asset must not take the whole review queue down; the
            // stored path is still something the admin client can show.
            return $path;
        }
    }
}
