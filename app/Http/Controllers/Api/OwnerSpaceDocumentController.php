<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpaceOwnerVerification;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Uploading the proof file behind a listing — the deed or the lease.
 *
 * This is deliberately separate from creating the space: the owner form needs the
 * new space's id before it can upload anything, so the document is a second call
 * made straight after the create/edit succeeds. The admin approval screen reads
 * whatever lands here.
 */
class OwnerSpaceDocumentController extends Controller
{
    use OwnerAuthorization;

    /**
     * 10MB, per the contract. The owner's own proof-of-identity upload is capped
     * at 5MB elsewhere; a lease contract is a bigger document than an ID.
     */
    private const MAX_KILOBYTES = 10240;

    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function store(Request $request, int $space)
    {
        $this->ensureOwnerRole();

        $workspace = $this->ensureOwnsWorkspace($space);

        $request->validate([
            // `proof` is the field name the owner's form uses, matching the slot
            // id on the model side. The aliases keep older builds that sent
            // `documents[]` or a bare `file` working.
            'proof' => ['required_without_all:documents,file', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:' . self::MAX_KILOBYTES],
            'documents' => ['required_without_all:proof,file', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:' . self::MAX_KILOBYTES],
            'file' => ['required_without_all:proof,documents', 'nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:' . self::MAX_KILOBYTES],
        ]);

        $upload = $request->file('proof') ?? $request->file('documents') ?? $request->file('file');

        if (! $upload || ! $upload->isValid()) {
            return response()->json([
                'message' => 'تعذّر رفع المستند. يرجى إعادة رفع الملف.',
                'errors' => ['proof' => ['لم يتم إرسال أي ملف صالح.']],
            ], 422);
        }

        $path = $this->storeProof($upload, $workspace->id);

        // The verification row is the record the admin screen reads, and the only
        // place a per-listing document can carry its own review state.
        $verification = SpaceOwnerVerification::updateOrCreate(
            [
                'user_id' => $workspace->owner_id,
                'workspace_id' => $workspace->id,
            ],
            [
                'proof_document_url' => $path,
                // A fresh upload always goes back for review, even if a previous
                // one was already approved: the admin approved *that* file.
                'status' => 'pending',
                'verified_at' => null,
            ]
        );

        // Mirrored onto the listing so the older readers of this column keep
        // working, and so the admin detail screen always has a path to show.
        $workspace->forceFill(['space_document_url' => $path])->save();

        return response()->json([
            'message' => 'تم رفع المستند.',
            'documents' => [
                [
                    'kind' => 'proof',
                    'name' => $upload->getClientOriginalName(),
                    'url' => $this->publicUrl($path),
                    'path' => $path,
                    'status' => $verification->status,
                    'uploaded_at' => optional($verification->created_at)->toIso8601String(),
                ],
            ],
        ], 201);
    }

    /**
     * Writes the file to the cloudinary disk under space_documents/{space_id}/ and
     * returns the disk-relative path.
     *
     * The extension comes from the *detected* mime type rather than the
     * client-supplied name, so a name like "deed.php" cannot choose the stored
     * extension. Only the path is persisted; the absolute URL is derived from it
     * on read, so the cloud name can change without invalidating stored rows.
     */
    private function storeProof($upload, int $spaceId): string
    {
        $extension = self::MIME_EXTENSIONS[$upload->getMimeType()] ?? 'pdf';

        $path = $upload->storeAs(
            'space_documents/' . $spaceId,
            'proof_' . time() . '.' . $extension,
            'cloudinary'
        );

        if (! $path) {
            // Surfaced as a 500 by the handler; storage being unreachable is a
            // server fault, not something the owner can fix by retrying.
            throw new \RuntimeException('تعذّر حفظ المستند على الخادم.');
        }

        return $path;
    }

    /**
     * The publicly reachable URL for a stored path, used by the admin's
     * "فتح المستند" button. Already absolute, because the cloudinary adapter
     * returns the asset's secure_url.
     */
    private function publicUrl(string $path): string
    {
        return Storage::disk('cloudinary')->url($path);
    }

    /**
     * The documents already attached to a listing, for the owner's own view.
     */
    public function index(Request $request, int $space)
    {
        $this->ensureOwnerRole();

        $workspace = $this->ensureOwnsWorkspace($space);

        $documents = SpaceOwnerVerification::where('workspace_id', $workspace->id)
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->get()
            ->filter(fn (SpaceOwnerVerification $verification) => filled($verification->proof_document_url))
            ->map(fn (SpaceOwnerVerification $verification) => [
                'kind' => 'proof',
                'name' => $verification->proof_document_url,
                'url' => $this->publicUrl($verification->proof_document_url),
                'path' => $verification->proof_document_url,
                'status' => $verification->status,
                'uploaded_at' => optional($verification->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'message' => 'تم جلب مستندات المساحة بنجاح.',
            'documents' => $documents,
        ]);
    }
}