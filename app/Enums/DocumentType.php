<?php

namespace App\Enums;

/**
 * Types de documents gérés par l'application.
 * FEUILLE_ROUTE : document journalier par véhicule (généré automatiquement chaque soir).
 * DELIBERATION : document officiel lié à une décision du Collège communal.
 * ATTESTATION : document administratif officiel (ex : attestation de transport).
 * PLANNING : export du planning sur une période donnée.
 * JUSTIFICATIF : pièce jointe uploadée par un utilisateur (statuts ASBL, délibération de création, etc.).
 * LISTE_PASSAGERS : liste nominative des passagers d'un trajet (carnet de bord, contrôle du SPF Mobilité), jointe par
 *   le demandeur ou un agent (PassengerListService), gardée 3 ans après l'année du trajet.
 */
enum DocumentType: string
{
    case FEUILLE_ROUTE = 'feuille_route';
    case ATTESTATION = 'attestation';
    case DELIBERATION = 'deliberation';
    case PLANNING = 'planning';
    case JUSTIFICATIF = 'justificatif';
    case LISTE_PASSAGERS = 'liste_passagers';

    public function label(): string
    {
        return match ($this) {
            self::FEUILLE_ROUTE => 'Feuille de route',
            self::ATTESTATION => 'Attestation',
            self::DELIBERATION => 'Délibération',
            self::PLANNING => 'Planning',
            self::JUSTIFICATIF => 'Justificatif',
            self::LISTE_PASSAGERS => 'Liste des passagers',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FEUILLE_ROUTE => 'document-text',
            self::ATTESTATION => 'badge-check',
            self::DELIBERATION => 'clipboard-document-check',
            self::PLANNING => 'calendar',
            self::JUSTIFICATIF => 'paper-clip',
            self::LISTE_PASSAGERS => 'users',
        };
    }

    /**
     * Indique si ce type de document est un acte administratif officiel.
     * Les types officiels nécessitent une signature ou un tampon et ne peuvent
     * pas être modifiés après génération sans créer une nouvelle version.
     */
    public function requiresOfficial(): bool
    {
        return in_array($this, [self::DELIBERATION, self::ATTESTATION]);
    }

    public static function toArray(): array
    {
        return array_column(
            array_map(fn($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}
