<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class WorkspaceController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate(['company' => 'required|string|max:150', 'name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(8)]]);
        $user = DB::transaction(function () use ($data) {
            $company = Company::create(['name' => $data['company']]);

            return User::create(['company_id' => $company->id, 'role' => 'admin', 'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('session_version', $user->session_version);

        return $this->me($request);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials + ['is_active' => true])) {
            return response()->json(['message' => 'Adresse email ou mot de passe incorrect.'], 422);
        }
        $request->session()->regenerate();
        $request->session()->put('session_version', Auth::user()->session_version);

        return $this->me($request);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('company');

        return response()->json($user);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin' && $request->user()->company_id !== null, 403);
    }

    private function permission(Request $request, string $permission): void
    {
        abort_unless($request->user()->company_id !== null && $request->user()->hasPermission($permission), 403);
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->company_id !== null && ($request->user()->hasPermission('manage_users') || $request->user()->hasPermission('manage_mailboxes')), 403);

        return User::where('company_id', $request->user()->company_id)->select('id', 'name', 'email', 'role', 'permissions', 'is_active')->orderBy('name')->get();
    }

    public function createUser(Request $request)
    {
        $this->permission($request, 'manage_users');
        $data = $request->validate([
            'name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users',
            'password' => ['required', Password::min(8)], 'role' => ['required', Rule::in(['admin', 'member'])],
            'permissions' => 'sometimes|array', 'permissions.*' => ['required', 'string', 'distinct', Rule::in(User::PERMISSIONS)],
        ]);
        $isAdmin = $request->user()->role === 'admin';
        abort_if(! $isAdmin && ($data['role'] !== 'member' || ! empty($data['permissions'])), 403, 'Seul un administrateur peut attribuer des rôles ou des permissions.');
        $data['permissions'] = $isAdmin && $data['role'] === 'member' ? ($data['permissions'] ?? User::DEFAULT_PERMISSIONS) : [];

        return User::create($data + ['company_id' => $request->user()->company_id]);
    }

    public function updatePermissions(Request $request, User $user)
    {
        $this->admin($request);
        abort_unless($request->user()->company_id !== null && $user->company_id === $request->user()->company_id, 404);
        abort_if($user->role === 'admin', 422, 'Les administrateurs disposent déjà de toutes les permissions.');
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(User::PERMISSIONS)],
        ]);
        $user->update($data);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'permissions', 'is_active']));
    }

    public function mailboxes(Request $request)
    {
        $user = $request->user();
        $query = Mailbox::where('company_id', $user->company_id);
        if (! $user->hasPermission('manage_mailboxes')) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query->with('users:id,name,email')->orderBy('name')->get();
    }

    public function createMailbox(Request $request)
    {
        $this->permission($request, 'manage_mailboxes');
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'sent_folder' => ['sometimes', 'required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
            'name' => 'required|string|max:150', 'email' => ['required', 'email', 'max:255', Rule::unique('mailboxes')->where('company_id', $companyId)],
            'imap_host' => 'required|string|max:253', 'imap_port' => 'required|integer|in:993',
            'smtp_host' => 'required|string|max:253', 'smtp_port' => 'required|integer|in:465,587', 'password' => 'required|string|max:1024',
            'user_ids' => 'array', 'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
        ]);

        return DB::transaction(function () use ($data, $companyId) {
            $ids = $data['user_ids'] ?? [];
            unset($data['user_ids']);
            $mailbox = Mailbox::create($data + ['company_id' => $companyId]);
            $mailbox->users()->sync($ids);

            return $mailbox->load('users:id,name,email');
        });
    }

    public function updateMailbox(Request $request, Mailbox $mailbox)
    {
        $this->permission($request, 'manage_mailboxes');
        abort_unless($mailbox->company_id === $request->user()->company_id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:255', Rule::unique('mailboxes')->where('company_id', $mailbox->company_id)->ignore($mailbox->id)],
            'imap_host' => 'required|string|max:253', 'imap_port' => 'required|integer|in:993',
            'smtp_host' => 'required|string|max:253', 'smtp_port' => 'required|integer|in:465,587',
            'password' => 'nullable|string|max:1024', 'sent_folder' => ['sometimes', 'required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);
        // Changing the underlying account would invalidate cached messages and their audit trail.
        abort_unless(strcasecmp($data['email'], $mailbox->email) === 0, 422, 'Créez une nouvelle boîte pour une autre adresse email.');
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $mailbox->update($data);

        return $mailbox->load('users:id,name,email');
    }

    public function updateAccess(Request $request, Mailbox $mailbox)
    {
        $this->permission($request, 'manage_mailboxes');
        abort_unless($mailbox->company_id === $request->user()->company_id, 404);
        $data = $request->validate(['user_ids' => 'present|array', 'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $request->user()->company_id)]]);
        $mailbox->users()->sync($data['user_ids']);

        return $mailbox->load('users:id,name,email');
    }
}
