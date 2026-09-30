<?php

namespace App\Enums;

/**
 * Cycle de vie d'une réservation.
 *
 * Flux standard :
 *   EN_ATTENTE → APPROUVE → CONFIRME → (ANNULE possible à tout moment)
 *
 * Flux avec conflit :
 *   EN_ATTENTE → EN_CONFLIT → EN_ATTENTE (après résolution) → APPROUVE → CONFIRME
 *
 * Flux court (auto-approuvé, ex : école communale sans conflit) :
 *   Directement APPROUVE ou CONFIRME à la création.
 *
 * Statuts terminaux (aucune transition possible) : REFUSE, ANNULE.
 */
enum ReservationStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case APPROUVE   = 'approuve';
    case CONFIRME   = 'confirme';
    case REFUSE     = 'refuse';
    case ANNULE     = 'annule';
    case EN_CONFLIT = 'en_conflit';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::APPROUVE   => 'Approuvé',
            self::CONFIRME   => 'Confirmé',
            self::REFUSE     => 'Refusé',
            self::ANNULE     => 'Annulé',
            self::EN_CONFLIT => 'En conflit',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'warning',
            self::APPROUVE   => 'success',
            self::CONFIRME   => 'success',
            self::REFUSE     => 'danger',
            self::ANNULE     => 'secondary',
            self::EN_CONFLIT => 'error',
        };
    }

    /**
     * Indique si le statut est terminal (aucune action métier n'est plus possible).
     * REFUSE et ANNULE sont les seuls états définitifs ; tout autre statut reste actif.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::REFUSE, self::ANNULE => true,
            default => false,
        };
    }

    /**
     * Valeurs des statuts encore actifs (non terminaux), pour filtrer en base.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal())
        ));
    }

    /**
     * Valeurs des statuts depuis lesquels une approbation est possible (machine à états).
     *
     * @return list<string>
     */
    public static function approvableValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => in_array(self::APPROUVE, $status->allowedTransitions(), true))
        ));
    }

    /**
     * Transitions autorisées depuis ce statut.
     * Ces règles constituent la machine à états métier de la réservation.
     * Toute tentative de transition non listée ici doit être rejetée par le service.
     *
     * Note : EN_CONFLIT peut revenir vers lui-même car un nouveau conflit peut être ajouté
     * pendant que la réservation est déjà en conflit (conflit composé).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::EN_ATTENTE => [self::EN_CONFLIT, self::APPROUVE, self::REFUSE, self::ANNULE],
            self::EN_CONFLIT => [self::EN_ATTENTE, self::EN_CONFLIT, self::APPROUVE, self::REFUSE, self::ANNULE],
            self::APPROUVE   => [self::EN_CONFLIT, self::CONFIRME, self::REFUSE, self::ANNULE],
            self::CONFIRME   => [self::EN_CONFLIT, self::ANNULE],
            self::REFUSE, self::ANNULE => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
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
