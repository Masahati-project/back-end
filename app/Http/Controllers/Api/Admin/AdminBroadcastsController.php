<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastDraft;
use App\Models\User;
use Illuminate\Http\Request;

class AdminBroadcastsController extends Controller
{
    /**
     * List broadcasts
     * GET /api/admin/broadcasts
     */
    public function index(Request $request)
    {
        $query = Broadcast::with('sentBy');

        if ($request->has('q')) {
            $q = $request->q;
            $query->where(function($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                   ->orWhere('body', 'like', "%$q%");
            });
        }

        if ($request->has('target')) {
            $query->where('target', $request->target);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $broadcasts = $query->orderBy('sent_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $broadcasts->map(fn($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'body' => $b->body,
                'target' => $b->target,
                'link' => $b->link,
                'channels' => $b->channels,
                'sentAt' => $b->sent_at->format('Y-m-d H:i'),
                'sent_at' => $b->sent_at->toIso8601String(),
                'sentBy' => 'admin',
                'opened' => $b->opened,
                'total' => $b->total,
            ])->toArray(),
            'meta' => [
                'page' => $broadcasts->currentPage(),
                'per_page' => $broadcasts->perPage(),
                'total' => $broadcasts->total(),
                'last_page' => $broadcasts->lastPage(),
            ]
        ]);
    }

    /**
     * Get audience counts
     * GET /api/admin/broadcasts/audience-counts
     */
    public function audienceCounts()
    {
        $all = User::whereIn('role', ['customer', 'space_owner'])
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->count();

        $owners = User::where('role', 'space_owner')
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->count();

        $freelancers = User::where('role', 'customer')
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->count();

        return response()->json([
            'data' => [
                'all' => $all,
                'owners' => $owners,
                'freelancers' => $freelancers,
            ]
        ]);
    }

    /**
     * Send broadcast
     * POST /api/admin/broadcasts
     */
    public function send(Request $request)
    {
        $request->validate([
            'title' => 'required|string|min:1|max:120',
            'body' => 'required|string|min:1|max:1000',
            'target' => 'required|in:all,owners,freelancers',
            'link' => 'nullable|string|max:300',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:in_app,email',
        ]);

        if ($request->link) {
            $this->validateUrl($request->link);
        }

        $user = $request->user();

        $query = User::whereIn('role', ['customer', 'space_owner'])
            ->where('status', 'active')
            ->whereNotNull('verified_at');

        if ($request->target === 'owners') {
            $query->where('role', 'space_owner');
        } elseif ($request->target === 'freelancers') {
            $query->where('role', 'customer');
        }

        $total = $query->count();

        $broadcast = Broadcast::create([
            'title' => $request->title,
            'body' => $request->body,
            'target' => $request->target,
            'link' => $request->link,
            'channels' => $request->channels,
            'total' => $total,
            'opened' => 0,
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $broadcast->id,
                'title' => $broadcast->title,
                'target' => $broadcast->target,
                'channels' => $broadcast->channels,
                'total' => $broadcast->total,
                'opened' => 0,
                'sentAt' => $broadcast->sent_at->format('Y-m-d H:i'),
                'sent_at' => $broadcast->sent_at->toIso8601String(),
                'sentBy' => 'admin',
                'message' => 'تم إرسال الإشعار بنجاح.',
            ]
        ], 201);
    }

    /**
     * Resend broadcast
     * POST /api/admin/broadcasts/{id}/resend
     */
    public function resend(Request $request, $id)
    {
        $broadcast = Broadcast::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $broadcast->id,
                'resent' => true,
                'total' => $broadcast->total,
                'message' => 'تمت إعادة الإرسال بنجاح.',
            ]
        ]);
    }

    /**
     * List drafts
     * GET /api/admin/broadcasts/drafts
     */
    public function drafts()
    {
        $drafts = BroadcastDraft::orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $drafts->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'body' => $d->body,
                'target' => $d->target,
                'link' => $d->link,
                'channels' => $d->channels,
                'created_at' => $d->created_at->toIso8601String(),
            ])->toArray()
        ]);
    }

    /**
     * Create draft
     * POST /api/admin/broadcasts/drafts
     */
    public function createDraft(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string|max:120',
            'body' => 'nullable|string|max:1000',
            'target' => 'required|in:all,owners,freelancers',
            'link' => 'nullable|string|max:300',
            'channels' => 'nullable|array',
            'channels.*' => 'in:in_app,email',
        ]);

        if (!$request->title && !$request->body) {
            return response()->json([
                'message' => 'Either title or body must be provided',
                'errors' => ['title' => ['Either title or body is required']]
            ], 422);
        }

        $user = $request->user();

        $draft = BroadcastDraft::create([
            'title' => $request->title,
            'body' => $request->body,
            'target' => $request->target,
            'link' => $request->link,
            'channels' => $request->channels ?? [],
            'created_by' => $user->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'title' => $draft->title,
                'body' => $draft->body,
                'target' => $draft->target,
                'link' => $draft->link,
                'channels' => $draft->channels,
                'created_at' => $draft->created_at->toIso8601String(),
            ]
        ], 201);
    }

    /**
     * Update draft
     * PUT /api/admin/broadcasts/drafts/{id}
     */
    public function updateDraft(Request $request, $id)
    {
        $draft = BroadcastDraft::findOrFail($id);

        $request->validate([
            'title' => 'nullable|string|max:120',
            'body' => 'nullable|string|max:1000',
            'target' => 'required|in:all,owners,freelancers',
            'link' => 'nullable|string|max:300',
            'channels' => 'nullable|array',
            'channels.*' => 'in:in_app,email',
        ]);

        $draft->update([
            'title' => $request->title ?? $draft->title,
            'body' => $request->body ?? $draft->body,
            'target' => $request->target,
            'link' => $request->link ?? $draft->link,
            'channels' => $request->channels ?? $draft->channels,
        ]);

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'title' => $draft->title,
                'body' => $draft->body,
                'target' => $draft->target,
                'link' => $draft->link,
                'channels' => $draft->channels,
                'created_at' => $draft->created_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Delete draft
     * DELETE /api/admin/broadcasts/drafts/{id}
     */
    public function deleteDraft($id)
    {
        BroadcastDraft::findOrFail($id)->delete();
        return response()->noContent();
    }

    private function validateUrl($url)
    {
        if (!preg_match('~^(?:https?://|/)~i', $url)) {
            return response()->json([
                'message' => 'Invalid URL',
                'errors' => ['link' => ['The link must be an internal path or an https URL.']]
            ], 422);
        }
    }
}
