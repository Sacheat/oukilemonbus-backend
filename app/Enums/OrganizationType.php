<?php

namespace App\Enums;

/**
 * Type d'organisation demandeuse du bus communal.
 * Les comportements associés (priorité, auto-approbation, quotas, récurrences, etc.)
 * sont entièrement pilotés par config/reservations.php et non codés en dur ici.
 * Cela permet de modifier les règles métier sans toucher au code PHP.
 */
enum OrganizationType: string
{
    case ECOLE_COMMUNALE    = 'ecole_communale';
    case PERSONNEL_COMMUNAL = 'personnel_communal';
    case CPAS               = 'cpas';
    case ASBL               = 'asbl';
    case EXTERNE            = 'externe';

    public function label(): string
    {
        return match ($this) {
            self::ECOLE_COMMUNALE    => 'École communale',
            self::PERSONNEL_COMMUNAL => 'Personnel communal',
            self::CPAS               => 'CPAS',
            self::ASBL               => 'ASBL',
            self::EXTERNE            => 'Externe',
        };
    }

    /** Ce type est-il traité en priorité dans l'ordonnancement du planning ? */
    public function isPriorityByDefault(): bool
    {
        return $this->isInConfig('high_priority_types');
    }

    /** Ce type bénéficie-t-il d'une approbation automatique sans intervention d'un agent ? */
    public function isAutoApproveType(): bool
    {
        return $this->isInConfig('auto_approve_types');
    }

    /** Ce type est-il exempté du quota annuel de réservations ? */
    public function isQuotaExempt(): bool
    {
        return $this->isInConfig('quota_exempt_types');
    }

    /** Ce type est-il exempté des restrictions horaires (avant 08h / après 17h) ? */
    public function isTimeRestrictionExempt(): bool
    {
        return $this->isInConfig('time_restriction_exempt_types');
    }

    /** Ce type est-il exempté de l'obligation de décision du Collège communal ? */
    public function isCouncilDecisionExempt(): bool
    {
        return $this->isInConfig('council_decision_exempt_types');
    }

    /**
     * Types dont les réservations exigent une décision du Collège (non exemptés).
     *
     * @return list<string>
     */
    public static function councilDecisionRequiredValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => ! $type->isCouncilDecisionExempt())
        ));
    }

    /** Ce type est-il autorisé à utiliser les réservations récurrentes ? */
    public function canUseRecurrences(): bool
    {
        return $this->isInConfig('recurrence_allowed_types');
    }

    /**
     * Vérifie si ce type est présent dans une liste de config/reservations.php.
     * Toutes les méthodes de comportement délèguent ici pour rester pilotées par la config.
     */
    private function isInConfig(string $key): bool
    {
        return in_array($this->value, config("reservations.{$key}", []), true);
    }

    public static function toArray(): array
    {
        return array_column(
            array_map(fn ($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}
