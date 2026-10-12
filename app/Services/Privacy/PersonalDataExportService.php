<?php

namespace App\Services\Privacy;

use App\Models\DocumentAccessLog;
use App\Models\DriverAlert;
use App\Models\OrgRegistrationRequest;
use App\Models\Reservation;
use App\Models\TripEvent;
use App\Models\TripReport;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\UserOrgRequest;

/**
 * RGPD, droits d'accès et de portabilité (art. 15 et 20) : toutes les données personnelles rattachées à un compte,
 * en JSON structuré. Les sections chauffeur ne sont remplies que si le compte a un profil chauffeur.
 *
 * Ne contient pas : les données d'autres personnes (seuls les noms d'organisation et les adresses des trajets
 * accompagnent les réservations), les secrets (mot de passe, tokens) ni les PDF générés, consultables dans l'app.
 * Une nouvelle table qui porte des données d'un utilisateur doit être ajoutée ici.
 */
class PersonalDataExportService
{
    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $user->loadMissing(['roles', 'organizations', 'driver', 'notificationPreference']);
        $driver = $user->driver;

        return [
            'generated_at' => now()->toISOString(),
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'active' => $user->active,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'anonymized_at' => $user->anonymized_at?->toISOString(),
                'last_seen_at' => $user->last_seen_at?->toISOString(),
                'privacy_policy_version' => $user->privacy_policy_version,
                'privacy_policy_accepted_at' => $user->privacy_policy_accepted_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
                'updated_at' => $user->updated_at?->toISOString(),
                'roles' => $user->roles->pluck('name')->values()->all(),
            ],
            'organizations' => $user->organizations->map(fn ($organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'relationship_type' => $organization->pivot->relationship_type,
                'is_default' => (bool) $organization->pivot->is_default,
                'joined_at' => $organization->pivot->created_at?->toISOString(),
            ])->values()->all(),
            'notification_preferences' => $user->notificationPreference?->makeHidden(['id', 'user_id'])->toArray(),
            'api_tokens' => $user->tokens()->get(['name', 'last_used_at', 'expires_at', 'created_at'])->toArray(),
            'reservations_requested' => $this->reservations($user),
            'membership_requests' => UserOrgRequest::query()
                ->where('requested_by_user_id', $user->id)
                ->with('organization:id,name')
                ->orderBy('created_at')
                ->get()
                ->map(fn (UserOrgRequest $request): array => [
                    'id' => $request->request_id,
                    'organization' => $request->organization?->name,
                    ...$request->only(['requested_role', 'justification', 'status', 'rejection_reason']),
                    'has_proof_document' => $request->proof_document_id !== null,
                    'reviewed_at' => $request->reviewed_at?->toISOString(),
                    'created_at' => $request->created_at?->toISOString(),
                ])->all(),
            'organization_registration_requests' => OrgRegistrationRequest::query()
                ->where('request_by_user_id', $user->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (OrgRegistrationRequest $request): array => [
                    ...$request->only([
                        'id', 'organization_name', 'organization_type', 'enterprise_number', 'official_email', 'phone',
                        'contact_person_name', 'contact_person_role', 'comment', 'status', 'rejection_reason',
                    ]),
                    'has_proof_document' => $request->proof_document_id !== null,
                    'reviewed_at' => $request->reviewed_at?->toISOString(),
                    'created_at' => $request->created_at?->toISOString(),
                ])->all(),
            'in_app_notifications' => $user->notifications()
                ->orderBy('created_at')
                ->get(['type', 'data', 'read_at', 'created_at'])
                ->toArray(),
            'document_access_logs' => DocumentAccessLog::query()
                ->where('user_id', $user->id)
                ->orderBy('created_at')
                ->get(['document_id', 'ip_address', 'user_agent', 'is_success', 'failure_reason', 'created_at'])
                ->toArray(),
            'driver' => $driver === null ? null : [
                'profile' => $driver->only(['id', 'licence_number', 'is_active'])
                    + ['licence_expiry_date' => $driver->licence_expiry_date?->toDateString()],
                'trip_events' => TripEvent::query()
                    ->where('driver_id', $driver->id)
                    ->orderBy('occurred_at')
                    ->get([
                        'reservation_id', 'type', 'occurred_at', 'source', 'position_state',
                        'latitude', 'longitude', 'accuracy_m', 'position_captured_at',
                    ])
                    ->toArray(),
                'alerts' => DriverAlert::query()
                    ->where('driver_id', $driver->id)
                    ->orderBy('created_at')
                    ->get([
                        'id', 'reservation_id', 'status', 'message', 'trip_progress', 'position_state',
                        'latitude', 'longitude', 'accuracy_m', 'position_captured_at', 'position_updates',
                        'fallback_label', 'handled_at', 'closed_at', 'closing_note', 'created_at',
                    ])
                    ->toArray(),
                'trip_reports' => TripReport::query()
                    ->where('driver_id', $driver->id)
                    ->orderBy('reported_at')
                    ->get([
                        'reservation_id', 'outcome', 'actual_departure_time', 'actual_return_time',
                        'actual_nb_children', 'actual_nb_adults', 'remarks', 'reported_at',
                    ])
                    ->toArray(),
                'unavailabilities' => Unavailability::query()
                    ->where('driver_id', $driver->id)
                    ->orderBy('start_datetime')
                    ->get(['id', 'reason', 'start_datetime', 'end_datetime', 'created_at'])
                    ->toArray(),
                'assigned_trips' => Reservation::query()
                    ->where('driver_id', $driver->id)
                    ->orderBy('trip_date')
                    ->get(['id', 'trip_date', 'departure_time', 'return_time', 'status'])
                    ->toArray(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function reservations(User $user): array
    {
        return Reservation::query()
            ->where('requested_by_user_id', $user->id)
            ->with(['organization:id,name', 'pickupLocation:id,formatted_address', 'destinationLocation:id,formatted_address'])
            ->orderBy('trip_date')
            ->get()
            ->map(fn (Reservation $reservation): array => [
                ...$reservation->only([
                    'id', 'nb_children', 'nb_adults', 'trip_purpose', 'rejection_reason', 'cancellation_reason',
                ]),
                'trip_date' => $reservation->trip_date?->toDateString(),
                'departure_time' => $reservation->departure_time?->format('H:i:s'),
                'return_time' => $reservation->return_time?->format('H:i:s'),
                'status' => $reservation->status?->value,
                'organization' => $reservation->organization?->name,
                'pickup_address' => $reservation->pickupLocation?->formatted_address,
                'destination_address' => $reservation->destinationLocation?->formatted_address,
                'created_at' => $reservation->created_at?->toISOString(),
            ])
            ->all();
    }
}
