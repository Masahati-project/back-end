<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Traits\OwnerAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OwnerDocumentController extends Controller
{
    use OwnerAuthorization;


    public function index()
    {
        $this->ensureOwnerRole();

        $document = Document::where('user_id', Auth::id())->first();

        if (!$document) {
            $document = Document::create([
                'user_id' => Auth::id(),
                'status' => 'none',
            ]);
        }

        return response()->json($this->formatDocumentResponse($document));
    }

    public function store(Request $request)
    {
        $this->ensureOwnerRole();

        $document = Document::firstOrCreate(
            ['user_id' => Auth::id()],
            ['status' => 'none']
        );

        // Get all files from the request
        $files = $request->files->all();

        if (empty($files)) {
            return response()->json(['message' => 'No files provided'], 400);
        }

        // Delete old files if status is rejected
        if ($document->status === 'rejected') {
            $document->files()->delete();
        }

        // Process each uploaded file (field name = slot id)
        foreach ($files as $slotId => $uploadedFile) {
            if (!is_array($uploadedFile)) {
                $uploadedFile = [$uploadedFile];
            }

            foreach ($uploadedFile as $file) {
                if (!$file->isValid()) {
                    continue;
                }

                // Validate file type - only PDF and common document formats
                $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
                if (!in_array($file->getMimeType(), $allowedMimes)) {
                    continue;
                }

                // Validate file size - max 5MB
                $maxSize = 5 * 1024 * 1024;
                if ($file->getSize() > $maxSize) {
                    continue;
                }

                // Store the file on Cloudinary
                $filename = 'documents/' . uniqid() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('documents', uniqid() . '_' . $file->getClientOriginalName(), 'cloudinary');

                // Create document file record
                DocumentFile::create([
                    'document_id' => $document->id,
                    'slot_id' => $slotId,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        // Update document status to pending
        $document->update([
            'status' => 'pending',
            'submitted_at' => now(),
            // A prior decision was about the file that was replaced, not this
            // one, so the review note is cleared rather than left describing a
            // document the owner no longer has on file.
            'note' => null,
            'reviewed_at' => null,
        ]);

        // The account is deliberately left alone. It used to drop back to
        // `pending` here on the grounds that the owner and the document must
        // never disagree about being cleared — but the owner no longer needs
        // clearing to list a space, so that only took a working owner out of
        // their own dashboard every time they re-uploaded a document.

        return response()->json($this->formatDocumentResponse($document), 201);
    }

    private function formatDocumentResponse(Document $document)
    {
        $files = [];

        foreach ($document->files as $file) {
            if (!isset($files[$file->slot_id])) {
                $files[$file->slot_id] = [];
            }

            $files[$file->slot_id][] = [
                'id' => $file->id,
                'name' => $file->name,
                'size' => $file->size,
                'type' => $file->mime_type,
                'path' => $file->path,
            ];
        }

        // If multiple files in a slot, keep them as array; if one, extract it
        $filesBySlot = [];
        foreach ($files as $slotId => $slotFiles) {
            $filesBySlot[$slotId] = count($slotFiles) === 1 ? $slotFiles[0] : $slotFiles;
        }

        return [
            'status' => $document->status,
            // Cast so an owner with no files gets {} rather than [], which would
            // break a client reading files.assets / files.cert.
            'files' => (object) $filesBySlot,
            'note' => $document->note,
            // The admin writes the decision's reason into the same column the review
            // screen reads it from, so this is the owner's feedback. Only an
            // admin decision ever writes `note` — a submission clears it — so the
            // column alone is the signal. It used to be gated on `reviewed_at`
            // as well, which meant any decision that did not stamp that column
            // showed the owner no reason at all: a rejected document with a note
            // rendered a blank rejection.
            'review_note' => $document->note,
            // Deliberately not mirrored. `admin_note` is internal working space
            // for the review screen; both fields used to carry the identical
            // expression, so the owner's payload leaked it under a second name.
            'admin_note' => null,
            'submitted_at' => $document->submitted_at?->toIso8601String(),
            'submittedAt' => $document->submitted_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewedAt' => $document->reviewed_at?->toIso8601String(),
        ];
    }
}
