<?php

namespace App\Services\Drivers;

use App\Models\Driver;

class DriverService
{
    public function create(array $data): Driver
    {
        $driver = Driver::create($data);

        // Un profil chauffeur donne accès à l'espace chauffeur (missions, feuilles de route).
        $driver->user?->assignRole('chauffeur');

        return $driver;
    }

    public function update(Driver $driver, array $data): Driver
    {
        $driver->update($data);
        return $driver->refresh();
    }

    public function activate(Driver $driver): Driver
    {
        $driver->update(['is_active' => true]);
        return $driver->refresh();
    }

    public function deactivate(Driver $driver): Driver
    {
        $driver->update(['is_active' => false]);
        return $driver->refresh();
    }
}
