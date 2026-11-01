<?php

namespace Tests\Feature;

use App\Jobs\SendMessageJob;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use App\Services\HestiaMailGateway;
use App\Services\MailServerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ResendMailSenderTest extends TestCase
{
    use RefreshDatabase;

    private function message(): Message
    {
        config(['services.resend.send_via_api' => true, 'services.resend.key' => 'test-key-not-a-real-secret']);
        $company = Company::create(['name' => 'Test']);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test', 'password' => 'secret']);
        $box->forceFill(['resend_enabled' => true])->save();

        return Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'status' => 'sending', 'request_id' => (string) Str::uuid(), 'from_address' => $box->email, 'to' => ['client@example.test'], 'cc' => ['copy@example.test'], 'subject' => 'Test', 'body' => 'Bonjour', 'message_at' => now(), 'internet_message_id' => Str::uuid().'@example.test']);
    }

    public function test_api_sends_private_attachment_with_correlation_and_idempotency(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/emails' => Http::response(['id' => '2b533d25-4406-4a7b-8283-f7670864ee31'])]);
        $message = $this->message();
        $message->update(['bcc' => ['private@example.test']]);
        Storage::disk('local')->put('qa/file', 'private bytes');
        $message->attachments()->create(['path' => 'qa/file', 'name' => 'devis.txt', 'size' => 13]);
        app(HestiaMailGateway::class)->send($message);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Authorization', 'Bearer test-key-not-a-real-secret')
            && $request->hasHeader('Idempotency-Key', 'maildesk-'.$message->mailbox_id.'-'.$message->request_id)
            && $request['tags'][0]['value'] === $message->request_id
            && base64_decode($request['attachments'][0]['content']) === 'private bytes'
            && $request['cc'] === ['copy@example.test'] && $request['bcc'] === ['private@example.test']);
        $this->assertSame('resend', $message->fresh()->provider);
        $this->assertSame('2b533d25-4406-4a7b-8283-f7670864ee31', $message->fresh()->provider_message_id);
    }

    public function test_missing_attachment_stops_before_any_provider_request(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        $message = $this->message();
        $message->attachments()->create(['path' => 'missing/file', 'name' => 'devis.txt', 'size' => 13]);
        $actor = User::factory()->create(['company_id' => $message->mailbox->company_id, 'role' => 'admin']);
        $message->update(['status' => 'queued', 'sender_user_id' => $actor->id]);
        $gateway = app(HestiaMailGateway::class);
        (new SendMessageJob($message->id))->handle($gateway);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('Pièce jointe indisponible. Aucun envoi Resend n’a été effectué.', $message->fresh()->send_error);
        $this->assertNull($message->fresh()->sent_at);
        $this->assertNull($message->fresh()->archive_error);
        (new SendMessageJob($message->id))->handle($gateway);
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_sanitized_and_not_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/emails' => Http::response(['message' => 'secret credential in provider error'], 403)]);
        $message = $this->message();
        try {
            app(HestiaMailGateway::class)->send($message);
            $this->fail('Expected provider failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('secret credential', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertNull($message->fresh()->provider_message_id);
    }

    public function test_api_key_cannot_be_used_by_a_mailbox_without_operator_opt_in(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $message = $this->message();
        $message->mailbox->forceFill(['resend_enabled' => false])->save();
        $resolver = Mockery::mock(MailServerAddress::class);
        $resolver->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('SMTP route selected'));
        $this->app->instance(MailServerAddress::class, $resolver);
        try {
            app(HestiaMailGateway::class)->send($message);
            $this->fail('Expected SMTP route');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP route selected', $exception->getMessage());
        }
        Http::assertNothingSent();
        $message->mailbox->fill(['resend_enabled' => true])->save();
        $this->assertFalse($message->mailbox->fresh()->resend_enabled);
    }
}
