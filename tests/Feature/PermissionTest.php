<?php

namespace Tests\Feature;

use App\Contracts\MailGateway;
use App\Jobs\SendMessageJob;
use App\Jobs\SyncMailboxJob;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(array $permissions = [], string $role = 'member'): array
    {
        $company = Company::create(['name' => 'A']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $member = User::factory()->create(['company_id' => $company->id, 'role' => $role, 'permissions' => array_values(array_unique(array_merge(User::DEFAULT_PERMISSIONS, $permissions)))]);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'password' => 'test-secret', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test']);
        $box->users()->attach($member);
        $message = Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $admin->id, 'from_address' => $box->email, 'to' => ['client@example.test'], 'subject' => 'Test', 'body' => 'Bonjour', 'message_at' => now()]);
        $message->readers()->attach($admin->id, ['read_at' => now()]);

        return [$admin, $member, $box, $message];
    }

    public static function activityPermissions(): array
    {
        return [
            'none' => [[], false, false, 'member'],
            'sender only' => [['view_send_activity'], true, false, 'member'],
            'readers only' => [['view_read_activity'], false, true, 'member'],
            'both' => [['view_send_activity', 'view_read_activity'], true, true, 'member'],
            'admin implicitly has both' => [[], true, true, 'admin'],
        ];
    }

    #[DataProvider('activityPermissions')]
    public function test_activity_permissions_are_enforced_on_details_read_and_list(array $permissions, bool $sender, bool $readers, string $role): void
    {
        [$admin, $member, $box, $message] = $this->workspace($permissions, $role);
        $this->actingAs($member);
        foreach ([$this->getJson('/workspace/messages/'.$message->id), $this->postJson('/workspace/messages/'.$message->id.'/read')] as $response) {
            $response->assertOk();
            if ($sender) {
                $response->assertJsonPath('sender.id', $admin->id)->assertJsonPath('sender_user_id', $admin->id);
            } else {
                $response->assertJsonMissingPath('sender')->assertJsonMissingPath('sender_user_id');
            }
            if ($readers) {
                $this->assertContains($admin->id, array_column($response->json('readers'), 'id'));
            } else {
                $response->assertJsonMissingPath('readers');
            }
        }
        $list = $this->getJson('/workspace/mailboxes/'.$box->id.'/messages?direction=outbound')->assertOk()->assertJsonPath('data.0.read_by_me', true);
        if ($sender) {
            $list->assertJsonPath('data.0.sender.id', $admin->id);
        } else {
            $list->assertJsonMissingPath('data.0.sender')->assertJsonMissingPath('data.0.sender_user_id');
        }
        $this->assertDatabaseCount('message_reads', 2);
    }

    public function test_only_company_admin_can_grant_and_revoke_permissions(): void
    {
        [$admin, $member, $box, $message] = $this->workspace();
        $foreignCompany = Company::create(['name' => 'B']);
        $foreign = User::factory()->create(['company_id' => $foreignCompany->id, 'role' => 'admin']);
        $url = '/workspace/users/'.$member->id.'/permissions';
        $this->putJson($url, ['permissions' => User::PERMISSIONS])->assertUnauthorized();
        $this->actingAs($member)->putJson($url, ['permissions' => User::PERMISSIONS])->assertForbidden();
        $this->actingAs($foreign)->putJson($url, ['permissions' => User::PERMISSIONS])->assertNotFound();
        $this->actingAs($admin)->putJson($url, ['permissions' => User::PERMISSIONS, 'role' => 'admin'])->assertOk()->assertJsonPath('role', 'member')->assertJsonPath('permissions', User::PERMISSIONS);
        $this->getJson('/workspace/users')->assertOk()->assertJsonFragment(['permissions' => User::PERMISSIONS]);
        $this->getJson('/auth/me')->assertOk()->assertJsonPath('role', 'admin');
        $this->actingAs($member->fresh())->getJson('/workspace/messages/'.$message->id)->assertOk()->assertJsonPath('sender.id', $admin->id)->assertJsonCount(1, 'readers');
        $this->getJson('/auth/me')->assertOk()->assertJsonPath('permissions', User::PERMISSIONS);
        $this->actingAs($admin)->putJson($url, ['permissions' => User::DEFAULT_PERMISSIONS])->assertOk()->assertJsonPath('permissions', User::DEFAULT_PERMISSIONS);
        $this->actingAs($member->fresh())->getJson('/workspace/messages/'.$message->id)->assertOk()->assertJsonMissingPath('sender')->assertJsonMissingPath('readers');
    }

    public function test_permissions_are_validated_and_do_not_grant_admin_or_mailbox_access(): void
    {
        [$admin, $member, $box, $message] = $this->workspace(['view_send_activity', 'view_read_activity']);
        $url = '/workspace/users/'.$member->id.'/permissions';
        foreach ([[], ['permissions' => 'all'], ['permissions' => ['not_a_permission']], ['permissions' => ['view_read_activity', 'view_read_activity']]] as $payload) {
            $this->actingAs($admin)->putJson($url, $payload)->assertUnprocessable();
        }
        $this->putJson('/workspace/users/'.$admin->id.'/permissions', ['permissions' => []])->assertUnprocessable();
        $this->actingAs($member)->getJson('/workspace/dashboard')->assertForbidden();
        $this->getJson('/workspace/admin/activity')->assertForbidden();
        $this->getJson('/workspace/users')->assertForbidden();
        $box->users()->detach($member);
        $this->getJson('/workspace/messages/'.$message->id)->assertNotFound();
        $this->postJson('/workspace/messages/'.$message->id.'/read')->assertNotFound();
        $this->getJson('/workspace/mailboxes/'.$box->id.'/messages')->assertNotFound();
    }

    public function test_admin_can_set_permissions_when_creating_a_member(): void
    {
        [$admin] = $this->workspace();
        $this->actingAs($admin)->postJson('/workspace/users', ['name' => 'New member', 'email' => 'new@example.test', 'password' => 'password1', 'role' => 'member', 'permissions' => ['view_read_activity']])->assertCreated()->assertJsonPath('permissions', ['view_read_activity']);
        $this->postJson('/workspace/users', ['name' => 'Invalid', 'email' => 'invalid@example.test', 'password' => 'password1', 'role' => 'member', 'permissions' => ['not_a_permission']])->assertUnprocessable();
    }

    public function test_send_and_idempotent_replay_do_not_leak_the_actor_without_permission(): void
    {
        Queue::fake();
        [$admin, $member, $box] = $this->workspace();
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'Hello', 'body' => 'Bonjour'];
        $url = '/workspace/mailboxes/'.$box->id.'/messages';
        $this->actingAs($member)->postJson($url, $payload)->assertStatus(202)->assertJsonMissingPath('sender')->assertJsonMissingPath('sender_user_id');
        $this->postJson($url, $payload)->assertOk()->assertJsonMissingPath('sender')->assertJsonMissingPath('sender_user_id');
        $this->assertSame($member->id, Message::where('request_id', $payload['request_id'])->firstOrFail()->sender_user_id);
        Queue::assertPushed(SendMessageJob::class, 1);
    }

    public function test_legacy_defaults_preserve_basic_email_access_but_an_empty_grant_revokes_it(): void
    {
        [$admin, $member] = $this->workspace();
        $member->update(['permissions' => null]);
        foreach (User::PERMISSIONS as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
            $this->assertSame(in_array($permission, User::DEFAULT_PERMISSIONS, true), $member->hasPermission($permission));
        }
        $member->update(['permissions' => []]);
        $this->assertFalse($member->hasPermission('read_emails'));
        $this->assertFalse($member->hasPermission('send_emails'));
        $this->assertFalse($admin->hasPermission('unknown'));
    }

    public function test_read_send_and_sync_are_separate_permissions_enforced_by_the_api(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$admin, $member, $box, $message] = $this->workspace();
        Storage::disk('local')->put('qa/file', 'test');
        $attachment = $message->attachments()->create(['name' => 'test.txt', 'path' => 'qa/file', 'size' => 4]);
        $member->update(['permissions' => []]);
        $url = '/workspace/mailboxes/'.$box->id.'/messages';
        $payload = ['request_id' => (string) Str::uuid(), 'to' => ['client@example.test'], 'subject' => 'New', 'body' => 'Body'];
        $this->actingAs($member);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/workspace/messages/'.$message->id)->assertForbidden();
        $this->postJson('/workspace/messages/'.$message->id.'/read')->assertForbidden();
        $this->getJson('/workspace/attachments/'.$attachment->id)->assertForbidden();
        $this->postJson($url, $payload)->assertForbidden();
        $this->postJson('/workspace/mailboxes/'.$box->id.'/sync')->assertForbidden();
        $member->update(['permissions' => ['read_emails']]);
        $this->getJson($url)->assertOk();
        $this->getJson('/workspace/messages/'.$message->id)->assertOk();
        $this->get('/workspace/attachments/'.$attachment->id)->assertOk()->assertDownload('test.txt');
        $this->postJson($url, $payload)->assertForbidden();
        $member->update(['permissions' => ['send_emails']]);
        $this->postJson($url, $payload)->assertStatus(202);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url, $payload + ['reply_to_id' => $message->id])->assertForbidden();
        $this->postJson($url, $payload + ['forward_attachment_ids' => [$attachment->id]])->assertForbidden();
        $member->update(['permissions' => ['sync_mailboxes']]);
        $this->postJson('/workspace/mailboxes/'.$box->id.'/sync')->assertStatus(202);
        Queue::assertPushed(SyncMailboxJob::class, 1);
        Queue::assertPushed(SendMessageJob::class, 1);
    }

    public function test_revoked_send_permission_cancels_an_already_queued_message(): void
    {
        [$admin, $member, $box, $message] = $this->workspace();
        $message->update(['status' => 'queued', 'sender_user_id' => $member->id]);
        $member->update(['permissions' => ['read_emails']]);
        $gateway = Mockery::mock(MailGateway::class);
        $gateway->shouldNotReceive('send');
        $gateway->shouldNotReceive('archive');
        (new SendMessageJob($message->id))->handle($gateway);
        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_delegated_user_management_cannot_promote_or_assign_permissions(): void
    {
        [$admin, $member] = $this->workspace();
        $member->update(['permissions' => ['manage_users']]);
        $payload = ['name' => 'Delegated creation', 'email' => 'delegated@example.test', 'password' => 'password1', 'role' => 'member'];
        $this->actingAs($member)->getJson('/workspace/users')->assertOk();
        $this->postJson('/workspace/users', array_replace($payload, ['role' => 'admin']))->assertForbidden();
        $this->postJson('/workspace/users', $payload + ['permissions' => ['read_emails']])->assertForbidden();
        $this->postJson('/workspace/users', $payload)->assertCreated()->assertJsonPath('role', 'member')->assertJsonPath('permissions', []);
        $this->putJson('/workspace/users/'.$member->id.'/permissions', ['permissions' => User::PERMISSIONS])->assertForbidden();
        $this->postJson('/workspace/mailboxes', [])->assertForbidden();
        $this->getJson('/workspace/dashboard')->assertForbidden();
    }

    public function test_mailbox_management_is_company_scoped_and_does_not_bypass_message_access(): void
    {
        [$admin, $member, $box, $message] = $this->workspace();
        $member->update(['permissions' => ['manage_mailboxes', 'read_emails']]);
        $payload = ['name' => 'Second', 'email' => 'second@example.test', 'imap_host' => 'mail.example.test', 'imap_port' => 993, 'smtp_host' => 'mail.example.test', 'smtp_port' => 587, 'password' => 'test-secret', 'user_ids' => []];
        $this->actingAs($member)->getJson('/workspace/users')->assertOk();
        $created = $this->postJson('/workspace/mailboxes', $payload)->assertCreated()->json('id');
        $this->getJson('/workspace/mailboxes')->assertJsonCount(2);
        $this->putJson('/workspace/mailboxes/'.$created, array_replace($payload, ['name' => 'Updated']))->assertOk()->assertJsonPath('name', 'Updated');
        $this->getJson('/workspace/mailboxes/'.$created.'/messages')->assertNotFound();
        $foreign = User::factory()->create(['company_id' => Company::create(['name' => 'Foreign'])->id, 'role' => 'admin']);
        $this->putJson('/workspace/mailboxes/'.$created.'/access', ['user_ids' => [$foreign->id]])->assertUnprocessable();
        $this->putJson('/workspace/mailboxes/'.$created.'/access', ['user_ids' => [$member->id]])->assertOk();
        $this->getJson('/workspace/mailboxes/'.$created.'/messages')->assertOk();
        $this->postJson('/workspace/users', [])->assertForbidden();
        $this->actingAs($foreign)->putJson('/workspace/mailboxes/'.$created, $payload)->assertNotFound();
    }

    public function test_delegated_dashboard_sees_only_assigned_boxes_and_masks_activity_without_grants(): void
    {
        [$admin, $member, $box, $message] = $this->workspace();
        $member->update(['permissions' => ['view_dashboard']]);
        $secondBox = $box->replicate();
        $secondBox->email = 'second@example.test';
        $secondBox->save();
        $hidden = $message->replicate();
        $hidden->mailbox_id = $secondBox->id;
        $hidden->save();
        $this->actingAs($member)->getJson('/workspace/dashboard')->assertOk()->assertJsonPath('stats.sent', 1)->assertJsonPath('stats.mailboxes', 1)->assertJsonPath('team', []);
        $this->getJson('/workspace/admin/activity')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.sender')->assertJsonMissingPath('data.0.sender_user_id')->assertJsonMissingPath('data.0.readers');
        $this->getJson('/workspace/admin/activity?user_id='.$admin->id)->assertForbidden();
        $this->getJson('/workspace/dashboard?mailbox_id='.$secondBox->id)->assertUnprocessable();
        $this->getJson('/workspace/messages/'.$message->id)->assertForbidden();
        $member->update(['permissions' => ['view_dashboard', 'view_read_activity']]);
        $this->getJson('/workspace/dashboard')->assertOk()->assertJsonMissingPath('team.0.sent_count')->assertJsonStructure(['team' => [['read_count']]]);
        $this->getJson('/workspace/admin/activity')->assertOk()->assertJsonMissingPath('data.0.sender')->assertJsonCount(1, 'data.0.readers');
        $readerOnly = User::factory()->create(['company_id' => $box->company_id, 'role' => 'member']);
        $message->readers()->attach($readerOnly->id, ['read_at' => now()]);
        $member->update(['permissions' => ['view_dashboard', 'view_send_activity']]);
        $this->getJson('/workspace/admin/activity?user_id='.$readerOnly->id)->assertOk()->assertJsonPath('total', 0);
    }
}
