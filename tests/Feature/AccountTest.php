<?php

namespace Tests\Feature;

use App\Contracts\MailGateway;
use App\Jobs\SendMessageJob;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function accounts(): array
    {
        $company = Company::create(['name' => 'A']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'password' => 'password1']);
        $member = User::factory()->create(['company_id' => $company->id, 'role' => 'member', 'password' => 'password1']);
        $foreign = User::factory()->create(['company_id' => Company::create(['name' => 'B'])->id, 'role' => 'admin']);

        return [$admin, $member, $foreign];
    }

    public function test_user_can_save_a_personal_signature_without_changing_others(): void
    {
        [$admin, $member] = $this->accounts();
        $this->actingAs($member)->putJson('/account/profile', ['name' => $member->name, 'email' => $member->email, 'signature' => "Cordialement,\nClientèle"])
            ->assertOk()->assertJsonPath('signature', "Cordialement,\nClientèle");
        $this->assertSame("Cordialement,\nClientèle", $member->fresh()->signature);
        $this->assertNull($admin->fresh()->signature);
    }

    public function test_profile_can_change_name_but_cannot_escalate_role_company_or_status(): void
    {
        [$admin, $member, $foreign] = $this->accounts();
        $this->actingAs($member)->putJson('/account/profile', ['name' => 'Updated', 'email' => $member->email, 'role' => 'admin', 'company_id' => $foreign->company_id, 'permissions' => User::PERMISSIONS, 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'Updated')->assertJsonPath('role', 'member')->assertJsonPath('is_active', true)->assertJsonMissingPath('password')->assertJsonMissingPath('session_version');
        $this->assertSame($admin->company_id, $member->fresh()->company_id);
        $this->assertFalse($member->fresh()->hasPermission('manage_users'));
    }

    public function test_email_change_requires_password_and_invalidates_old_recovery_token_and_sessions(): void
    {
        [$admin, $member] = $this->accounts();
        $oldEmail = $member->email;
        Password::createToken($member);
        $data = ['name' => 'New name', 'email' => 'new@example.test'];
        $this->actingAs($member)->putJson('/account/profile', $data)->assertUnprocessable();
        $this->putJson('/account/profile', $data + ['current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/account/profile', $data + ['current_password' => 'password1'])->assertOk()->assertJsonPath('email', 'new@example.test');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertSame(1, $member->fresh()->session_version);
        $this->getJson('/auth/me')->assertOk();
        $this->withSession(['session_version' => 0])->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_password_change_checks_confirmation_revokes_old_sessions_and_preserves_current_session(): void
    {
        [$admin, $member] = $this->accounts();
        $this->actingAs($member)->putJson('/account/password', ['current_password' => 'wrong', 'password' => 'new-password1', 'password_confirmation' => 'new-password1'])->assertUnprocessable();
        $this->putJson('/account/password', ['current_password' => 'password1', 'password' => 'new-password1', 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->putJson('/account/password', ['current_password' => 'password1', 'password' => 'new-password1', 'password_confirmation' => 'new-password1'])->assertOk();
        $this->assertTrue(Hash::check('new-password1', $member->fresh()->password));
        $this->getJson('/auth/me')->assertOk();
        $this->withSession(['session_version' => 0])->getJson('/workspace/mailboxes')->assertUnauthorized();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'password1'])->assertUnprocessable();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'new-password1'])->assertOk();
        $this->getJson('/auth/me')->assertOk();
    }

    public function test_only_same_company_admin_can_edit_or_disable_another_account(): void
    {
        [$admin, $member, $foreign] = $this->accounts();
        $member->update(['permissions' => ['manage_users']]);
        $data = ['name' => 'Edited', 'email' => $admin->email];
        $this->actingAs($member)->putJson('/workspace/users/'.$admin->id, $data)->assertForbidden();
        $this->putJson('/workspace/users/'.$admin->id.'/status', ['is_active' => false])->assertForbidden();
        $this->actingAs($foreign)->putJson('/workspace/users/'.$member->id, ['name' => 'Edited', 'email' => $member->email])->assertNotFound();
        $this->putJson('/workspace/users/'.$member->id.'/status', ['is_active' => false])->assertNotFound();
        $this->actingAs($admin)->putJson('/workspace/users/'.$admin->id.'/status', ['is_active' => false])->assertUnprocessable();
        $this->putJson('/workspace/users/'.$member->id, ['name' => 'Edited', 'email' => 'changed@example.test'])->assertUnprocessable();
        $this->putJson('/workspace/users/'.$member->id, ['name' => 'Edited', 'email' => 'changed@example.test', 'current_password' => 'password1', 'role' => 'admin'])->assertOk()->assertJsonPath('role', 'member')->assertJsonPath('email', 'changed@example.test');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_disable_cancels_queued_mail_and_reactivation_never_restores_old_sessions(): void
    {
        [$admin, $member] = $this->accounts();
        $box = Mailbox::create(['company_id' => $admin->company_id, 'name' => 'Contact', 'email' => 'contact@example.test', 'password' => 'secret', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test']);
        $box->users()->attach($member);
        $queued = Message::create(['mailbox_id' => $box->id, 'sender_user_id' => $member->id, 'direction' => 'outbound', 'status' => 'queued', 'from_address' => $box->email, 'to' => ['client@example.test'], 'subject' => 'Queued', 'body' => 'Text', 'message_at' => now()]);
        $sent = $queued->replicate(); $sent->status = 'sent'; $sent->save();
        $token = Password::createToken($member);
        $this->actingAs($admin)->putJson('/workspace/users/'.$member->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame('failed', $queued->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertDatabaseHas('mailbox_user', ['mailbox_id' => $box->id, 'user_id' => $member->id]);
        $this->assertFalse(Password::tokenExists($member, $token));
        $this->putJson('/workspace/users/'.$member->id.'/permissions', ['permissions' => ['read_emails']])->assertOk()->assertJsonPath('is_active', false);
        $gateway = Mockery::mock(MailGateway::class);
        $gateway->shouldNotReceive('send'); $gateway->shouldNotReceive('archive');
        (new SendMessageJob($queued->id))->handle($gateway);
        // A submission already in flight when the account was disabled can enqueue afterwards.
        $late = $queued->replicate(); $late->status = 'queued'; $late->save();
        (new SendMessageJob($late->id))->handle($gateway);
        $this->assertSame('failed', $late->fresh()->status);
        $this->actingAs($member->fresh())->getJson('/auth/me')->assertUnauthorized();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'password1'])->assertUnprocessable();
        $this->actingAs($admin)->withSession(['session_version' => 0])->putJson('/workspace/users/'.$member->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->actingAs($member->fresh())->withSession(['session_version' => 0])->getJson('/auth/me')->assertUnauthorized();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'password1'])->assertOk();
        $this->getJson('/auth/me')->assertOk();
    }

    public function test_recovery_is_generic_and_sends_only_to_active_accounts_with_a_trusted_url(): void
    {
        Notification::fake();
        [$admin, $member] = $this->accounts();
        $member->forceFill(['is_active' => false])->save();
        config(['app.url' => 'https://maildesk.example.test']);
        $expected = $this->postJson('/auth/forgot-password', ['email' => $admin->email])->assertOk()->json();
        $this->postJson('/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertExactJson($expected);
        $this->postJson('/auth/forgot-password', ['email' => $member->email])->assertOk()->assertExactJson($expected);
        $this->postJson('/auth/forgot-password', ['email' => $admin->email])->assertOk()->assertExactJson($expected);
        Notification::assertSentToTimes($admin, ResetPassword::class, 1);
        Notification::assertNotSentTo($member, ResetPassword::class);
        Notification::assertSentTo($admin, ResetPassword::class, function ($notification) use ($admin) {
            $url = $notification->toMail($admin)->actionUrl;
            $this->assertStringStartsWith('https://maildesk.example.test/reset-password/', $url);
            $this->assertStringContainsString(rawurlencode($admin->email), $url);

            return true;
        });
        $this->assertNotSame(Notification::sent($admin, ResetPassword::class)->first()->token, DB::table('password_reset_tokens')->where('email', $admin->email)->value('token'));
    }

    public function test_reset_token_expires_is_single_use_and_revokes_existing_sessions(): void
    {
        Notification::fake();
        [$admin, $member] = $this->accounts();
        $token = Password::createToken($member);
        $data = ['email' => $member->email, 'token' => $token, 'password' => 'new-password1', 'password_confirmation' => 'new-password1'];
        $this->postJson('/auth/reset-password', array_replace($data, ['token' => 'wrong']))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/auth/reset-password', $data)->assertUnprocessable();
        $this->travelBack();
        $this->postJson('/auth/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('new-password1', $member->fresh()->password));
        $this->postJson('/auth/reset-password', $data)->assertUnprocessable();
        $this->actingAs($member->fresh())->withSession(['session_version' => 0])->getJson('/auth/me')->assertUnauthorized();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'new-password1'])->assertOk();
        $this->getJson('/auth/me')->assertOk();
    }

    public function test_inactive_account_cannot_reset_password_and_duplicate_emails_are_rejected(): void
    {
        [$admin, $member] = $this->accounts();
        $token = Password::createToken($member);
        $member->forceFill(['is_active' => false])->save();
        $this->postJson('/auth/reset-password', ['email' => $member->email, 'token' => $token, 'password' => 'new-password1', 'password_confirmation' => 'new-password1'])->assertUnprocessable();
        $this->assertTrue(Hash::check('password1', $member->fresh()->password));
        $this->actingAs($admin)->putJson('/account/profile', ['name' => 'Admin', 'email' => $member->email, 'current_password' => 'password1'])->assertUnprocessable();
    }

    public function test_password_recovery_keeps_csrf_protection_and_does_not_cache_token_page(): void
    {
        Notification::fake();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->postJson('/auth/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(419);
        $this->postJson('/auth/reset-password', [])->assertStatus(419);
        $page = $this->get('/reset-password/test-token?email=unknown%40example.test')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->withHeader('X-XSRF-TOKEN', $page->getCookie('XSRF-TOKEN', false)->getValue())
            ->postJson('/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
    }
}
