<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'local-test-signing-secret-32-bytes';

    private function message(array $recipients = ['client@example.test']): Message
    {
        config(['services.resend.webhook_secret' => 'whsec_'.base64_encode($this->secret)]);
        $company = Company::create(['name' => 'QA']);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test', 'password' => 'secret']);

        return Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'status' => 'sent', 'provider' => 'resend', 'request_id' => (string) Str::uuid(), 'from_address' => $box->email, 'to' => $recipients, 'body' => 'Private body', 'subject' => 'Test', 'message_at' => now()]);
    }

    private function event(Message $message, string $type = 'email.delivered', ?array $to = null): array
    {
        return ['type' => $type, 'created_at' => now()->toIso8601String(), 'data' => [
            'email_id' => '2b533d25-4406-4a7b-8283-f7670864ee31', 'message_id' => '<rewritten@resend.example>',
            'from' => 'Contact <'.$message->from_address.'>', 'to' => $to ?? $message->to,
            'tags' => ['maildesk_request_id' => $message->request_id],
        ]];
    }

    private function signed(array $event, string $id = 'msg_test', ?int $timestamp = null, bool $tampered = false)
    {
        $timestamp ??= now()->timestamp;
        $body = json_encode($event);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $this->secret, true));

        return $this->call('POST', '/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp, 'HTTP_SVIX_SIGNATURE' => 'v1,invalid v1,'.$signature,
        ], $tampered ? $body.' ' : $body);
    }

    public function test_signature_missing_secret_old_future_and_tampered_requests_are_rejected(): void
    {
        $message = $this->message();
        $event = $this->event($message);
        $this->postJson('/webhooks/resend', $event)->assertUnauthorized();
        $this->signed($event, timestamp: now()->timestamp - 301)->assertUnauthorized();
        $this->signed($event, timestamp: now()->timestamp + 301)->assertUnauthorized();
        $this->signed($event, tampered: true)->assertUnauthorized();
        $this->assertDatabaseCount('message_delivery_events', 0);
        config(['services.resend.webhook_secret' => null]);
        $this->signed($event)->assertStatus(503);
    }

    public function test_signature_verification_matches_the_independent_official_svix_vector(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(1731705121));
        config(['services.resend.webhook_secret' => 'whsec_plJ3nmyCDGBKInavdOK15jsl']);
        $this->call('POST', '/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => 'msg_loFOjxBNrRLzqYUf', 'HTTP_SVIX_TIMESTAMP' => '1731705121',
            'HTTP_SVIX_SIGNATURE' => 'v1,rAvfW3dJ/X/qxhsaXPOyyCGmRKsaKWcsNccKXlIktD0=',
        ], '{"event_type":"ping","data":{"success":true}}')->assertOk();
        $this->travelBack();
    }

    public function test_delivery_is_correlated_despite_provider_message_id_rewriting_and_is_idempotent(): void
    {
        $message = $this->message();
        $this->signed($this->event($message))->assertOk()->assertJsonPath('matched', true);
        $this->signed($this->event($message))->assertOk();
        $this->assertDatabaseCount('message_delivery_events', 1);
        $this->assertSame('delivered', $message->fresh()->delivery_status);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame('2b533d25-4406-4a7b-8283-f7670864ee31', $message->fresh()->provider_message_id);
        $delayed = $this->event($message, 'email.delivery_delayed');
        $delayed['created_at'] = now()->subMinute()->toIso8601String();
        $this->signed($delayed, 'msg_delayed')->assertOk();
        $this->assertSame('delivered', $message->fresh()->delivery_status);
        $this->signed($this->event($message, 'email.bounced'), 'msg_bounce')->assertOk();
        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertNotNull($message->fresh()->delivery_error);
        $this->signed($this->event($message), 'msg_delivered_again')->assertOk();
        $this->assertSame('failed', $message->fresh()->delivery_status);
    }

    public function test_all_recipients_must_be_delivered_and_partial_failures_remain_visible(): void
    {
        $message = $this->message(['one@example.test', 'two@example.test']);
        $this->signed($this->event($message, to: ['one@example.test']), 'msg_one')->assertOk();
        $this->assertSame('partial', $message->fresh()->delivery_status);
        $this->signed($this->event($message, to: ['two@example.test']), 'msg_two')->assertOk();
        $this->assertSame('delivered', $message->fresh()->delivery_status);
        $this->signed($this->event($message, 'email.bounced', ['two@example.test']), 'msg_two_bounced')->assertOk();
        $this->assertSame('partial', $message->fresh()->delivery_status);
        $this->assertStringContainsString('two@example.test', $message->fresh()->delivery_error);
        $this->assertStringNotContainsString('one@example.test', $message->fresh()->delivery_error);
    }

    public function test_signed_event_confirms_an_uncertain_send_without_enqueuing_another_email(): void
    {
        $message = $this->message();
        $message->update(['status' => 'uncertain', 'send_error' => 'HTTP timeout']);
        $this->signed($this->event($message))->assertOk()->assertJsonPath('matched', true);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNull($message->fresh()->send_error);
        $this->assertNotNull($message->fresh()->archive_error);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_unrelated_sender_recipient_reference_and_provider_id_are_not_matched(): void
    {
        $message = $this->message();
        $event = $this->event($message);
        $event['data']['from'] = 'another@example.test';
        $this->signed($event)->assertOk()->assertJsonPath('matched', false);
        $event = $this->event($message, to: ['another@example.test']);
        $this->signed($event)->assertOk()->assertJsonPath('matched', false);
        $event = $this->event($message);
        $event['data']['tags']['maildesk_request_id'] = (string) Str::uuid();
        $this->signed($event)->assertOk()->assertJsonPath('matched', false);
        $message->update(['provider_message_id' => (string) Str::uuid()]);
        $this->signed($this->event($message))->assertOk()->assertJsonPath('matched', false);
        $this->assertDatabaseCount('message_delivery_events', 0);
        $this->assertNull($message->fresh()->delivery_status);
    }

    public function test_ambiguous_company_reference_is_ignored_instead_of_guessing(): void
    {
        $message = $this->message();
        $other = $this->message();
        $other->update(['request_id' => $message->request_id]);
        $this->signed($this->event($message))->assertOk()->assertJsonPath('matched', false);
        $this->assertDatabaseCount('message_delivery_events', 0);
    }

    public function test_webhook_is_csrf_exempt_but_authentication_forms_remain_protected(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $message = $this->message();
        $message->update(['status' => 'sending']);
        $this->signed($this->event($message))->assertOk()->assertJsonPath('matched', true);
        $this->postJson('/auth/login', ['email' => 'qa@example.test', 'password' => 'password1'])->assertStatus(419);
        $this->assertSame('delivered', $message->fresh()->delivery_status);
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(DB::table('message_delivery_events')->where('type', 'email.opened')->exists());
    }
}
