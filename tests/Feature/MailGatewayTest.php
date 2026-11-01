<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Services\HestiaMailGateway;
use App\Services\MailServerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class MailGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function box(): Mailbox
    {
        $company = Company::create(['name' => 'Test']);

        return Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'password' => 'secret', 'imap_host' => 'mail.example.test', 'imap_port' => 993, 'smtp_host' => 'mail.example.test', 'smtp_port' => 587, 'sent_folder' => 'Sent']);
    }

    public function test_imap_adapter_parses_real_mime_and_keeps_messages_unread(): void
    {
        $box = $this->box();
        $raw = "From: Client <client@example.test>\r\nTo: contact@example.test\r\nSubject: Devis\r\nMessage-ID: <test@example.test>\r\nDate: Fri, 30 Oct 2026 10:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=boundary123\r\n\r\n--boundary123\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nBonjour\r\n--boundary123\r\nContent-Type: text/plain; name=devis.txt\r\nContent-Disposition: attachment; filename=devis.txt\r\nContent-Transfer-Encoding: base64\r\n\r\nY29udGVudQ==\r\n--boundary123--\r\n";
        $raw = str_replace("--boundary123\r\nContent-Type: text/plain; name=devis.txt", "--boundary123\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<b>Bonjour HTML</b><script>alert(1)</script>\r\n--boundary123\r\nContent-Type: text/plain; name=devis.txt", $raw);
        $parsed = Mockery::mock(\Webklex\PHPIMAP\Message::fromString($raw))->makePartial();
        $parsed->shouldReceive('parseBody')->once()->andReturnSelf();
        $parsed->setUid(12);
        $resolver = Mockery::mock(MailServerAddress::class);
        $resolver->shouldReceive('resolve')->with('mail.example.test')->andReturn('93.184.216.34');
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('disconnect')->once();
        $folder = Mockery::mock(Folder::class);
        $folder->shouldReceive('status')->andReturn(['uidvalidity' => 123]);
        $client->shouldReceive('getFolderByPath')->with('INBOX')->andReturn($folder);
        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);
        $query->shouldReceive('whereUid')->with('1:*')->andReturnSelf();
        $query->shouldReceive('setFetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('leaveUnread')->once()->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->once()->andReturnSelf();
        $query->shouldReceive('limit')->with(50)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection([$parsed]));
        $manager = Mockery::mock(ClientManager::class);
        $manager->shouldReceive('make')->with(Mockery::on(fn ($config) => $config['host'] === '93.184.216.34' && $config['validate_cert'] && $config['encryption'] === 'ssl' && $config['ssl_options']['peer_name'] === 'mail.example.test'))->andReturn($client);
        $batch = (new HestiaMailGateway($resolver, $manager))->receive($box);
        $this->assertInstanceOf(\Generator::class, $batch['messages']);
        $batch['messages'] = iterator_to_array($batch['messages']);
        $this->assertSame(12, $batch['messages'][0]['remote_uid']);
        $this->assertSame('client@example.test', $batch['messages'][0]['from_address']);
        $this->assertSame(['contact@example.test'], $batch['messages'][0]['to']);
        $this->assertStringContainsString('Bonjour', $batch['messages'][0]['body']);
        $this->assertStringContainsString('Bonjour HTML', $batch['messages'][0]['html_body']);
        $this->assertStringNotContainsString('<script', $batch['messages'][0]['html_body']);
        $this->assertSame('contenu', $batch['messages'][0]['attachments'][0]['content']);
    }

    public function test_body_fetch_is_lazy_and_disconnects_on_failure(): void
    {
        $box = $this->box();
        $resolver = Mockery::mock(MailServerAddress::class);
        $resolver->shouldReceive('resolve')->with('mail.example.test')->andReturn('93.184.216.34');
        $item = Mockery::mock(\Webklex\PHPIMAP\Message::class);
        $item->shouldReceive('getUid')->once()->andReturn(1);
        $item->shouldReceive('parseBody')->once()->andThrow(new \RuntimeException('Body fetch failed'));
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('disconnect')->once();
        $folder = Mockery::mock(Folder::class);
        $client->shouldReceive('getFolderByPath')->with('INBOX')->andReturn($folder);
        $folder->shouldReceive('status')->andReturn(['uidvalidity' => 123]);
        $query = Mockery::mock(WhereQuery::class);
        $folder->shouldReceive('query')->andReturn($query);
        $query->shouldReceive('whereUid')->with('1:*')->andReturnSelf();
        $query->shouldReceive('setFetchOrderAsc')->andReturnSelf();
        $query->shouldReceive('leaveUnread')->andReturnSelf();
        $query->shouldReceive('setFetchBody')->with(false)->andReturnSelf();
        $query->shouldReceive('limit')->with(50)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new MessageCollection([$item]));
        $manager = Mockery::mock(ClientManager::class);
        $manager->shouldReceive('make')->andReturn($client);

        $batch = (new HestiaMailGateway($resolver, $manager))->receive($box);
        $this->assertInstanceOf(\Generator::class, $batch['messages']);
        $this->expectExceptionMessage('Body fetch failed');
        iterator_to_array($batch['messages']);
    }

    public function test_outgoing_mime_contains_identity_reply_reference_and_private_attachment(): void
    {
        Storage::fake('local');
        $box = $this->box();
        $parent = Message::create(['mailbox_id' => $box->id, 'direction' => 'inbound', 'from_address' => 'client@example.test', 'to' => [$box->email], 'subject' => 'Question', 'body' => 'Bonjour', 'message_at' => now(), 'internet_message_id' => '<parent@example.test>']);
        $message = Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'from_address' => $box->email, 'to' => ['client@example.test'], 'cc' => ['copy@example.test'], 'subject' => 'Re: Question', 'body' => 'Une réponse', 'message_at' => now(), 'internet_message_id' => 'new@example.test', 'reply_to_id' => $parent->id]);
        Storage::disk('local')->put('mail/test', 'contenu');
        $message->attachments()->create(['path' => 'mail/test', 'name' => 'devis.txt', 'size' => 7]);
        $email = app(HestiaMailGateway::class)->email($message);
        $this->assertSame($box->email, $email->getFrom()[0]->getAddress());
        $this->assertSame('client@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('new@example.test', $email->getHeaders()->get('Message-ID')->getId());
        $this->assertStringContainsString('parent@example.test', $email->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('parent@example.test', $email->getHeaders()->get('References')->getBodyAsString());
        $this->assertCount(1, $email->getAttachments());
        $this->assertStringContainsString('multipart/mixed', $email->toString());
    }

    public function test_bcc_is_in_smtp_envelope_but_not_the_transmitted_message(): void
    {
        $box = $this->box();
        $message = Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'from_address' => $box->email, 'to' => ['client@example.test'], 'bcc' => ['private@example.test'], 'subject' => 'Test', 'body' => 'Bonjour', 'message_at' => now(), 'internet_message_id' => 'bcc@example.test']);
        $email = app(HestiaMailGateway::class)->email($message);
        $envelope = \Symfony\Component\Mailer\Envelope::create($email);
        $sent = new \Symfony\Component\Mailer\SentMessage($email, $envelope);
        $this->assertContains('private@example.test', array_map(fn ($recipient) => $recipient->getAddress(), $envelope->getRecipients()));
        $this->assertStringNotContainsString('private@example.test', $sent->toString());
    }

    public function test_private_network_and_invalid_server_names_are_refused(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '192.168.1.4', '100.64.0.1', '0.0.0.0', 'localhost/attack', 'host:123'] as $host) {
            try {
                (new MailServerAddress)->resolve($host);
                $this->fail('Expected private host to be rejected');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
