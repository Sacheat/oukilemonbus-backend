<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'amorçage de l'application.
 *
 * Surcharge le lien de vérification e-mail de Laravel : l'email pointe vers la SPA
 * (FRONTEND_URL + frontend_email_verification_path) avec id, hash, expires et signature
 * en query string. La SPA appelle ensuite GET /api/email/verify/{id}/{hash}?expires=&signature=.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $id = $notifiable->getKey();
            $hash = sha1($notifiable->getEmailForVerification());

            // Signature relative : valide quel que soit l'hôte par lequel la SPA joint l'API (proxy, domaine).
            $signedPath = URL::temporarySignedRoute(
                'api.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $id, 'hash' => $hash],
                absolute: false
            );

            parse_str((string) parse_url($signedPath, PHP_URL_QUERY), $signature);

            return config('app.frontend_url')
                .config('app.frontend_email_verification_path')
                .'?'.http_build_query(['id' => $id, 'hash' => $hash, ...$signature]);
        });
    }
}
