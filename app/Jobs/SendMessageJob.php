<?php

namespace App\Jobs;

use App\Contracts\MailGateway;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 360;

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $messageId) {}

    public function handle(MailGateway $gateway): void
    {
        // A duplicate/retried job must never submit the same message to SMTP twice.
        if (! Message::whereKey($this->messageId)->where('status', 'queued')->update(['status' => 'sending', 'send_error' => null])) {
            return;
        }
        $message = Message::findOrFail($this->messageId);
        $actor = $message->sender_user_id ? User::find($message->sender_user_id) : null;
        if (! $actor || $actor->company_id !== $message->mailbox->company_id || ($actor->role !== 'admin' && ! $message->mailbox->users()->whereKey($actor->id)->exists())) {
            $message->update(['status' => 'failed', 'send_error' => 'Envoi annulé : accès à la boîte révoqué.']);

            return;
        }
        try {
            $gateway->send($message);
            $message->update(['status' => 'sent', 'sent_at' => now(), 'send_error' => null]);
        } catch (\Throwable $e) {
            $confirmed = DB::transaction(function () use ($message) {
                $current = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
                if ($current->provider === 'resend' && $current->provider_message_id && $current->delivery_status) {
                    $current->update(['status' => 'sent', 'sent_at' => now(), 'send_error' => null]);

                    return true;
                }
                $current->update(['status' => 'uncertain', 'send_error' => 'Envoi non confirmé. Vérifiez la boîte et le serveur avant de renvoyer ce message.']);

                return false;
            });
            if (! $confirmed) {
                throw new \RuntimeException('Envoi non confirmé pour le message '.$message->id.'.');
            }
            $message->refresh();
        }
        $message->update(['archive_error' => 'Email envoyé. Copie dans le dossier serveur non confirmée.']);
        try {
            $gateway->archive($message->fresh());
            $message->update(['archive_error' => null]);
        } catch (\Throwable $e) {
            $message->update(['archive_error' => 'Email envoyé. Copie dans le dossier Envoyés du serveur impossible ; vérifiez le nom du dossier IMAP.']);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Message::whereKey($this->messageId)->where('status', 'sending')->update(['status' => 'uncertain', 'send_error' => 'Envoi interrompu. Vérifiez le serveur avant tout nouvel envoi.']);
    }
}
