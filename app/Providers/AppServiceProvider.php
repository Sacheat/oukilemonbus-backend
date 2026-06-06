<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'amorçage de l'application.
 *
 * Surcharge la génération d'URL de vérification e-mail de Laravel pour pointer
 * vers la route nommée 'api.verification.verify' (préfixée 'api.') plutôt que
 * vers la route web par défaut — requis par l'architecture SPA + API REST.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // La route de vérification est préfixée 'api.' — on surcharge la génération d'URL
        // pour que la notification VerifyEmail pointe vers le bon endpoint.
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'api.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id'   => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
