<?php

namespace App\Services\Documents\Generators;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Génère le rendu HTML d'une délibération à partir d'un contexte préparé.
 *
 * Le générateur ne connaît pas directement CouncilDecision, Reservation ou Organization.
 * Il reçoit uniquement un tableau de contexte déjà préparé par DeliberationContextBuilder.
 *
 * Cette séparation permet de garder :
 * - le builder responsable des données ;
 * - le template Blade responsable de l'affichage ;
 * - le générateur responsable du rendu.
 *
 * Le HTML généré servira plus tard de base pour produire un PDF.
 */
class DeliberationGenerator
{
    private const VIEW = 'documents.deliberations.college';

    public function generateHtml(array $context): string
    {
        return view(self::VIEW, [
            'context' => $context,
        ])->render();
    }

    public function defaultFileName(array $context): string
    {
        $reservationId = data_get($context, 'reservation.id', 'reservation');
        $decisionReference = data_get($context, 'decision.reference', 'decision');

        return $this->safeFileName(
            'deliberation-'.$decisionReference.'-'.$reservationId.'.html'
        );
    }

    private function safeFileName(string $fileName): string
    {
        $fileName = strtolower($fileName);
        $fileName = preg_replace('/[^a-z0-9\\.\\-_]+/', '-', $fileName);
        $fileName = preg_replace('/-+/', '-', $fileName);

        return trim($fileName, '-');
    }

    public function defaultPdfFileName(array $context): string
    {
        $reservationId = data_get($context, 'reservation.id', 'reservation');
        $decisionReference = data_get($context, 'decision.reference', 'decision');

        return $this->safeFileName(
            'deliberation-'.$decisionReference.'-'.$reservationId.'.pdf'
        );
    }

    public function generatePdf(array $context): string
    {
        $html = $this->generateHtml($context);

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
