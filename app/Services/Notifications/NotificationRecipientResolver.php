<?php

namespace App\Services\Notifications;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Résout les destinataires de notifications pour les événements opérationnels.
 *
 * Toutes les méthodes filtrent sur `active = true` pour ne jamais notifier
 * des comptes désactivés, même s'ils sont encore en base de données.
 *
 * `internalUsers()` : tous les admin et agent_communal actifs.
 * `reservationRequester()` : l'utilisateur qui a créé la demande, si actif.
 * `reservationDriverUser()` : l'utilisateur associé au profil Driver assigné, si actif.
 */
class NotificationRecipientResolver
{
    public function internalUsers(): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'agent_communal']))
            ->get();
    }

    public function reservationRequester(Reservation $reservation): ?User
    {
        $reservation->loadMissing('requestedByUser');

        return $reservation->requestedByUser?->active ? $reservation->requestedByUser : null;
    }

    public function reservationDriverUser(Reservation $reservation): ?User
    {
        $reservation->loadMissing('driver.user');
        $user = $reservation->driver?->user;

        return $user?->active ? $user : null;
    }
}

