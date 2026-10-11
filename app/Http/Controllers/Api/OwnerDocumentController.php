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

        // A re-submission returns the owner to review too. Leaving the user row
        // at `active` while the document sits in `pending` would make the two
        // disagree about whether the owner is cleared, and the gate trusts both.
        $user = Auth::user();
        if ($user->status === 'active' && $user->role === 'space_owner') {
            $user->forceFill(['status' => 'pending'])->save();
        }

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
            // The admin writes the decision's reason into the same column the
            // review screen reads it from, so this is the owner's feedback and
            // is null until a decision has actually been made.
            'review_note' => $document->reviewed_at ? $document->note : null,
            'admin_note' => $document->reviewed_at ? $document->note : null,
            'submitted_at' => $document->submitted_at?->toIso8601String(),
            'submittedAt' => $document->submitted_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewedAt' => $document->reviewed_at?->toIso8601String(),
        ];
    }
}
