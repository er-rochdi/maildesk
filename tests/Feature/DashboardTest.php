<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $company = Company::create(['name' => 'A']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $member = User::factory()->create(['company_id' => $company->id, 'role' => 'member']);
        $foreign = User::factory()->create(['company_id' => Company::create(['name' => 'B'])->id, 'role' => 'admin']);
        $makeBox = fn ($user, $email) => Mailbox::create(['company_id' => $user->company_id, 'name' => 'Contact', 'email' => $email, 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test', 'password' => 'private-password']);
        $box = $makeBox($admin, 'contact@example.test');
        $secondBox = $makeBox($admin, 'support@example.test');
        $foreignBox = $makeBox($foreign, 'foreign@example.test');
        $box->users()->attach($member);

        return [$admin, $member, $foreign, $box, $secondBox, $foreignBox];
    }

    private function message(Mailbox $box, array $data = []): Message
    {
        return Message::create(array_merge(['mailbox_id' => $box->id, 'direction' => 'inbound', 'status' => 'received', 'from_address' => 'client@example.test', 'to' => [$box->email], 'subject' => 'Email test', 'body' => 'Private message body', 'message_at' => now()], $data));
    }

    public function test_company_totals_period_and_team_counts_are_accurate_without_multiplying_reads(): void
    {
        [$admin, $member, $foreign, $box, $secondBox, $foreignBox] = $this->workspace();
        $read = $this->message($box);
        $this->message($box);
        $read->readers()->attach([$admin->id => ['read_at' => now()], $member->id => ['read_at' => now()]]);
        $this->message($box, ['direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $member->id, 'sent_at' => now(), 'delivery_status' => 'delivered']);
        $this->message($box, ['direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $admin->id, 'sent_at' => now(), 'delivery_status' => 'failed', 'delivery_error' => 'Bounce']);
        $this->message($box, ['direction' => 'outbound', 'status' => 'uncertain', 'sender_user_id' => $admin->id]);
        $this->message($box, ['direction' => 'outbound', 'status' => 'queued', 'sender_user_id' => $admin->id]);
        $this->message($box, ['direction' => 'outbound', 'status' => 'failed', 'sender_user_id' => $admin->id]);
        $this->message($secondBox, ['direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $admin->id, 'sent_at' => now()->subDays(100), 'message_at' => now()->subDays(100)]);
        $this->message($foreignBox, ['direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $foreign->id, 'sent_at' => now(), 'delivery_status' => 'delivered']);

        $response = $this->actingAs($admin)->getJson('/workspace/dashboard')->assertOk()
            ->assertJsonPath('stats.sent', 3)->assertJsonPath('stats.received', 2)->assertJsonPath('stats.unopened', 1)
            ->assertJsonPath('stats.issues', 3)->assertJsonPath('stats.pending', 1)->assertJsonPath('stats.delivered', 1)
            ->assertJsonPath('stats.mailboxes', 2)->assertJsonPath('stats.users', 2)->assertJsonCount(14, 'trend');
        $team = collect($response->json('team'))->keyBy('id');
        $this->assertSame(2, $team[$admin->id]['sent_count']);
        $this->assertSame(1, $team[$member->id]['sent_count']);
        $this->assertSame(1, $team[$admin->id]['read_count']);
        $this->assertSame(1, $team[$member->id]['read_count']);
        $this->assertSame(2, collect($response->json('trend'))->sum('sent'));
        $this->assertSame(2, collect($response->json('trend'))->sum('received'));
        $this->getJson('/workspace/dashboard?period=7&mailbox_id='.$box->id)->assertOk()->assertJsonPath('stats.sent', 2)->assertJsonCount(7, 'trend');
        $this->getJson('/workspace/dashboard?period=7&mailbox_id='.$secondBox->id)->assertOk()->assertJsonPath('stats.sent', 0)->assertJsonPath('stats.received', 0);
        $this->assertDatabaseCount('message_reads', 2);
    }

    public function test_journal_exposes_sender_readers_and_first_open_time_without_marking_messages_read(): void
    {
        [$admin, $member, $foreign, $box, $secondBox, $foreignBox] = $this->workspace();
        $read = $this->message($box, ['subject' => 'Facture client']);
        $openedAt = now()->subMinutes(5)->setMicrosecond(0);
        $read->readers()->attach([$admin->id => ['read_at' => now()], $member->id => ['read_at' => $openedAt]]);
        $unread = $this->message($box, ['subject' => 'Non ouvert']);
        $sent = $this->message($box, ['direction' => 'outbound', 'status' => 'sent', 'sender_user_id' => $member->id, 'sent_at' => now(), 'subject' => 'Reponse client']);
        $this->message($foreignBox, ['subject' => 'Foreign secret']);
        $response = $this->actingAs($admin)->getJson('/workspace/admin/activity?user_id='.$member->id)->assertOk()->assertJsonPath('total', 2);
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame($member->id, $rows[$sent->id]['sender']['id']);
        $this->assertCount(2, $rows[$read->id]['readers']);
        $this->assertSame($member->id, $rows[$read->id]['readers'][0]['id']);
        $reader = collect($rows[$read->id]['readers'])->firstWhere('id', $member->id);
        $this->assertSame($openedAt->toISOString(), $reader['pivot']['read_at']);
        $response->assertJsonMissingPath('data.0.body')->assertJsonMissingPath('data.0.mailbox.password');
        $this->getJson('/workspace/admin/activity?kind=unread')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $unread->id);
        $this->getJson('/workspace/admin/activity?kind=sent')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $sent->id);
        $this->getJson('/workspace/admin/activity?q=Facture')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $read->id);
        $this->assertSame(2, DB::table('message_reads')->count());
        $this->assertSame($openedAt->toDateTimeString(), DB::table('message_reads')->where('message_id', $read->id)->where('user_id', $member->id)->value('read_at'));
    }

    public function test_dashboard_requires_an_admin_and_rejects_foreign_company_filters(): void
    {
        [$admin, $member, $foreign, $box, $secondBox, $foreignBox] = $this->workspace();
        foreach (['/workspace/dashboard', '/workspace/admin/activity'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['/workspace/dashboard', '/workspace/admin/activity'] as $url) {
            $this->actingAs($member)->getJson($url)->assertForbidden();
            $this->actingAs($admin)->getJson($url.'?mailbox_id='.$foreignBox->id)->assertUnprocessable();
            $this->getJson($url.'?period=invalid')->assertUnprocessable();
        }
        $this->getJson('/workspace/admin/activity?user_id='.$foreign->id)->assertUnprocessable();
        $this->getJson('/workspace/admin/activity?kind=invalid')->assertUnprocessable();
        $unassignedAdmin = User::factory()->create(['company_id' => null, 'role' => 'admin']);
        $this->actingAs($unassignedAdmin)->getJson('/workspace/dashboard')->assertForbidden();
        $this->getJson('/workspace/admin/activity')->assertForbidden();
    }

    public function test_journal_paginates_without_losing_or_repeating_messages(): void
    {
        [$admin, $member, $foreign, $box] = $this->workspace();
        for ($i = 0; $i < 21; $i++) {
            $this->message($box, ['subject' => 'Email '.$i]);
        }
        $first = $this->actingAs($admin)->getJson('/workspace/admin/activity?page=1')->assertOk()->assertJsonPath('total', 21)->assertJsonPath('last_page', 2)->assertJsonCount(20, 'data');
        $second = $this->getJson('/workspace/admin/activity?page=2')->assertOk()->assertJsonCount(1, 'data');
        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertCount(21, array_unique($ids));
    }
}
