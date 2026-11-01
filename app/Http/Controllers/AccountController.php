<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountController extends Controller
{
    private function identity(Request $request, User $user): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);
        if ($data['email'] !== $user->email) {
            $request->validate(['current_password' => 'required|current_password']);
        }

        return $data;
    }

    private function saveIdentity(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $user->refresh();
            $changedEmail = $user->email !== $data['email'];
            if ($changedEmail) {
                Password::deleteToken($user);
                $user->email_verified_at = null;
            }
            $user->fill($data)->save();
            if ($changedEmail) {
                $user->revokeSessions();
            }
        });
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $data = $this->identity($request, $user);
        $signature = $request->validate(['signature' => 'nullable|string|max:5000']);
        $this->saveIdentity($user, $data + $signature);
        $request->session()->put('session_version', $user->session_version);
        $request->session()->regenerate();

        return $user->load('company');
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(8), 'different:current_password']]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data) {
            Password::deleteToken($user);
            $user->update(['password' => $data['password']]);
            $user->revokeSessions();
        });
        $request->session()->put('session_version', $user->session_version);
        $request->session()->regenerate();

        return response()->json(['message' => 'Mot de passe modifié. Les autres sessions ont été déconnectées.']);
    }

    private function authorizeAdmin(Request $request, User $user): void
    {
        abort_unless($request->user()->role === 'admin' && $request->user()->company_id !== null, 403);
        abort_unless($user->company_id === $request->user()->company_id, 404);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeAdmin($request, $user);
        // The current administrator edits their own account through the profile screen.
        abort_if($request->user()->id === $user->id, 422, 'Modifiez votre compte depuis Mon profil.');
        $this->saveIdentity($user, $this->identity($request, $user));

        return $user;
    }

    public function status(Request $request, User $user)
    {
        $this->authorizeAdmin($request, $user);
        $data = $request->validate(['is_active' => 'required|boolean']);
        abort_if(! $data['is_active'] && $request->user()->id === $user->id, 422, 'Vous ne pouvez pas désactiver votre propre compte.');
        DB::transaction(function () use ($request, $user, $data) {
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()->is_active, 401);
            $user->refresh();
            if ($user->is_active === (bool) $data['is_active']) {
                return;
            }
            if (! $data['is_active'] && $user->role === 'admin') {
                abort_if(User::where('company_id', $user->company_id)->where('role', 'admin')->where('is_active', true)->count() <= 1, 422, 'Conservez au moins un administrateur actif.');
            }
            Password::deleteToken($user);
            $user->forceFill(['is_active' => (bool) $data['is_active']])->save();
            $user->revokeSessions();
            if (! $user->is_active) {
                Message::where('sender_user_id', $user->id)->where('direction', 'outbound')->where('status', 'queued')
                    ->update(['status' => 'failed', 'send_error' => 'Envoi annulé : compte utilisateur désactivé.']);
            }
        });

        return $user;
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink($data + ['is_active' => true]);

        return response()->json(['message' => 'Si un compte actif correspond à cette adresse, un lien de réinitialisation vous sera envoyé.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
        $status = Password::reset($data + ['is_active' => true], function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->update(['password' => $password]);
                $user->revokeSessions();
            });
            event(new PasswordReset($user));
        });
        abort_unless($status === Password::PasswordReset, 422, 'Ce lien est invalide ou a expiré. Demandez un nouveau lien.');

        return response()->json(['message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.']);
    }
}
