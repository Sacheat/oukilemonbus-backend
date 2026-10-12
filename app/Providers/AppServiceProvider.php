<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Point d'amorçage de l'application.
 *
 * Surcharge le lien de vérification e-mail de Laravel : l'email pointe vers la SPA
 * (FRONTEND_URL + frontend_email_verification_path) avec id, hash, expires et signature
 * en query string. La SPA appelle ensuite GET /api/email/verify/{id}/{hash}?expires=&signature=.
 * Même principe pour le mot de passe oublié : FRONTEND_URL/reset-password?token=&email=,
 * puis POST /api/auth/reset-password.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Composants Blade des emails : <x-emails::button>, <x-emails::trip-ticket>… (resources/views/emails/components).
        Blade::anonymousComponentPath(resource_path('views/emails/components'), 'emails');

        // Tout job (ou notification, mail en file) qui échoue définitivement, après ses essais, est journalisé :
        // dans jobs.log pour le détail, et dans le log principal pour qu'une supervision le voie.
        Queue::failing(function (JobFailed $event): void {
            $context = [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
                'uuid' => $event->job->uuid(),
                'message' => $event->exception->getMessage(),
            ];

            Log::channel('jobs')->error('queue.job.failed', $context);
            Log::error('queue.job.failed', $context);
        });

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

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return config('app.frontend_url')
                .config('app.frontend_password_reset_path')
                .'?'.http_build_query(['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
        });

        $this->configureRateLimiting();
        $this->configurePasswordRules();
    }

    /**
     * Règle des mots de passe (inscription, création par un admin, changement, réinitialisation) : 12 caractères au
     * moins, avec lettres et chiffres. En production, refus d'un mot de passe connu des fuites (Have I Been Pwned :
     * seuls les 5 premiers caractères du hash SHA-1 partent, voir docs/sous-traitants.md ; si le service ne répond
     * pas, le mot de passe est accepté). Les comptes existants ne sont pas forcés à changer.
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Limiteurs nommés (middleware throttle:consultation, throttle:action…), valeurs dans config/rate_limits.php.
     *
     * Un `throttle:60,1` sans nom compte un seul compteur par utilisateur pour TOUTES les routes limitées : après
     * 20 consultations, un preview (limite 20) était refusé. Ici chaque famille a son compteur ; `consultation` est
     * partagé par les lectures, les autres familles comptent en plus route par route.
     */
    private function configureRateLimiting(): void
    {
        $who = fn (Request $request): string => $request->user()?->getAuthIdentifier() ?? $request->ip();

        RateLimiter::for('consultation', fn (Request $request) => Limit::perMinute(config('rate_limits.consultation'))
            ->by($who($request)));

        foreach (['action', 'verification', 'envoi', 'connexion', 'inscription', 'aide'] as $family) {
            RateLimiter::for($family, fn (Request $request) => Limit::perMinute(config("rate_limits.{$family}"))
                ->by(($request->route()?->getName() ?? $request->path()).'|'.$who($request)));
        }
    }
}
