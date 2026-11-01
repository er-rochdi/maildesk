<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Contracts\MailGateway::class, \App\Services\HestiaMailGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $resetUrl = fn (User $user, string $token) => rtrim(config('app.url'), '/').'/reset-password/'.rawurlencode($token).'?'.http_build_query(['email' => $user->getEmailForPasswordReset()]);
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn (User $user, string $token) => (new MailMessage)
            ->subject('Réinitialiser votre mot de passe Maildesk')->greeting('Bonjour,')
            ->line('Vous avez demandé la réinitialisation de votre mot de passe Maildesk.')
            ->action('Réinitialiser mon mot de passe', $resetUrl($user, $token))
            ->line('Ce lien expire dans '.config('auth.passwords.users.expire').' minutes.')
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez cet email.'));
    }
}
