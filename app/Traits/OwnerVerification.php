<?php

namespace App\Traits;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The owner-onboarding gate.
 *
 * An owner registers in `pending`, uploads proof of ownership, and an admin
 * approves it. Only then may they list a space.
 *
 * "Approved" deliberately means two things at once — a submitted document and an
 * owner the admin has cleared — because either alone is satisfiable without the
 * admin. The user row is what the admin actually sets to `active` when they
 * approve the documents, so the pair cannot disagree: an approved document with
 * a still-pending owner means the approval was rolled back, and a cleared owner
 * with nothing on file means there is nothing behind the clearance.
 */
trait OwnerVerification
{
    /**
     * The most recently submitted proof document for an owner, if any.
     */
    public function ownerProofDocument(?int $userId): ?Document
    {
        if (! $userId) {
            return null;
        }

        return Document::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether an owner is cleared to list spaces.
     *
     * @return array{approved: bool, document_status: ?string, reason: ?string}
     */
    public function ownerVerificationState(?int $userId): array
    {
        $document = $this->ownerProofDocument($userId);
        $documentStatus = $document?->status;

        // Nothing on file at all — the owner has not started the process.
        if (! $document || $documentStatus === 'none') {
            return [
                'approved' => false,
                'document_status' => $documentStatus,
                'reason' => 'owner_document_missing',
            ];
        }

        if ($documentStatus === 'pending') {
            return [
                'approved' => false,
                'document_status' => $documentStatus,
                'reason' => 'owner_document_pending_review',
            ];
        }

        if ($documentStatus === 'rejected') {
            return [
                'approved' => false,
                'document_status' => $documentStatus,
                'reason' => 'owner_document_rejected',
            ];
        }

        // The documents are approved; the owner's account has to agree.
        if ($this->ownerAccountIsCleared($userId)) {
            return [
                'approved' => true,
                'document_status' => $documentStatus,
                'reason' => null,
            ];
        }

        return [
            'approved' => false,
            'document_status' => $documentStatus,
            'reason' => 'owner_account_pending',
        ];
    }

    /**
     * The admin's decision on the owner themselves. `active` is the cleared
     * state; `pending` and `suspended` are both "not yet", for different reasons.
     */
    private function ownerAccountIsCleared(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return User::where('id', $userId)->where('status', 'active')->exists();
    }

    /**
     * Aborts with 403 unless the owner is cleared to list spaces.
     *
     * The frontend also hides the create button, but that is a convenience: this
     * is the actual rule, and it has to hold for any client.
     */
    public function ensureOwnerVerifiedToListSpaces(): void
    {
        $state = $this->ownerVerificationState(auth()->id());

        if ($state['approved']) {
            return;
        }

        throw new AuthorizationException($this->ownerVerificationMessage($state['reason']), 403);
    }

    /**
     * The owner-facing explanation. Written to say what to do next rather than
     * only what is wrong.
     */
    private function ownerVerificationMessage(string $reason): string
    {
        return match ($reason) {
            'owner_document_missing' =>
                'يجب رفع مستند إثبات الملكية قبل إضافة المساحات.',
            'owner_document_pending_review' =>
                'مستند إثبات الملكية قيد المراجعة من قبل الإدارة.',
            'owner_document_rejected' =>
                'تم رفض مستند إثبات الملكية. يرجى رفع مستند صحيح ثم إعادة المراجعة.',
            default =>
                'حسابك قيد المراجعة. سيتم تفعيل إضافة المساحات بعد توثيق الحساب.',
        };
    }
}