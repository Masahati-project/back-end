<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\BroadcastDraft;
use App\Models\BroadcastRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminBroadcastsController extends Controller
{
    /**
     * List broadcasts
     * GET /api/admin/broadcasts
     */
    public function index(Request $request)
    {
        $query = Broadcast::with('sentBy');

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($q2) use ($q) {
                $q2->where('title', 'like', "%$q%")
                    ->orWhere('body', 'like', "%$q%");
            });
        }

        if ($request->filled('target')) {
            $query->where('target', $request->target);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $broadcasts = $query->orderBy('sent_at', 'desc')->paginate($perPage);

        $data = $broadcasts->getCollection()->map(function ($b) {
            $sentByName = $b->sentBy ? $b->sentBy->full_name : null;
            $recipients = $b->recipients_count ?? $b->total ?? 0;

            return [
                'id' => $b->id,
                'title' => $b->title,
                'body' => $b->body,
                'target' => $b->target,
                'audience' => $b->target,
                'link' => $b->link ?? '',
                'channels' => $b->channels,
                'recipients_count' => $recipients,
                'recipients' => $recipients,
                'total' => $recipients,
                'opened_count' => $b->opened ?? 0,
                'opened' => $b->opened ?? 0,
                'sentAt' => $b->sent_at ? $b->sent_at->format('Y-m-d H:i') : null,
                'sent_at' => $b->sent_at ? $b->sent_at->toIso8601String() : null,
                'created_at' => $b->sent_at ? $b->sent_at->toIso8601String() : null,
                'sentBy' => 'admin',
                'sent_by' => 'admin',
                'sent_by_name' => $sentByName,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $broadcasts->currentPage(),
                'last_page' => $broadcasts->lastPage(),
                'total' => $broadcasts->total(),
                'per_page' => $broadcasts->perPage(),
                'page' => $broadcasts->currentPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $b = Broadcast::with('sentBy')->findOrFail($id);

        $sentByName = $b->sentBy ? $b->sentBy->full_name : null;
        $recipients = $b->recipients_count ?? $b->total ?? 0;

        return response()->json([
            'data' => [
                'id' => $b->id,
                'title' => $b->title,
                'body' => $b->body,
                'target' => $b->target,
                'link' => $b->link ?? '',
                'channels' => $b->channels ?? [],
                'recipients_count' => $recipients,
                'in_app_created_count' => $b->in_app_created_count ?? 0,
                'email_queued_count' => $b->email_queued_count ?? 0,
                'email_sent_count' => $b->email_sent_count ?? 0,
                'email_failed_count' => $b->email_failed_count ?? 0,
                'email_skipped_no_address_count' => $b->email_skipped_no_address_count ?? 0,
                'opened_count' => $b->opened ?? 0,
                'sent_at' => $b->sent_at ? $b->sent_at->toIso8601String() : null,
                'sent_by' => 'admin',
                'sent_by_name' => $sentByName,
            ],
        ]);
    }

    public function recipients(Request $request, $id)
    {
        $broadcast = Broadcast::findOrFail($id);
        $perPage = min($request->integer('per_page', 50), 100);
        $channelFilter = $request->input('channel');
        $statusFilter = $request->input('status');

        $base = BroadcastRecipient::where('broadcast_id', $broadcast->id);

        if ($channelFilter) {
            $base->where('channel', $channelFilter);
        }
        if ($statusFilter) {
            $base->where('status', $statusFilter);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $base->whereHas('user', function ($q2) use ($q) {
                $q2->where('full_name', 'like', "%$q%")
                    ->orWhere('email', 'like', "%$q%");
            });
        }

        $users = (clone $base)
            ->select('user_id')
            ->distinct()
            ->paginate($perPage);

        $rows = BroadcastRecipient::with('user')
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('user_id', $users->getCollection()->pluck('user_id'))
            ->get()
            ->groupBy('user_id');

        $data = $users->getCollection()->map(function ($entry) use ($rows) {
            $userRows = $rows->get($entry->user_id, collect());
            $user = optional($userRows->first())->user;

            $email = null;
            if ($user && $user->email && str_contains($user->email, '@')) {
                $parts = explode('@', $user->email);
                $email = substr($parts[0], 0, 1).'***@'.$parts[1];
            }

            $result = [
                'user_id' => $entry->user_id,
                'name' => $user ? $user->full_name : null,
                'email' => $email,
            ];

            $inApp = $userRows->firstWhere('channel', 'in_app');
            $emailRow = $userRows->firstWhere('channel', 'email');

            if ($inApp) {
                $result['in_app'] = [
                    'status' => $inApp->status,
                    'notification_id' => $inApp->notification_id,
                ];
            }

            if ($emailRow) {
                $result['email_status'] = $emailRow->status;
                $result['email_delivery'] = [
                    'status' => $emailRow->status,
                    'attempts' => $emailRow->attempts ?? 1,
                    'error' => $emailRow->error,
                ];
            }

            return $result;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
            ],
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
            ->count();

        $owners = User::where('role', 'space_owner')
            ->where('status', 'active')
            ->count();

        $customers = User::where('role', 'customer')
            ->where('status', 'active')
            ->count();

        return response()->json([
            'data' => [
                'all' => $all,
                'owners' => $owners,
                'customers' => $customers,
                'freelancers' => 0,
            ],
        ]);
    }

    /**
     * Send broadcast
     * POST /api/admin/broadcasts
     */
    public function send(Request $request)
    {
        $request->validate([
            'title' => 'required|string|min:1|max:255',
            'body' => 'required|string|min:1|max:5000',
            'target' => 'required|in:all,owners,customers,freelancers',
            'link' => ['nullable', 'string', 'max:300', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                if (preg_match('~^https?://~i', $value)) {
                    return;
                }
                if (str_starts_with($value, '/')) {
                    return;
                }
                $fail('The link must be an absolute http(s):// URL, or an internal path like /spaces.');
            }],
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:in_app,email',
        ]);

        $user = $request->user();

        $query = User::where('status', 'active');

        $target = $request->target === 'freelancers' ? 'customers' : $request->target;

        if ($target === 'owners') {
            $query->where('role', 'space_owner');
        } elseif ($target === 'customers') {
            $query->where('role', 'customer');
        } else {
            $query->whereIn('role', ['customer', 'space_owner']);
        }

        $recipients = $query->get();
        $total = $recipients->count();

        if ($total === 0) {
            return response()->json([
                'message' => 'لا يوجد مستلمون لهذا الجمهور',
                'errors' => ['target' => ['لا يوجد مستلمون لهذا الجمهور']],
            ], 422);
        }

        $channels = $request->channels;
        $broadcast = Broadcast::create([
            'title' => trim($request->title),
            'body' => trim($request->body),
            'target' => $request->target,
            'link' => $request->link === '' ? null : $request->link,
            'channels' => $channels,
            'total' => $total,
            'recipients_count' => $total,
            'opened' => 0,
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        $inAppCreated = 0;
        if (in_array('in_app', $channels)) {
            foreach ($recipients as $recipient) {
                $notification = Notification::create([
                    'user_id' => $recipient->id,
                    'type' => 'broadcast',
                    'title' => $broadcast->title,
                    'body' => $broadcast->body,
                    'message' => $broadcast->body,
                    'link' => $broadcast->link,
                    'is_read' => false,
                    'read' => false,
                    'broadcast_id' => $broadcast->id,
                ]);
                $inAppCreated++;
                BroadcastRecipient::create([
                    'broadcast_id' => $broadcast->id,
                    'user_id' => $recipient->id,
                    'channel' => 'in_app',
                    'status' => 'created',
                    'attempts' => 1,
                    'notification_id' => $notification->id,
                ]);
            }
        }

        $emailQueued = 0;
        $emailFailed = 0;
        $emailSkipped = 0;
        if (in_array('email', $channels)) {
            foreach ($recipients as $recipient) {
                if (empty($recipient->email)) {
                    $emailSkipped++;
                    BroadcastRecipient::create([
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $recipient->id,
                        'channel' => 'email',
                        'status' => 'skipped',
                        'attempts' => 0,
                        'error' => 'No email address',
                    ]);

                    continue;
                }
                try {
                    Mail::to($recipient->email)->queue(new BroadcastMail($broadcast->title, $broadcast->body, $broadcast->link));
                    $emailQueued++;
                    BroadcastRecipient::create([
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $recipient->id,
                        'channel' => 'email',
                        'status' => 'sent',
                        'attempts' => 1,
                    ]);
                } catch (\Throwable $e) {
                    $emailFailed++;
                    BroadcastRecipient::create([
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $recipient->id,
                        'channel' => 'email',
                        'status' => 'failed',
                        'attempts' => 1,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $broadcast->update([
            'in_app_created_count' => $inAppCreated,
            'email_queued_count' => $emailQueued,
            'email_sent_count' => $emailQueued,
            'email_failed_count' => $emailFailed,
            'email_skipped_no_address_count' => $emailSkipped,
        ]);

        return response()->json([
            'message' => 'تم إرسال الإشعار.',
            'data' => [
                'id' => $broadcast->id,
                'title' => $broadcast->title,
                'body' => $broadcast->body,
                'target' => $broadcast->target,
                'link' => $broadcast->link,
                'channels' => $broadcast->channels,
                'recipients_count' => $total,
                'in_app_created_count' => $inAppCreated,
                'email_queued_count' => $emailQueued,
                'email_failed_count' => $emailFailed,
                'email_skipped_no_address_count' => $emailSkipped,
                'sent_at' => $broadcast->sent_at->toIso8601String(),
                'sent_by' => 'admin',
            ],
        ], 201);
    }

    /**
     * Resend broadcast
     * POST /api/admin/broadcasts/{id}/resend
     */
    public function resend(Request $request, $id)
    {
        $broadcast = Broadcast::findOrFail($id);
        $channels = $request->filled('channels') ? $request->channels : ($broadcast->channels ?? []);

        $query = User::where('status', 'active');
        $target = $broadcast->target === 'freelancers' ? 'customers' : $broadcast->target;
        if ($target === 'owners') {
            $query->where('role', 'space_owner');
        } elseif ($target === 'customers') {
            $query->where('role', 'customer');
        } else {
            $query->whereIn('role', ['customer', 'space_owner']);
        }

        $recipients = $query->get();
        $total = $recipients->count();

        if ($total === 0) {
            return response()->json([
                'message' => 'لا يوجد مستلمون لهذا الجمهور',
                'errors' => ['target' => ['لا يوجد مستلمون لهذا الجمهور']],
            ], 422);
        }

        $inAppCreated = $broadcast->in_app_created_count ?? 0;
        if (is_array($channels) && in_array('in_app', $channels)) {
            foreach ($recipients as $recipient) {
                if (! Notification::where('user_id', $recipient->id)->where('broadcast_id', $broadcast->id)->exists()) {
                    $notification = Notification::create([
                        'user_id' => $recipient->id,
                        'type' => 'broadcast',
                        'title' => $broadcast->title,
                        'body' => $broadcast->body,
                        'message' => $broadcast->body,
                        'link' => $broadcast->link,
                        'is_read' => false,
                        'read' => false,
                        'broadcast_id' => $broadcast->id,
                    ]);
                    BroadcastRecipient::firstOrCreate(
                        ['broadcast_id' => $broadcast->id, 'user_id' => $recipient->id, 'channel' => 'in_app'],
                        ['status' => 'created', 'attempts' => 1, 'notification_id' => $notification->id]
                    );
                    $inAppCreated++;
                }
            }
        }

        $emailQueued = $broadcast->email_queued_count ?? 0;
        $emailFailed = $broadcast->email_failed_count ?? 0;
        if (is_array($channels) && in_array('email', $channels)) {
            foreach ($recipients as $recipient) {
                if (empty($recipient->email)) {
                    continue;
                }
                $existing = BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('user_id', $recipient->id)->where('channel', 'email')->first();
                if ($existing && $existing->status === 'sent') {
                    continue;
                }
                try {
                    Mail::to($recipient->email)->queue(new BroadcastMail($broadcast->title, $broadcast->body, $broadcast->link));
                    if ($existing) {
                        $existing->update(['status' => 'sent', 'attempts' => ($existing->attempts ?? 0) + 1]);
                    } else {
                        BroadcastRecipient::create([
                            'broadcast_id' => $broadcast->id,
                            'user_id' => $recipient->id,
                            'channel' => 'email',
                            'status' => 'sent',
                            'attempts' => 1,
                        ]);
                    }
                    $emailQueued++;
                } catch (\Throwable $e) {
                    if ($existing) {
                        $existing->update(['status' => 'failed', 'attempts' => ($existing->attempts ?? 0) + 1, 'error' => $e->getMessage()]);
                    } else {
                        BroadcastRecipient::create([
                            'broadcast_id' => $broadcast->id,
                            'user_id' => $recipient->id,
                            'channel' => 'email',
                            'status' => 'failed',
                            'attempts' => 1,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $emailFailed++;
                }
            }
        }

        $broadcast->update([
            'channels' => $channels,
            'recipients_count' => $total,
            'in_app_created_count' => BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'in_app')->count(),
            'email_queued_count' => BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'email')->where('status', 'sent')->count(),
            'email_sent_count' => BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'email')->where('status', 'sent')->count(),
            'email_failed_count' => BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'email')->where('status', 'failed')->count(),
            'email_skipped_no_address_count' => BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'email')->where('status', 'skipped')->count(),
            'sent_at' => now(),
        ]);

        $broadcast->refresh();

        return response()->json([
            'message' => 'تم إرسال الإشعار.',
            'data' => [
                'id' => $broadcast->id,
                'title' => $broadcast->title,
                'body' => $broadcast->body,
                'target' => $broadcast->target,
                'link' => $broadcast->link,
                'channels' => $broadcast->channels,
                'recipients_count' => $broadcast->recipients_count,
                'in_app_created_count' => $broadcast->in_app_created_count,
                'email_queued_count' => $broadcast->email_queued_count,
                'email_failed_count' => $broadcast->email_failed_count,
                'sent_at' => $broadcast->sent_at ? $broadcast->sent_at->toIso8601String() : null,
                'sent_by' => 'admin',
            ],
        ]);
    }

    public function testSend(Request $request)
    {
        $request->validate([
            'title' => 'required|string|min:1|max:255',
            'body' => 'required|string|min:1|max:5000',
            'link' => ['nullable', 'string', 'max:300', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                if (preg_match('~^https?://~i', $value)) {
                    return;
                }
                if (str_starts_with($value, '/')) {
                    return;
                }
                $fail('The link must be an absolute http(s):// URL, or an internal path like /spaces.');
            }],
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:in_app,email',
        ]);

        $user = $request->user();
        $channels = $request->channels;
        $emailStatus = 'queued';
        $inApp = false;

        if (in_array('in_app', $channels)) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'broadcast',
                'title' => trim($request->title),
                'body' => trim($request->body),
                'message' => trim($request->body),
                'link' => $request->link === '' ? null : $request->link,
                'is_read' => false,
                'read' => false,
            ]);
            $inApp = true;
        }

        if (in_array('email', $channels)) {
            try {
                if (! empty($user->email)) {
                    Mail::to($user->email)->queue(new BroadcastMail(trim($request->title), trim($request->body), $request->link === '' ? null : $request->link));
                    $emailStatus = 'queued';
                } else {
                    $emailStatus = 'skipped';
                }
            } catch (\Throwable $e) {
                $emailStatus = 'failed';
            }
        }

        return response()->json([
            'message' => 'تم إرسال الرسالة التجريبية.',
            'data' => [
                'email' => $user->email,
                'in_app' => $inApp,
                'email_status' => $emailStatus,
                'job_id' => null,
            ],
        ], 200);
    }

    public function retryFailed(Request $request, $id)
    {
        $broadcast = Broadcast::findOrFail($id);
        $retried = 0;

        $failedRecipients = BroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->where('channel', 'email')
            ->where('status', 'failed')
            ->with('user')
            ->get();

        foreach ($failedRecipients as $failedRecipient) {
            $recipient = $failedRecipient->user;
            if (! $recipient || empty($recipient->email)) {
                continue;
            }
            try {
                Mail::to($recipient->email)->queue(new BroadcastMail($broadcast->title, $broadcast->body, $broadcast->link));
                $failedRecipient->update([
                    'status' => 'sent',
                    'attempts' => ($failedRecipient->attempts ?? 1) + 1,
                    'error' => null,
                ]);
                $retried++;
            } catch (\Throwable $e) {
                $failedRecipient->update([
                    'attempts' => ($failedRecipient->attempts ?? 1) + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $failedCount = BroadcastRecipient::where('broadcast_id', $broadcast->id)->where('channel', 'email')->where('status', 'failed')->count();

        $broadcast->update([
            'email_queued_count' => ($broadcast->email_queued_count ?? 0) + $retried,
            'email_sent_count' => ($broadcast->email_sent_count ?? 0) + $retried,
            'email_failed_count' => $failedCount,
        ]);

        $broadcast->refresh();

        return response()->json([
            'message' => "أُعيد جدولة {$retried} رسالة فاشلة.",
            'data' => [
                'retried' => $retried,
                'email_failed_count' => $broadcast->email_failed_count,
            ],
        ], 200);
    }

    /**
     * List drafts
     * GET /api/admin/broadcasts/drafts
     */
    public function drafts(Request $request)
    {
        $drafts = BroadcastDraft::where('created_by', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $drafts->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'body' => $d->body,
                'target' => $d->target,
                'link' => $d->link,
                'channels' => $d->channels,
                'created_at' => $d->created_at->toIso8601String(),
                'updated_at' => $d->updated_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    /**
     * Create draft
     * POST /api/admin/broadcasts/drafts
     */
    public function createDraft(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:5000',
            'target' => 'required|in:all,owners,customers,freelancers',
            'link' => 'nullable|string|max:300',
            'channels' => 'nullable|array',
            'channels.*' => 'in:in_app,email',
        ]);

        if (! $request->title && ! $request->body) {
            return response()->json([
                'message' => 'Either title or body must be provided',
                'errors' => ['title' => ['Either title or body is required']],
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
                'updated_at' => $draft->updated_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update draft
     * PUT /api/admin/broadcasts/drafts/{id}
     */
    public function updateDraft(Request $request, $id)
    {
        $draft = BroadcastDraft::where('created_by', $request->user()->id)->findOrFail($id);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:5000',
            'target' => 'required|in:all,owners,customers,freelancers',
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
                'updated_at' => $draft->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete draft
     * DELETE /api/admin/broadcasts/drafts/{id}
     */
    public function deleteDraft(Request $request, $id)
    {
        BroadcastDraft::where('created_by', $request->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function validateUrl($url)
    {
        if (! preg_match('~^(?:https?://|/)~i', $url)) {
            return response()->json([
                'message' => 'Invalid URL',
                'errors' => ['link' => ['The link must be an internal path or an https URL.']],
            ], 422);
        }
    }
}
