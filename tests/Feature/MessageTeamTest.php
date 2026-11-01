<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_workflow_is_scoped_to_authorized_mailbox_readers(): void
    {
        $company = Company::create(['name' => 'A']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $member = User::factory()->create(['company_id' => $company->id, 'role' => 'member']);
        $noRead = User::factory()->create(['company_id' => $company->id, 'role' => 'member', 'permissions' => ['send_emails']]);
        $other = User::factory()->create(['company_id' => Company::create(['name' => 'B'])->id, 'role' => 'admin']);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'password' => 'secret', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test']);
        $box->users()->attach([$member->id, $noRead->id]);
        $message = Message::create(['mailbox_id' => $box->id, 'direction' => 'inbound', 'status' => 'received', 'from_address' => 'client@example.test', 'to' => [$box->email], 'subject' => 'Test', 'body' => 'Bonjour', 'message_at' => now()]);
        $url = '/workspace/messages/'.$message->id;
        $this->actingAs($member)->getJson($url.'/team')->assertOk()->assertJsonPath('status', 'open')->assertJsonCount(2, 'members');
        $this->putJson($url.'/team', ['status' => 'in_progress', 'assigned_user_id' => $noRead->id])->assertUnprocessable();
        $this->putJson($url.'/team', ['status' => 'done', 'assigned_user_id' => $admin->id])->assertOk()->assertJsonPath('assigned_user_id', $admin->id);
        $this->postJson($url.'/notes', ['body' => 'À rappeler'])->assertCreated()->assertJsonPath('author.id', $member->id);
        $this->actingAs($admin)->getJson($url.'/team')->assertOk()->assertJsonPath('notes.0.body', 'À rappeler');
        $this->actingAs($other)->getJson($url.'/team')->assertNotFound();
        $this->postJson($url.'/notes', ['body' => 'Interdit'])->assertNotFound();
        $this->actingAs($noRead)->getJson($url.'/team')->assertForbidden();
        $this->assertDatabaseCount('message_notes', 1);
    }
}
