<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageTeamController extends Controller
{
    private function authorizeAccess(Request $request, Message $message): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('read_emails'), 403);
        abort_unless($user->company_id === $message->mailbox->company_id && ($user->role === 'admin' || $message->mailbox->users()->whereKey($user->id)->exists()), 404);
    }

    public function show(Request $request, Message $message)
    {
        $this->authorizeAccess($request, $message);
        $mailbox = $message->mailbox;
        $members = User::where('company_id', $mailbox->company_id)->where('is_active', true)
            ->where(fn ($query) => $query->where('role', 'admin')->orWhereHas('mailboxes', fn ($query) => $query->where('mailboxes.id', $mailbox->id)))
            ->select('id', 'name', 'role', 'permissions', 'is_active')->orderBy('name')->get()
            ->filter(fn (User $user) => $user->hasPermission('read_emails'))->map->only(['id', 'name'])->values();

        return response()->json(['status' => $message->work_status, 'assigned_user_id' => $message->assigned_user_id,
            'members' => $members, 'notes' => $message->notes()->with('author:id,name')->orderBy('id')->get()]);
    }

    public function update(Request $request, Message $message)
    {
        $this->authorizeAccess($request, $message);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'done'])], 'assigned_user_id' => ['nullable', 'integer']]);
        if (! empty($data['assigned_user_id'])) {
            $mailbox = $message->mailbox;
            $assigned = User::whereKey($data['assigned_user_id'])->where('company_id', $mailbox->company_id)->where('is_active', true)
                ->where(fn ($query) => $query->where('role', 'admin')->orWhereHas('mailboxes', fn ($query) => $query->where('mailboxes.id', $mailbox->id)))->first();
            abort_unless($assigned && $assigned->hasPermission('read_emails'), 422, 'Cet utilisateur ne dispose pas de cet accès.');
        }
        $message->update(['work_status' => $data['status'], 'assigned_user_id' => $data['assigned_user_id'] ?? null]);

        return $this->show($request, $message);
    }

    public function note(Request $request, Message $message)
    {
        $this->authorizeAccess($request, $message);
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $note = $message->notes()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json($note->load('author:id,name'), 201);
    }
}
