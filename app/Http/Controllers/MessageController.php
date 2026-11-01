<?php

namespace App\Http\Controllers;

use App\Jobs\SendMessageJob;
use App\Jobs\SyncMailboxJob;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    private function authorizeMailbox(Request $request, Mailbox $mailbox): void
    {
        $user = $request->user();
        abort_unless($user->company_id === $mailbox->company_id && ($user->role === 'admin' || $mailbox->users()->whereKey($user->id)->exists()), 404);
    }

    public function index(Request $request, Mailbox $mailbox)
    {
        $this->authorizeMailbox($request, $mailbox);
        $data = $request->validate(['direction' => 'nullable|in:inbound,outbound', 'q' => 'nullable|string|max:150']);

        return Message::where('mailbox_id', $mailbox->id)->where('direction', $data['direction'] ?? 'inbound')
            ->when($data['q'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('subject', 'like', '%'.$search.'%')->orWhere('from_address', 'like', '%'.$search.'%')->orWhere('body', 'like', '%'.$search.'%')))
            ->select(['id', 'mailbox_id', 'direction', 'status', 'from_address', 'from_name', 'to', 'subject', 'message_at', 'sender_user_id', 'send_error', 'archive_error', 'delivery_error', 'delivery_report_id', 'provider', 'delivery_status'])
            ->with('sender')->withCount('attachments')->withExists(['readers as read_by_me' => fn ($q) => $q->where('users.id', $request->user()->id)])
            ->orderByDesc('message_at')->orderByDesc('id')->paginate(30);
    }

    public function show(Request $request, Message $message)
    {
        $this->authorizeMailbox($request, $message->mailbox);

        return $message->load('sender', 'readers', 'attachments');
    }

    public function read(Request $request, Message $message)
    {
        $this->authorizeMailbox($request, $message->mailbox);
        DB::table('message_reads')->insertOrIgnore(['message_id' => $message->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return $message->load('sender', 'readers', 'attachments');
    }

    public function attachment(Request $request, Attachment $attachment)
    {
        $this->authorizeMailbox($request, $attachment->message->mailbox);

        return Storage::disk('local')->download($attachment->path, $attachment->name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function sync(Request $request, Mailbox $mailbox)
    {
        $this->authorizeMailbox($request, $mailbox);
        SyncMailboxJob::dispatch($mailbox->id);

        return response()->json(['message' => 'Synchronisation demandée.'], 202);
    }

    public function store(Request $request, Mailbox $mailbox)
    {
        $this->authorizeMailbox($request, $mailbox);
        $data = $request->validate([
            'request_id' => 'required|uuid', 'to' => 'required|array|min:1|max:30', 'to.*' => 'required|email:rfc|max:255',
            'cc' => 'nullable|array|max:30', 'cc.*' => 'required|email:rfc|max:255', 'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'body' => 'required|string|max:200000', 'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')->where('mailbox_id', $mailbox->id)],
            'attachments' => 'nullable|array|max:10', 'attachments.*' => 'file|max:10240',
            'forward_attachment_ids' => 'nullable|array|max:10', 'forward_attachment_ids.*' => 'integer',
        ]);
        $forwarded = Attachment::whereIn('id', $data['forward_attachment_ids'] ?? [])->whereHas('message', fn ($q) => $q->where('mailbox_id', $mailbox->id))->get();
        abort_unless($forwarded->count() === count(array_unique($data['forward_attachment_ids'] ?? [])), 422, 'Pièce jointe inaccessible.');
        $files = $request->file('attachments', []);
        abort_if($forwarded->contains(fn ($file) => $file->size > 10 * 1024 * 1024), 422, 'Chaque pièce jointe doit faire au maximum 10 Mo.');
        abort_if(count($files) + $forwarded->count() > 10, 422, '10 pièces jointes maximum.');
        abort_if(collect($files)->sum(fn ($f) => $f->getSize()) + $forwarded->sum('size') > 20 * 1024 * 1024, 422, 'Les pièces jointes dépassent 20 Mo.');
        if ($existing = Message::where('mailbox_id', $mailbox->id)->where('request_id', $data['request_id'])->first()) {
            return $this->replay($existing, $request, $data, $files, $forwarded);
        }
        $paths = [];
        try {
            $message = DB::transaction(function () use ($request, $mailbox, $data, $files, $forwarded, &$paths) {
                $message = Message::create([
                    'mailbox_id' => $mailbox->id, 'direction' => 'outbound', 'status' => 'queued', 'request_id' => $data['request_id'],
                    'sender_user_id' => $request->user()->id, 'from_address' => $mailbox->email, 'from_name' => $mailbox->name,
                    'to' => $data['to'], 'cc' => $data['cc'] ?? [], 'subject' => $data['subject'], 'body' => $data['body'],
                    'reply_to_id' => $data['reply_to_id'] ?? null, 'message_at' => now(), 'internet_message_id' => Str::uuid().'@'.Str::after($mailbox->email, '@'),
                ]);
                foreach ($files as $file) {
                    $path = $file->store('mail/'.$mailbox->id, 'local');
                    if (! $path) {
                        throw new \RuntimeException('Enregistrement du fichier impossible.');
                    }
                    $paths[] = $path;
                    $message->attachments()->create(['name' => Attachment::safeName($file->getClientOriginalName()), 'path' => $path, 'size' => $file->getSize()]);
                }
                foreach ($forwarded as $file) {
                    $path = 'mail/'.$mailbox->id.'/'.Str::uuid();
                    if (! Storage::disk('local')->copy($file->path, $path)) {
                        throw new \RuntimeException('Copie du fichier impossible.');
                    }
                    $paths[] = $path;
                    $message->attachments()->create(['name' => $file->name, 'path' => $path, 'size' => $file->size]);
                }

                return $message;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            if ($e instanceof QueryException) {
                $existing = Message::where('mailbox_id', $mailbox->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    return $this->replay($existing, $request, $data, $files, $forwarded);
                }
            }
            throw $e;
        }
        // Queue dispatch happens after the message/files have committed. A queue outage
        // must not delete attachments of a persisted message; the scheduler re-enqueues it.
        try {
            SendMessageJob::dispatch($message->id);
        } catch (\Throwable $e) {
            $message->update(['send_error' => 'File d’envoi indisponible. La mise en file sera retentée automatiquement.']);
            Log::error('Mail queue unavailable', ['message_id' => $message->id, 'exception_class' => get_class($e)]);
        }

        return response()->json($message->load('attachments', 'sender'), 202);
    }

    private function replay(Message $message, Request $request, array $data, array $files, $forwarded)
    {
        abort_unless($message->sender_user_id === $request->user()->id, 409);
        $sameContent = $message->to === $data['to'] && ($message->cc ?? []) === ($data['cc'] ?? [])
            && $message->subject === $data['subject'] && $message->body === $data['body']
            && $message->reply_to_id === (isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null);

        // Compare file contents as well: a replacement can keep the same name and size.
        $signature = fn ($name, $path) => [$name, hash_file('sha256', $path)];
        $incoming = collect($files)->map(fn ($file) => $signature(Attachment::safeName($file->getClientOriginalName()), $file->getRealPath()))
            ->concat($forwarded->map(fn ($file) => $signature($file->name, Storage::disk('local')->path($file->path))))->all();
        $stored = $message->attachments->map(fn ($file) => $signature($file->name, Storage::disk('local')->path($file->path)))->all();
        sort($incoming);
        sort($stored);
        abort_unless($sameContent && $incoming === $stored, 409, 'Cette demande correspond déjà à un autre contenu. Vérifiez le message dans Envoyés avant de créer un nouveau message.');

        return response()->json($message->load('attachments', 'sender'));
    }
}
