<?php

namespace App\Support\Privacy;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Politique de confidentialité (RGPD, art. 13) : la page elle-même vit dans la SPA (`privacy.policy.url`), le backend
 * garde la version dont chaque personne a pris connaissance. Ce n'est pas un consentement (les traitements reposent
 * sur la mission de la commune) : le refus n'est pas une option, l'app ne bloque rien, la SPA affiche la politique
 * tant que `must_accept` est vrai.
 *
 * Changer PRIVACY_POLICY_VERSION (ex. date de la nouvelle version) redemande la prise de connaissance à tous.
 */
class PrivacyPolicy
{
    /** @return array{version: string, url: string} */
    public function current(): array
    {
        return [
            'version' => (string) config('privacy.policy.version'),
            'url' => (string) config('privacy.policy.url'),
        ];
    }

    /** @return array{version: string, url: string, accepted_version: ?string, accepted_at: ?string, must_accept: bool} */
    public function statusFor(User $user): array
    {
        return [
            ...$this->current(),
            'accepted_version' => $user->privacy_policy_version,
            'accepted_at' => $user->privacy_policy_accepted_at?->toISOString(),
            'must_accept' => $user->privacy_policy_version !== $this->current()['version'],
        ];
    }

    /** @throws ValidationException version différente de la version en vigueur (page périmée côté SPA) */
    public function accept(User $user, string $version): void
    {
        if ($version !== $this->current()['version']) {
            throw ValidationException::withMessages([
                'version' => 'La politique de confidentialité a changé : rechargez la page pour lire la version en vigueur.',
            ]);
        }

        $user->forceFill([
            'privacy_policy_version' => $version,
            'privacy_policy_accepted_at' => now(),
        ])->save();
    }
}
