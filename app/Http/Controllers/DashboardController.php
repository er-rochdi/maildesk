<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private function filters(Request $request): array
    {
        abort_unless($request->user()->hasPermission('view_dashboard') && $request->user()->company_id !== null, 403);
        $companyId = $request->user()->company_id;
        $accessibleIds = $request->user()->role === 'admin' ? null : $request->user()->mailboxes()->pluck('mailboxes.id');
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['all', '7', '30', '90'])],
            'mailbox_id' => ['nullable', 'integer', Rule::exists('mailboxes', 'id')->where('company_id', $companyId)->where(fn ($query) => $query->when($accessibleIds !== null, fn ($query) => $query->whereIn('id', $accessibleIds)))],
        ]);
        $period = $data['period'] ?? 'all';

        return [$companyId, $data['mailbox_id'] ?? null, $period === 'all' ? null : now()->startOfDay()->subDays((int) $period - 1), $period, $accessibleIds];
    }

    private function messages($companyId, $mailboxId, $accessibleIds)
    {
        return Message::whereHas('mailbox', fn ($query) => $query->where('company_id', $companyId))
            ->when($accessibleIds !== null, fn ($query) => $query->whereIn('mailbox_id', $accessibleIds))
            ->when($mailboxId, fn ($query) => $query->where('mailbox_id', $mailboxId));
    }

    private function dated($query, $column, ?Carbon $since)
    {
        return $query->when($since, fn ($query) => $query->where($column, '>=', $since)->where($column, '<=', now()->endOfDay()));
    }

    public function show(Request $request)
    {
        [$companyId, $mailboxId, $since, $period, $accessibleIds] = $this->filters($request);
        $base = $this->messages($companyId, $mailboxId, $accessibleIds);
        $outbound = $this->dated((clone $base)->where('direction', 'outbound'), DB::raw('COALESCE(sent_at, message_at)'), $since);
        $sent = (clone $outbound)->where('status', 'sent');
        $received = $this->dated((clone $base)->where('direction', 'inbound'), 'message_at', $since);
        $problems = fn ($query) => $query->whereIn('status', ['failed', 'uncertain'])->orWhereNotNull('delivery_error')->orWhere('delivery_status', 'failed');

        $reads = DB::table('message_reads')->join('messages', 'messages.id', '=', 'message_reads.message_id')
            ->join('mailboxes', 'mailboxes.id', '=', 'messages.mailbox_id')->where('mailboxes.company_id', $companyId)
            ->whereColumn('message_reads.user_id', 'users.id')->when($mailboxId, fn ($query) => $query->where('mailboxes.id', $mailboxId))
            ->when($accessibleIds !== null, fn ($query) => $query->whereIn('mailboxes.id', $accessibleIds));
        $reads = $this->dated($reads, DB::raw('COALESCE(messages.sent_at, messages.message_at)'), $since)->selectRaw('COUNT(*)');
        $team = User::where('company_id', $companyId)->select(['id', 'name', 'email', 'role'])->addSelect([
            'sent_count' => (clone $sent)->whereColumn('messages.sender_user_id', 'users.id')->selectRaw('COUNT(*)'),
            'read_count' => $reads,
        ])->orderBy('name')->get();
        $team->each(function ($member) use ($request) {
            if (! $request->user()->hasPermission('view_send_activity')) {
                $member->makeHidden('sent_count');
            }
            if (! $request->user()->hasPermission('view_read_activity')) {
                $member->makeHidden('read_count');
            }
        });

        $days = $period === 'all' ? 14 : (int) $period;
        $start = now()->startOfDay()->subDays($days - 1);
        $inboundDays = $this->dated((clone $base)->where('direction', 'inbound'), 'message_at', $start)
            ->selectRaw('DATE(message_at) as day, COUNT(*) as total')->groupByRaw('DATE(message_at)')->pluck('total', 'day');
        $outboundDays = $this->dated((clone $base)->where('direction', 'outbound')->where('status', 'sent'), DB::raw('COALESCE(sent_at, message_at)'), $start)
            ->selectRaw('DATE(COALESCE(sent_at, message_at)) as day, COUNT(*) as total')->groupByRaw('DATE(COALESCE(sent_at, message_at))')->pluck('total', 'day');
        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $trend[] = ['day' => $day, 'sent' => (int) ($outboundDays[$day] ?? 0), 'received' => (int) ($inboundDays[$day] ?? 0)];
        }

        return response()->json([
            'stats' => [
                'sent' => (clone $sent)->count(), 'received' => (clone $received)->count(),
                'unopened' => (clone $received)->whereDoesntHave('readers')->count(),
                'issues' => (clone $outbound)->where($problems)->count(),
                'delivered' => (clone $sent)->where('delivery_status', 'delivered')->whereNull('delivery_error')->count(),
                'pending' => (clone $outbound)->whereIn('status', ['queued', 'sending'])->count(),
                'mailboxes' => Mailbox::where('company_id', $companyId)->when($mailboxId, fn ($query) => $query->whereKey($mailboxId))->when($accessibleIds !== null, fn ($query) => $query->whereIn('id', $accessibleIds))->count(),
                'users' => $team->count(),
            ],
            'trend' => $trend, 'team' => $request->user()->hasPermission('view_send_activity') || $request->user()->hasPermission('view_read_activity') ? $team : [], 'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function activity(Request $request)
    {
        [$companyId, $mailboxId, $since, $period, $accessibleIds] = $this->filters($request);
        $data = $request->validate([
            'q' => 'nullable|string|max:150', 'kind' => ['nullable', Rule::in(['all', 'sent', 'received', 'unread', 'issues', 'pending'])],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
        ]);
        abort_if(! empty($data['user_id']) && ! $request->user()->hasPermission('view_send_activity') && ! $request->user()->hasPermission('view_read_activity'), 403);
        $query = $this->dated($this->messages($companyId, $mailboxId, $accessibleIds), DB::raw('COALESCE(sent_at, message_at)'), $since);
        if (($data['kind'] ?? 'all') === 'sent') {
            $query->where('direction', 'outbound')->where('status', 'sent');
        } elseif (($data['kind'] ?? null) === 'pending') {
            $query->where('direction', 'outbound')->whereIn('status', ['queued', 'sending']);
        } elseif (($data['kind'] ?? null) === 'issues') {
            $query->where('direction', 'outbound')->where(fn ($query) => $query->whereIn('status', ['failed', 'uncertain'])->orWhereNotNull('delivery_error')->orWhere('delivery_status', 'failed'));
        } elseif (in_array($data['kind'] ?? null, ['received', 'unread'], true)) {
            $query->where('direction', 'inbound');
            if ($data['kind'] === 'unread') {
                $query->whereDoesntHave('readers');
            }
        }
        $query->when($data['user_id'] ?? null, fn ($query, $id) => $query->where(fn ($query) => $query
            ->when($request->user()->hasPermission('view_send_activity'), fn ($query) => $query->where('sender_user_id', $id))
            ->when($request->user()->hasPermission('view_read_activity'), fn ($query) => $query->orWhereHas('readers', fn ($query) => $query->where('users.id', $id)))))
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('subject', 'like', '%'.$data['q'].'%')->orWhere('from_address', 'like', '%'.$data['q'].'%')->orWhere('to', 'like', '%'.$data['q'].'%')));

        return $query->select(['id', 'mailbox_id', 'direction', 'status', 'from_address', 'to', 'subject', 'message_at', 'created_at', 'sent_at', 'sender_user_id', 'delivery_status', 'delivery_error', 'send_error'])
            ->with(['mailbox:id,name,email', 'sender', 'readers' => fn ($query) => $query->where('users.company_id', $companyId)])
            ->orderByDesc(DB::raw('COALESCE(sent_at, message_at)'))->orderByDesc('id')->paginate(20)
            ->through(fn ($message) => $message->visibleActivityFor($request->user()));
    }
}
