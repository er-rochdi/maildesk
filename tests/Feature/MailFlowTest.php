<?php

namespace Tests\Feature;

use App\Contracts\MailGateway;
use App\Jobs\SendMessageJob;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use App\Services\SyncMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailFlowTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $company = Company::create(['name' => 'Societe A']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $member = User::factory()->create(['company_id' => $company->id, 'role' => 'member']);
        $otherCompany = Company::create(['name' => 'Societe B']);
        $other = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'admin']);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'password' => 'test-secret', 'imap_host' => 'mail.example.test', 'imap_port' => 993, 'smtp_host' => 'mail.example.test', 'smtp_port' => 587]);
        $box->users()->attach($member);

        return [$admin, $member, $other, $box];
    }

    private function received(Mailbox $box): Message
    {
        return Message::create(['mailbox_id' => $box->id, 'direction' => 'inbound', 'status' => 'received', 'from_address' => 'client@example.test', 'to' => [$box->email], 'subject' => 'Demande', 'body' => '<script>alert(1)</script> Bonjour', 'message_at' => now()]);
    }

    public function test_sync_is_idempotent_preserves_reads_and_handles_uid_validity_change(): void
    {
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        $gateway = new FakeMailGateway;
        $gateway->batch = ['validity' => '100', 'messages' => [['remote_uid' => 1, 'from_address' => 'client@example.test', 'to' => [$box->email], 'subject' => 'Devis', 'body' => 'Bonjour', 'message_at' => now(), 'attachments' => [['name' => 'devis.txt', 'content' => 'contenu']]]]];
        $sync = new SyncMailbox($gateway);
        $sync->run($box);
        $message = Message::firstOrFail();
        $this->assertCount(1, $message->attachments);
        Storage::disk('local')->assertExists($message->attachments[0]->path);
        $this->actingAs($admin)->postJson('/workspace/messages/'.$message->id.'/read')->assertOk()->assertJsonCount(1, 'readers');
        $sync->run($box);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        $gateway->batch['validity'] = '200';
        $sync->run($box);
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame('200', $box->fresh()->uid_validity);
        $this->assertDatabaseCount('message_reads', 1);
    }

    public function test_read_tracking_is_per_user_and_never_exposed_to_another_company(): void
    {
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        $message = $this->received($box);
        Storage::disk('local')->put('mail/test-file', 'test');
        $file = $message->attachments()->create(['name' => 'document.txt', 'path' => 'mail/test-file', 'size' => 4]);
        $this->actingAs($admin)->getJson('/workspace/messages/'.$message->id)->assertOk()->assertJsonCount(0, 'readers');
        $this->actingAs($admin)->postJson('/workspace/messages/'.$message->id.'/read')->assertOk();
        $firstRead = DB::table('message_reads')->first()->read_at;
        $this->travel(5)->minutes();
        $this->actingAs($admin)->postJson('/workspace/messages/'.$message->id.'/read')->assertOk();
        $this->assertSame($firstRead, DB::table('message_reads')->first()->read_at);
        $this->actingAs($member)->getJson('/workspace/mailboxes/'.$box->id.'/messages')->assertJsonPath('data.0.read_by_me', false)->assertJsonMissingPath('data.0.body');
        $this->actingAs($member)->postJson('/workspace/messages/'.$message->id.'/read')->assertJsonCount(2, 'readers');
        $unassigned = User::factory()->create(['company_id' => $box->company_id, 'role' => 'member']);
        $this->actingAs($unassigned)->getJson('/workspace/messages/'.$message->id)->assertNotFound();
        $this->postJson('/workspace/mailboxes/'.$box->id.'/sync')->assertNotFound();
        $this->postJson('/workspace/messages/'.$message->id.'/read')->assertNotFound();
        $this->getJson('/workspace/attachments/'.$file->id)->assertNotFound();
        $this->actingAs($member);
        $this->get('/workspace/attachments/'.$file->id)->assertOk()->assertHeader('content-type', 'application/octet-stream');
        $this->actingAs($other)->getJson('/workspace/mailboxes/'.$box->id.'/messages')->assertNotFound();
        $this->postJson('/workspace/messages/'.$message->id.'/read')->assertNotFound();
        $this->getJson('/workspace/attachments/'.$file->id)->assertNotFound();
        $box->users()->detach($member);
        $this->actingAs($member)->getJson('/workspace/messages/'.$message->id)->assertNotFound();
    }

    public function test_outgoing_records_actor_attachment_and_idempotency_and_sends_once(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Réponse', 'body' => 'Bonjour', 'attachments' => [UploadedFile::fake()->createWithContent('devis.txt', 'contenu')]];
        $this->actingAs($member)->post('/workspace/mailboxes/'.$box->id.'/messages', $payload, ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('sender.id', $member->id);
        $this->post('/workspace/mailboxes/'.$box->id.'/messages', $payload, ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        Queue::assertPushed(SendMessageJob::class, 1);
        $message = Message::firstOrFail();
        $gateway = new FakeMailGateway;
        $job = new SendMessageJob($message->id);
        $job->handle($gateway);
        $job->handle($gateway);
        $this->assertSame(1, $gateway->sent);
        $this->assertSame('sent', $message->fresh()->status);
    }

    public function test_retry_rejects_changed_content_without_creating_or_dispatching_another_message(): void
    {
        Queue::fake();
        [$admin, $member, $other, $box] = $this->workspace();
        $parent = $this->received($box);
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Original', 'body' => 'Bonjour'];
        $url = '/workspace/mailboxes/'.$box->id.'/messages';
        $this->actingAs($member)->postJson($url, $payload)->assertStatus(202);
        foreach ([['to' => ['changed@example.test']], ['cc' => ['copy@example.test']], ['subject' => 'Modifié'], ['body' => 'Au revoir'], ['reply_to_id' => $parent->id]] as $change) {
            $this->postJson($url, array_replace($payload, $change))->assertStatus(409)->assertJsonPath('message', 'Cette demande correspond déjà à un autre contenu. Vérifiez le message dans Envoyés avant de créer un nouveau message.');
        }
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('subject', 'Original');
        $this->assertDatabaseCount('messages', 2);
        Queue::assertPushed(SendMessageJob::class, 1);
    }

    public function test_retry_compares_attachment_names_and_contents_including_forwarded_files(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        $parent = $this->received($box);
        Storage::disk('local')->put('mail/forward', 'forward');
        $forward = $parent->attachments()->create(['name' => 'forward.txt', 'path' => 'mail/forward', 'size' => 7]);
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Original', 'body' => 'Bonjour', 'forward_attachment_ids' => [$forward->id]];
        $url = '/workspace/mailboxes/'.$box->id.'/messages';
        $post = fn ($changes = [], $name = 'devis.txt', $content = 'original') => $this->post($url, array_replace($payload, ['attachments' => [UploadedFile::fake()->createWithContent($name, $content)]], $changes), ['Accept' => 'application/json']);
        $this->actingAs($member);
        $post()->assertStatus(202);
        $post()->assertOk();
        $post([], 'devis.txt', 'modified')->assertStatus(409);
        $post([], 'autre.txt')->assertStatus(409);
        $post(['attachments' => []])->assertStatus(409);
        $post(['forward_attachment_ids' => []])->assertStatus(409);
        Storage::disk('local')->put('mail/forward', 'changed');
        $post()->assertStatus(409);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('attachments', 3);
        Queue::assertPushed(SendMessageJob::class, 1);
    }

    public function test_smtp_failure_is_uncertain_and_not_retried_and_revoked_user_cannot_send(): void
    {
        [$admin, $member, $other, $box] = $this->workspace();
        $message = $this->received($box);
        $message->update(['direction' => 'outbound', 'status' => 'queued', 'sender_user_id' => $member->id]);
        $gateway = new FakeMailGateway;
        $gateway->fail = true;
        $job = new SendMessageJob($message->id);
        try {
            $job->handle($gateway);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('password', $e->getMessage());
        }
        $this->assertSame('uncertain', $message->fresh()->status);
        $job->handle($gateway);
        $this->assertSame(1, $gateway->sent);
        $message->refresh()->update(['status' => 'queued']);
        $box->users()->detach($member);
        $job->handle($gateway);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame(1, $gateway->sent);
    }

    public function test_archive_failure_keeps_smtp_accepted_status(): void
    {
        [$admin, $member, $other, $box] = $this->workspace();
        $message = $this->received($box);
        $message->update(['status' => 'queued', 'direction' => 'outbound', 'sender_user_id' => $member->id]);
        $gateway = new FakeMailGateway;
        $gateway->archiveFail = true;
        (new SendMessageJob($message->id))->handle($gateway);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->archive_error);
        (new SendMessageJob($message->id))->handle($gateway);
        $this->assertSame(1, $gateway->sent);
    }

    public function test_provider_event_before_http_timeout_confirms_send_and_still_archives_once(): void
    {
        [$admin, $member, $other, $box] = $this->workspace();
        $message = $this->received($box);
        $message->update(['direction' => 'outbound', 'status' => 'queued', 'sender_user_id' => $member->id]);
        $gateway = new class implements MailGateway
        {
            public int $sent = 0;
            public int $archived = 0;

            public function receive(Mailbox $mailbox): array
            {
                return ['validity' => '1', 'messages' => []];
            }

            public function send(Message $message): void
            {
                $this->sent++;
                $message->update(['provider' => 'resend', 'provider_message_id' => (string) Str::uuid(), 'delivery_status' => 'delivered']);
                throw new \RuntimeException('HTTP response lost after webhook');
            }

            public function archive(Message $message): void
            {
                $this->archived++;
            }
        };
        $job = new SendMessageJob($message->id);
        $job->handle($gateway);
        $job->handle($gateway);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNull($message->fresh()->send_error);
        $this->assertNull($message->fresh()->archive_error);
        $this->assertSame(1, $gateway->sent);
        $this->assertSame(1, $gateway->archived);
    }

    public function test_queue_outage_preserves_message_and_attachments_for_recovery(): void
    {
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue offline'));
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Test', 'body' => 'Test', 'attachments' => [UploadedFile::fake()->createWithContent('test.txt', 'private')]];
        $this->actingAs($member)->post('/workspace/mailboxes/'.$box->id.'/messages', $payload, ['Accept' => 'application/json'])->assertStatus(202);
        $message = Message::firstOrFail();
        $this->assertSame('queued', $message->status);
        $this->assertNotNull($message->send_error);
        Storage::disk('local')->assertExists($message->attachments->first()->path);
    }

    public function test_mailbox_configuration_preserves_password_and_is_admin_scoped(): void
    {
        [$admin, $member, $other, $box] = $this->workspace();
        $payload = ['name' => 'Contact updated', 'email' => $box->email, 'imap_host' => $box->imap_host, 'imap_port' => 993, 'smtp_host' => $box->smtp_host, 'smtp_port' => 465, 'sent_folder' => 'INBOX.Sent', 'password' => ''];
        $url = '/workspace/mailboxes/'.$box->id;
        $this->actingAs($member)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($other)->putJson($url, $payload)->assertNotFound();
        $this->actingAs($admin)->putJson($url, $payload)->assertOk()->assertJsonMissingPath('password');
        $this->assertSame('test-secret', $box->fresh()->password);
        $this->assertSame('INBOX.Sent', $box->fresh()->sent_folder);
    }

    public function test_reply_and_forward_cannot_reference_another_mailbox(): void
    {
        Queue::fake();
        [$admin, $member, $other, $box] = $this->workspace();
        $message = $this->received($box);
        $otherBox = Mailbox::create(['company_id' => $other->company_id, 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'secret', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test']);
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Test', 'body' => 'Test', 'reply_to_id' => $message->id];
        $this->actingAs($other)->postJson('/workspace/mailboxes/'.$otherBox->id.'/messages', $payload)->assertUnprocessable();
        $file = $message->attachments()->create(['name' => 'private.txt', 'path' => 'private', 'size' => 1]);
        unset($payload['reply_to_id']);
        $payload['forward_attachment_ids'] = [$file->id];
        $this->postJson('/workspace/mailboxes/'.$otherBox->id.'/messages', $payload)->assertUnprocessable();
        $this->actingAs($member)->postJson('/workspace/mailboxes/'.$otherBox->id.'/messages', $payload)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_two_members_reply_and_forward_preserve_actor_and_copy_private_attachments(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$admin, $member, $other, $box] = $this->workspace();
        $second = User::factory()->create(['company_id' => $box->company_id, 'role' => 'member']);
        $box->users()->attach($second);
        $original = $this->received($box);
        Storage::disk('local')->put('mail/original', 'original attachment');
        $file = $original->attachments()->create(['name' => 'original.txt', 'path' => 'mail/original', 'size' => 19]);
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Re: Demande', 'body' => 'Reponse membre', 'reply_to_id' => $original->id];
        $reply = $this->actingAs($member)->postJson('/workspace/mailboxes/'.$box->id.'/messages', $payload)->assertStatus(202)->assertJsonPath('sender.id', $member->id)->json('id');
        $forward = $this->actingAs($second)->postJson('/workspace/mailboxes/'.$box->id.'/messages', ['request_id' => (string) Str::uuid(), 'to' => ['colleague@example.test'], 'subject' => 'Fwd: Demande', 'body' => 'Transfert', 'forward_attachment_ids' => [$file->id]])->assertStatus(202)->assertJsonPath('sender.id', $second->id)->json('id');
        $copy = Message::findOrFail($forward)->attachments()->firstOrFail();
        $this->assertNotSame($file->path, $copy->path);
        $this->assertSame('original attachment', Storage::disk('local')->get($copy->path));
        $file->update(['size' => 11 * 1024 * 1024]);
        $this->actingAs($second)->postJson('/workspace/mailboxes/'.$box->id.'/messages', ['request_id' => (string) Str::uuid(), 'to' => ['colleague@example.test'], 'subject' => 'Fwd: too large', 'body' => 'Test', 'forward_attachment_ids' => [$file->id]])->assertUnprocessable();
        $this->assertSame($original->id, Message::findOrFail($reply)->reply_to_id);
        $this->actingAs($member)->get('/workspace/attachments/'.$copy->id)->assertOk()->assertDownload('original.txt');
        $box->users()->detach($member);
        $this->getJson('/workspace/attachments/'.$copy->id)->assertNotFound();
        $this->postJson('/workspace/mailboxes/'.$box->id.'/messages', $payload)->assertNotFound();
        $gateway = new FakeMailGateway;
        (new SendMessageJob($reply))->handle($gateway);
        (new SendMessageJob($forward))->handle($gateway);
        $this->assertSame('failed', Message::findOrFail($reply)->status);
        $this->assertSame('sent', Message::findOrFail($forward)->status);
        $this->assertSame(1, $gateway->sent);
    }

    public function test_sync_failure_has_safe_diagnostic_preserved_by_job_and_clears_on_recovery(): void
    {
        [$admin, $member, $other, $box] = $this->workspace();
        $gateway = new FakeMailGateway;
        $gateway->receiveFail = true;
        try {
            (new SyncMailbox($gateway))->run($box);
            $this->fail('Expected IMAP failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('secret-password', $exception->getMessage());
        }
        $error = $box->fresh()->sync_error;
        $this->assertStringContainsString('IMAP inaccessible', $error);
        $this->assertStringNotContainsString('secret-password', $error);
        (new \App\Jobs\SyncMailboxJob($box->id))->failed(new \RuntimeException);
        $this->assertSame($error, $box->fresh()->sync_error);
        $gateway->receiveFail = false;
        (new SyncMailbox($gateway))->run($box);
        $this->assertNull($box->fresh()->sync_error);
    }
}

class FakeMailGateway implements MailGateway
{
    public array $batch = ['validity' => '1', 'messages' => []];

    public int $sent = 0;

    public bool $fail = false;

    public bool $archiveFail = false;

    public bool $receiveFail = false;

    public function archive(Message $message): void
    {
        if ($this->archiveFail) {
            throw new \RuntimeException('Archive failed');
        }
    }

    public function receive(Mailbox $mailbox): array
    {
        if ($this->receiveFail) {
            throw new \RuntimeException('Connection timed out: secret-password');
        }
        return $this->batch;
    }

    public function send(Message $message): void
    {
        $this->sent++;
        if ($this->fail) {
            throw new \RuntimeException('password must never be logged');
        }
    }
}
