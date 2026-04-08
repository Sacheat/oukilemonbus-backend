<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'formatted_address',
    'street',
    'street_number',
    'postal_code',
    'city',
    'country',
    'latitude',
    'longitude',
    'place_id',
    'provider',
    'is_verified',
])]
class Location extends Model
{
    use HasFactory;

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function pickupReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'pickup_location_id');
    }

    public function destinationReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'destination_location_id');
    }

    public function orgRegistrationRequests(): HasMany
    {
        return $this->hasMany(OrgRegistrationRequest::class);
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_verified' => 'boolean',
        ];
    }
}
