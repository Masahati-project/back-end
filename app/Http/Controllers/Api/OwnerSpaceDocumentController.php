<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpaceOwnerVerification;
use App\Services\SpaceDocumentService;
use App\Services\SpaceDocumentStorage;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function store(Request $request, int $space)
    {
        $this->ensureOwnerRole();

        $workspace = $this->ensureOwnsWorkspace($space);

        $request->validate([
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

        $path = SpaceDocumentStorage::store($upload, 'space_documents/'.$workspace->id);

        // One row per upload, not one row per space: a rejected deed stays in
        // the admin's history and the replacement is reviewed as a new file.
        $verification = SpaceOwnerVerification::create([
            'user_id' => $workspace->owner_id,
            'workspace_id' => $workspace->id,
            'proof_document_url' => $path,
            'status' => 'pending',
        ]);

        // The column stays the pointer to the most recent document for clients
        // that predate the per-space document list.
        $workspace->forceFill(['space_document_url' => $path])->save();

        return response()->json([
            'message' => 'تم رفع المستند.',
            'documents' => [
                [
                    'kind' => 'proof',
                    'name' => $upload->getClientOriginalName(),
                    'url' => SpaceDocumentService::url($path),
                    'path' => $path,
                    'status' => $verification->status,
                    'uploaded_at' => optional($verification->created_at)->toIso8601String(),
                ],
            ],
        ], 201);
    }

    /**
     * The documents already attached to a listing, for the owner's own view.
     *
     * The shared service also includes a listing whose only proof is the legacy
     * `space_document_url` column (spaces created before the document rows).
     */
    public function index(Request $request, int $space)
    {
        $this->ensureOwnerRole();

        $workspace = $this->ensureOwnsWorkspace($space);

        $documents = SpaceDocumentService::listFor($workspace);

        return response()->json([
            'message' => 'تم جلب مستندات المساحة بنجاح.',
            'documents' => $documents,
        ]);
    }
}