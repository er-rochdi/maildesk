<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_company_and_admin(): void
    {
        $this->postJson('/auth/register', ['company' => 'Acme', 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('company.name', 'Acme');
        $this->assertAuthenticated();
    }

    public function test_authenticated_writes_require_the_renewed_csrf_cookie(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $page = $this->get('/')->assertOk();
        $initialToken = session()->token();
        $initialCookie = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $login = $this->withHeader('X-XSRF-TOKEN', $initialCookie)->postJson('/auth/register', ['company' => 'Acme', 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk();
        $this->assertNotSame($initialToken, session()->token());

        $payload = ['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password1', 'role' => 'member'];
        $this->withHeader('X-XSRF-TOKEN', $initialCookie)->postJson('/workspace/users', $payload)->assertStatus(419);
        $this->withHeader('X-XSRF-TOKEN', $login->getCookie('XSRF-TOKEN', false)->getValue())->postJson('/workspace/users', $payload)->assertCreated();
    }

    public function test_mailbox_access_is_scoped_to_company_and_assigned_members(): void
    {
        $a = Company::create(['name' => 'A']); $b = Company::create(['name' => 'B']);
        $admin = User::factory()->create(['company_id' => $a->id, 'role' => 'admin']);
        $member = User::factory()->create(['company_id' => $a->id, 'role' => 'member']);
        $other = User::factory()->create(['company_id' => $b->id, 'role' => 'member']);
        $payload = ['name' => 'Contact', 'email' => 'contact@example.test', 'imap_host' => 'mail.example.test', 'imap_port' => 993, 'smtp_host' => 'mail.example.test', 'smtp_port' => 587, 'password' => 'secret-test', 'user_ids' => [$member->id]];
        $response = $this->actingAs($admin)->postJson('/workspace/mailboxes', $payload + ['resend_enabled' => true])->assertCreated()->assertJsonMissing(['password' => 'secret-test']);
        $id = $response->json('id');
        $this->assertFalse(Mailbox::findOrFail($id)->resend_enabled);
        $this->assertNotSame('secret-test', DB::table('mailboxes')->where('id', $id)->value('password'));
        $this->actingAs($member)->getJson('/workspace/mailboxes')->assertOk()->assertJsonCount(1);
        $this->actingAs($member)->postJson('/workspace/users', [])->assertForbidden();
        $this->actingAs($other)->getJson('/workspace/mailboxes')->assertOk()->assertJsonCount(0);
        $this->actingAs($admin)->putJson('/workspace/mailboxes/'.$id.'/access', ['user_ids' => [$other->id]])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/workspace/mailboxes/'.$id.'/access', ['user_ids' => []])->assertOk();
        $this->actingAs($member)->getJson('/workspace/mailboxes')->assertOk()->assertJsonCount(0);
    }
}
