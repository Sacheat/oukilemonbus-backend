<?php

namespace App\Enums;

enum OrganizationType: string
{
    case ECOLE_COMMUNALE = 'ecole_communale';
    case PERSONNEL_COMMUNAL = 'personnel_communal';
    case CPAS = 'cpas';
    case ASBL = 'asbl';
    case EXTERNE = 'externe';

    public function label(): string
    {
        return match ($this) {
            self::ECOLE_COMMUNALE    => 'Ecole communale',
            self::PERSONNEL_COMMUNAL => 'Personnel communal',
            self::CPAS               => 'CPAS',
            self::ASBL               => 'ASBL',
            self::EXTERNE            => 'Externe',
        };
    }

    public function isPriorityByDefault(): bool
    {
        return $this->isInConfig('high_priority_types');
    }

    public function isAutoApproveType(): bool
    {
        return $this->isInConfig('auto_approve_types');
    }

    public function isQuotaExempt(): bool
    {
        return $this->isInConfig('quota_exempt_types');
    }

    public function isTimeRestrictionExempt(): bool
    {
        return $this->isInConfig('time_restriction_exempt_types');
    }

    public function isCouncilDecisionExempt(): bool
    {
        return $this->isInConfig('council_decision_exempt_types');
    }

    public function canUseRecurrences(): bool
    {
        return $this->isInConfig('recurrence_allowed_types');
    }

    private function isInConfig(string $key): bool
    {
        return in_array($this->value, config("reservations.{$key}", []), true);
    }

    /**
     * Obtenir tous les types sous forme de tableau [value => label]
     */
    public static function toArray(): array
    {
        return array_column(
            array_map(fn ($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}
