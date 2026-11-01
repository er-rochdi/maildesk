<?php

namespace App\Services;

use App\Contracts\MailGateway;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Support\MessageCollection;

class HestiaMailGateway implements MailGateway
{
    public function __construct(private MailServerAddress $addresses, private ClientManager $clients) {}

    public function receive(Mailbox $mailbox): array
    {
        $client = $this->client($mailbox);
        try {
            $client->connect();
            $folder = $client->getFolderByPath('INBOX');
            $status = $folder->status();
            $validity = hash('sha256', strtolower($mailbox->imap_host)).':'.$status['uidvalidity'];
            $after = $validity === $mailbox->uid_validity ? $mailbox->last_uid : 0;
            $items = $folder->query()->whereUid(($after + 1).':*')->setFetchOrderAsc()->leaveUnread()->setFetchBody(false)->limit(50)->get();

            return ['validity' => $validity, 'messages' => $this->messages($client, $items, $after)];
        } catch (\Throwable $e) {
            $client->disconnect();
            throw $e;
        }
    }

    private function messages(Client $client, MessageCollection $items, int $after): \Generator
    {
        try {
            // Shift headers out of the collection so processed bodies are not retained for the whole batch.
            while ($items->isNotEmpty()) {
                $item = $items->shift();
                $uid = (int) $item->getUid();
                // IMAP ranges can return the final UID when the lower bound is beyond the final message.
                if ($uid <= $after) {
                    continue;
                }
                $item->parseBody();
                $from = $item->getFrom()->first();
                $attachments = [];
                foreach ($item->getAttachments() as $attachment) {
                    $attachments[] = ['name' => Attachment::safeName($attachment->getName() ?: 'piece-jointe'), 'content' => $attachment->getContent()];
                }
                $text = $item->getTextBody();
                $html = $item->getHTMLBody() ?: null;
                yield [
                    'remote_uid' => $uid, 'internet_message_id' => mb_substr((string) $item->getMessageId(), 0, 255),
                    'from_address' => mb_substr($from->mail ?? '', 0, 255), 'from_name' => mb_substr($from->personal ?? '', 0, 255),
                    'to' => $this->addresses($item->getTo()), 'cc' => $this->addresses($item->getCc()),
                    'subject' => mb_substr((string) $item->getSubject(), 0, 255),
                    'body' => $text ?: html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html ?: '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'html_body' => $html ? (new SafeEmailHtml)->clean($html) : null,
                    'message_at' => $item->getDate()->first() ?: now(), 'attachments' => $attachments,
                    'delivery_report' => (new DeliveryReport)->parse((string) $item->getHeader()->get('content_type')->first(), $item->getHeader()->getBoundary() ?? '', $item->getRawBody()),
                ];
                unset($item, $attachments, $text, $html);
            }
        } finally {
            $client->disconnect();
        }
    }

    private function client(Mailbox $mailbox): Client
    {
        $ip = $this->addresses->resolve($mailbox->imap_host);

        return $this->clients->make([
            'host' => $ip, 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true,
            'username' => $mailbox->email, 'password' => $mailbox->password, 'protocol' => 'imap',
            'timeout' => 20, 'ssl_options' => ['peer_name' => $mailbox->imap_host, 'SNI_enabled' => true],
        ]);
    }

    private function addresses($attribute): array
    {
        $result = [];
        foreach ($attribute->all() as $address) {
            if (! empty($address->mail)) {
                $result[] = $address->mail;
            }
        }

        return $result;
    }

    public function send(Message $message): void
    {
        if (config('services.resend.send_via_api') && $message->mailbox->resend_enabled) {
            app(ResendMailSender::class)->send($message, $this->email($message));

            return;
        }
        $mailbox = $message->mailbox;
        $transport = new EsmtpTransport($this->addresses->resolve($mailbox->smtp_host), (int) $mailbox->smtp_port, (int) $mailbox->smtp_port === 465);
        $transport->setUsername($mailbox->email)->setPassword($mailbox->password)->setRequireTls(true);
        $transport->getStream()->setTimeout(30)->setStreamOptions(['ssl' => ['peer_name' => $mailbox->smtp_host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        (new Mailer($transport))->send($this->email($message));
    }

    public function archive(Message $message): void
    {
        $client = $this->client($message->mailbox);
        try {
            $client->connect();
            $client->getFolderByPath($message->mailbox->sent_folder)->appendMessage($this->email($message)->toString(), ['\\Seen']);
        } finally {
            $client->disconnect();
        }
    }

    public function email(Message $message): Email
    {
        $mailbox = $message->mailbox;
        $email = (new Email)->from(new Address($mailbox->email, $mailbox->name))->to(...$message->to)->subject($message->subject)->text($message->body)->date($message->message_at);
        if ($message->cc) {
            $email->cc(...$message->cc);
        }
        if ($message->bcc) {
            $email->bcc(...$message->bcc);
        }
        $email->getHeaders()->addIdHeader('Message-ID', $message->internet_message_id);
        if ($message->reply_to_id) {
            $parent = Message::where('mailbox_id', $mailbox->id)->find($message->reply_to_id);
            if ($parent && preg_match('/\A[^\s<>]+@[^\s<>]+\z/', trim($parent->internet_message_id ?? '', '<>'))) {
                $email->getHeaders()->addIdHeader('In-Reply-To', trim($parent->internet_message_id, '<>'));
                $email->getHeaders()->addIdHeader('References', trim($parent->internet_message_id, '<>'));
            }
        }
        foreach ($message->attachments as $attachment) {
            $email->attachFromPath(Storage::disk('local')->path($attachment->path), $attachment->name, 'application/octet-stream');
        }

        return $email;
    }
}
