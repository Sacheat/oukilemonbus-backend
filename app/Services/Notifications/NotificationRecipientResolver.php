<?php

namespace App\Services\Notifications;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

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

