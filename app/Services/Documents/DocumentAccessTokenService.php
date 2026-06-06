<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Émet et consomme les tokens d'accès à usage unique pour le téléchargement de documents.
 *
 * `issue()` : crée un DocumentAccessToken avec une durée de vie configurable
 *   (notifications.mail.signed_link_expiration_minutes, défaut 24h).
 *
 * `validateAndConsume()` : vérifie le token en quatre étapes :
 *   1. Existence (invalid_token si absent)
 *   2. Non révoqué (revoked_at = null)
 *   3. Non encore utilisé (used_at = null — usage unique)
 *   4. Non expiré (expires_at dans le futur)
 *   Si valide, marque `used_at = now()` (le token ne peut plus être réutilisé).
 *
 * Chaque tentative (succès ou échec) est tracée dans DocumentAccessLog
 * avec IP, user-agent et motif d'échec pour l'audit forensique.
 */
class DocumentAccessTokenService
{
    public function issue(Document $document, ?User $user = null): DocumentAccessToken
    {
        return DocumentAccessToken::create([
            'document_id' => $document->id,
            'user_id' => $user?->id,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes((int) config('notifications.mail.signed_link_expiration_minutes', 60 * 24)),
            'used_at' => null,
            'revoked_at' => null,
        ]);
    }

    public function validateAndConsume(Document $document, string $token, Request $request): bool
    {
        $accessToken = DocumentAccessToken::query()
            ->where('document_id', $document->id)
            ->where('token', $token)
            ->first();

        if ($accessToken === null) {
            $this->log($document, null, false, 'invalid_token', $request);
            return false;
        }

        if ($accessToken->revoked_at !== null) {
            $this->log($document, $accessToken, false, 'revoked_token', $request);
            return false;
        }

        if ($accessToken->used_at !== null) {
            $this->log($document, $accessToken, false, 'already_used', $request);
            return false;
        }

        if ($accessToken->expires_at->isPast()) {
            $this->log($document, $accessToken, false, 'expired_token', $request);
            return false;
        }

        $accessToken->update([
            'used_at' => now(),
        ]);

        $this->log($document, $accessToken, true, null, $request);

        return true;
    }

    private function log(
        Document $document,
        ?DocumentAccessToken $token,
        bool $isSuccess,
        ?string $failureReason,
        Request $request
    ): void {
        DocumentAccessLog::create([
            'document_id' => $document->id,
            'document_access_token_id' => $token?->id,
            'user_id' => $token?->user_id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            'is_success' => $isSuccess,
            'failure_reason' => $failureReason,
        ]);
    }
}

