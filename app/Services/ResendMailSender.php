<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;

class ResendMailSender
{
    public function send(Message $message, Email $email): void
    {
        $key = config('services.resend.key');
        if (! $key) {
            throw new RuntimeException('Clé Resend non configurée.');
        }
        if (! $message->request_id) {
            $message->update(['request_id' => (string) Str::uuid()]);
        }
        // Persist correlation before sending: a webhook may arrive before the HTTP response.
        $message->update(['provider' => 'resend']);
        $headers = [];
        foreach (['Message-ID', 'In-Reply-To', 'References'] as $name) {
            if ($header = $email->getHeaders()->get($name)) {
                $headers[$name] = $header->getBodyAsString();
            }
        }
        $payload = [
            'from' => $email->getFrom()[0]->toString(), 'to' => $message->to,
            'subject' => $message->subject, 'text' => $message->body, 'headers' => $headers,
            'tags' => [['name' => 'maildesk_request_id', 'value' => $message->request_id]],
        ];
        if ($message->cc) {
            $payload['cc'] = $message->cc;
        }
        if ($message->attachments->isNotEmpty()) {
            $payload['attachments'] = $message->attachments->map(fn ($file) => [
                'filename' => $file->name, 'content' => base64_encode(Storage::disk('local')->get($file->path)),
            ])->all();
        }
        // No automatic HTTP retry: the job retains the existing uncertain-send protection.
        $response = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(30)
            ->withHeaders(['Idempotency-Key' => 'maildesk-'.$message->mailbox_id.'-'.$message->request_id])
            ->post('https://api.resend.com/emails', $payload);
        if (! $response->successful() || ! Str::isUuid($response->json('id') ?? '')) {
            throw new RuntimeException('Envoi Resend non confirmé. Consultez le tableau de bord Resend.');
        }
        $message->update(['provider_message_id' => $response->json('id')]);
    }
}
