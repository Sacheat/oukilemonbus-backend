<?php

namespace Tests\Unit;

use App\Enums\ApprovalType;
use App\Enums\AssignmentType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Enums\UnavailabilityReasonType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\ReservationAssignmentHistory;
use App\Models\ReservationConflict;
use App\Models\ReservationStatusHistory;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use App\Models\User;
use App\Models\UserOrganization;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelConfigurationTest extends TestCase
{
    #[DataProvider('fillableProvider')]
    public function test_models_expose_the_expected_fillable_attributes(string $modelClass, array $expectedFillable): void
    {
        $this->assertSame($expectedFillable, (new $modelClass)->getFillable());
    }

    #[DataProvider('castsProvider')]
    public function test_models_expose_the_expected_casts(string $modelClass, array $expectedCasts): void
    {
        $casts = (new $modelClass)->getCasts();

        foreach ($expectedCasts as $attribute => $cast) {
            $this->assertArrayHasKey($attribute, $casts);
            $this->assertSame($cast, $casts[$attribute]);
        }
    }

    #[DataProvider('tableMetadataProvider')]
    public function test_models_expose_expected_table_metadata(
        string $modelClass,
        string $table,
        string $primaryKey,
        ?string $updatedAtColumn,
        bool $incrementing
    ): void {
        $model = new $modelClass;

        $this->assertSame($table, $model->getTable());
        $this->assertSame($primaryKey, $model->getKeyName());
        $this->assertSame($updatedAtColumn, $model->getUpdatedAtColumn());
        $this->assertSame($incrementing, $model->getIncrementing());
    }

    #[DataProvider('relationProvider')]
    public function test_models_declare_expected_relationships(
        string $modelClass,
        string $method,
        string $relationClass,
        string $relatedClass,
        ?string $foreignKey = null,
        ?string $table = null
    ): void {
        $relation = (new $modelClass)->{$method}();

        $this->assertInstanceOf($relationClass, $relation);

        if (! $relation instanceof MorphTo) {
            $this->assertInstanceOf($relatedClass, $relation->getRelated());
        }

        if ($foreignKey !== null && method_exists($relation, 'getForeignKeyName')) {
            $this->assertSame($foreignKey, $relation->getForeignKeyName());
        }

        if ($table !== null && method_exists($relation, 'getTable')) {
            $this->assertSame($table, $relation->getTable());
        }
    }

    public static function fillableProvider(): array
    {
        return [
            [User::class, ['first_name', 'last_name', 'email', 'password', 'phone', 'active']],
            [Driver::class, ['user_id', 'licence_number', 'licence_expiry_date', 'is_active']],
            [Vehicle::class, ['licence_plate', 'capacity', 'is_active']],
            [Location::class, ['formatted_address', 'street', 'street_number', 'postal_code', 'city', 'country', 'latitude', 'longitude', 'place_id', 'provider', 'is_verified']],
            [Municipality::class, ['name', 'external_id', 'nis_code', 'country', 'postal_code', 'source', 'is_active', 'synced_at', 'latitude', 'longitude', 'depot_location_id', 'max_trip_distance_km', 'max_pickup_distance_km']],
            [MunicipalityLocality::class, ['municipality_id', 'name', 'normalized_name', 'external_id', 'source', 'is_active']],
            [Organization::class, ['name', 'type', 'official_email', 'phone_contact', 'name_contact', 'location_id', 'is_priority', 'is_banned', 'banned_reason', 'annual_quota', 'reservations_used_this_year', 'quota_reset_year', 'proof_document_id']],
            [UserOrganization::class, ['user_id', 'organization_id', 'relationship_type', 'is_default']],
            [Recurrence::class, ['frequency', 'interval_value', 'days_of_week', 'start_date', 'end_date', 'occurrence_count', 'is_active']],
            [Reservation::class, ['requested_by_user_id', 'organization_id', 'pickup_location_id', 'destination_location_id', 'driver_id', 'vehicle_id', 'recurrence_id', 'trip_date', 'departure_time', 'return_time', 'nb_children', 'nb_adults', 'trip_purpose', 'activity_type', 'distance_km', 'estimated_duration_min', 'status', 'approval_type', 'validated_by_user_id', 'validated_at', 'cancelled_by_user_id', 'cancelled_at', 'rejection_reason', 'cancellation_reason', 'passengers_confirmed_at']],
            [CouncilDecision::class, ['reservation_id', 'decision_status', 'decision_date', 'recorded_by_user_id', 'decision_reference', 'validated_at', 'notes']],
            [Document::class, ['reservation_id', 'user_id', 'document_type', 'file_name', 'file_path', 'mime_type', 'file_size', 'checksum', 'generation_mode', 'generation_context', 'template_key', 'template_version', 'generated_at', 'is_official', 'version', 'is_available']],
            [NotificationDelivery::class, ['event_id', 'event_key', 'dedupe_key', 'attempt_id', 'attempt_number', 'notification_id', 'notification_type', 'notifiable_type', 'notifiable_id', 'reservation_id', 'document_id', 'channel', 'priority', 'recipient_email', 'status', 'provider', 'provider_message_id', 'payload', 'sent_at', 'failed_at', 'error_message']],
            [Unavailability::class, ['reason_type', 'reason', 'driver_id', 'vehicle_id', 'start_datetime', 'end_datetime', 'created_by_user_id', 'is_active']],
            [UnavailabilityHistory::class, ['unavailability_id', 'old_reason_type', 'new_reason_type', 'old_start_datetime', 'new_start_datetime', 'old_end_datetime', 'new_end_datetime', 'old_reason', 'new_reason', 'changed_by_user_id', 'change_reason']],
            [ReservationStatusHistory::class, ['reservation_id', 'old_status', 'new_status', 'changed_by_user_id', 'reason']],
            [ReservationAssignmentHistory::class, ['reservation_id', 'assignment_type', 'old_driver_id', 'new_driver_id', 'old_vehicle_id', 'new_vehicle_id', 'changed_by_user_id', 'reason']],
            [ReservationConflict::class, ['reservation_id', 'type', 'message', 'related_reservation_id', 'is_resolved', 'resolved_by_user_id', 'resolved_at']],
            [OrgRegistrationRequest::class, ['request_by_user_id', 'organization_name', 'organization_type', 'enterprise_number', 'official_email', 'phone', 'location_id', 'contact_person_name', 'contact_person_role', 'proof_document_id', 'comment', 'status', 'confidence_note', 'reviewed_by_user_id', 'reviewed_at', 'rejection_reason']],
            [UserOrgRequest::class, ['organization_id', 'requested_by_user_id', 'requested_role', 'justification', 'proof_document_id', 'status', 'confidence_note', 'reviewed_by_user_id', 'reviewed_at', 'rejection_reason']],
        ];
    }

    public static function castsProvider(): array
    {
        return [
            [User::class, ['active' => 'boolean', 'email_verified_at' => 'datetime', 'password' => 'hashed']],
            [Driver::class, ['licence_expiry_date' => 'date', 'is_active' => 'boolean']],
            [Vehicle::class, ['capacity' => 'integer', 'is_active' => 'boolean']],
            [Location::class, ['latitude' => 'decimal:8', 'longitude' => 'decimal:8', 'is_verified' => 'boolean']],
            [Municipality::class, ['is_active' => 'boolean', 'synced_at' => 'datetime', 'latitude' => 'decimal:8', 'longitude' => 'decimal:8', 'max_trip_distance_km' => 'integer', 'max_pickup_distance_km' => 'integer']],
            [MunicipalityLocality::class, ['is_active' => 'boolean']],
            [Organization::class, ['type' => OrganizationType::class, 'is_priority' => 'boolean', 'annual_quota' => 'integer', 'reservations_used_this_year' => 'integer', 'quota_reset_year' => 'datetime']],
            [UserOrganization::class, ['is_default' => 'boolean']],
            [Recurrence::class, ['interval_value' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'occurrence_count' => 'integer', 'is_active' => 'boolean']],
            [Reservation::class, ['trip_date' => 'date', 'departure_time' => 'datetime:H:i:s', 'return_time' => 'datetime:H:i:s', 'nb_children' => 'integer', 'nb_adults' => 'integer', 'activity_type' => ReservationActivityType::class, 'distance_km' => 'decimal:2', 'estimated_duration_min' => 'integer', 'status' => ReservationStatus::class, 'approval_type' => ApprovalType::class, 'validated_at' => 'datetime', 'cancelled_at' => 'datetime']],
            [CouncilDecision::class, ['decision_status' => CouncilDecisionStatus::class, 'decision_date' => 'date', 'validated_at' => 'datetime']],
            [Document::class, ['document_type' => DocumentType::class, 'file_size' => 'integer', 'generation_context' => 'array', 'generated_at' => 'datetime', 'is_official' => 'boolean', 'version' => 'integer', 'is_available' => 'boolean']],
            [NotificationDelivery::class, ['status' => DeliveryStatus::class, 'attempt_number' => 'integer', 'payload' => 'json', 'sent_at' => 'datetime', 'failed_at' => 'datetime']],
            [Unavailability::class, ['reason_type' => UnavailabilityReasonType::class, 'start_datetime' => 'datetime', 'end_datetime' => 'datetime', 'is_active' => 'boolean']],
            [UnavailabilityHistory::class, ['old_reason_type' => UnavailabilityReasonType::class, 'new_reason_type' => UnavailabilityReasonType::class, 'old_start_datetime' => 'datetime', 'new_start_datetime' => 'datetime', 'old_end_datetime' => 'datetime', 'new_end_datetime' => 'datetime', 'created_at' => 'datetime']],
            [ReservationStatusHistory::class, ['old_status' => ReservationStatus::class, 'new_status' => ReservationStatus::class, 'created_at' => 'datetime']],
            [ReservationAssignmentHistory::class, ['assignment_type' => AssignmentType::class, 'created_at' => 'datetime']],
            [ReservationConflict::class, ['type' => ReservationConflictType::class, 'is_resolved' => 'boolean', 'resolved_at' => 'datetime', 'created_at' => 'datetime']],
            [OrgRegistrationRequest::class, ['organization_type' => OrganizationType::class, 'status' => RequestStatus::class, 'confidence_note' => 'integer', 'reviewed_at' => 'datetime']],
            [UserOrgRequest::class, ['status' => RequestStatus::class, 'confidence_note' => 'integer', 'reviewed_at' => 'datetime']],
        ];
    }

    public static function tableMetadataProvider(): array
    {
        return [
            [User::class, 'users', 'id', 'updated_at', true],
            [UserOrganization::class, 'user_organization', 'id', 'updated_at', false],
            [ReservationStatusHistory::class, 'reservation_status_history', 'id', null, true],
            [ReservationAssignmentHistory::class, 'reservation_assignment_history', 'id', null, true],
            [ReservationConflict::class, 'reservation_conflicts', 'id', null, true],
            [UnavailabilityHistory::class, 'unavailabilities_history', 'id', null, true],
            [UserOrgRequest::class, 'user_org_requests', 'request_id', 'updated_at', true],
        ];
    }

    public static function relationProvider(): array
    {
        return [
            [User::class, 'driver', HasOne::class, Driver::class, 'user_id'],
            [User::class, 'organizations', BelongsToMany::class, Organization::class, null, 'user_organization'],
            [User::class, 'requestedReservations', HasMany::class, Reservation::class, 'requested_by_user_id'],
            [User::class, 'validatedReservations', HasMany::class, Reservation::class, 'validated_by_user_id'],
            [User::class, 'cancelledReservations', HasMany::class, Reservation::class, 'cancelled_by_user_id'],
            [User::class, 'recordedCouncilDecisions', HasMany::class, CouncilDecision::class, 'recorded_by_user_id'],
            [User::class, 'documents', HasMany::class, Document::class, 'user_id'],
            [User::class, 'createdUnavailabilities', HasMany::class, Unavailability::class, 'created_by_user_id'],
            [User::class, 'unavailabilityHistoryChanges', HasMany::class, UnavailabilityHistory::class, 'changed_by_user_id'],
            [User::class, 'reservationStatusHistoryChanges', HasMany::class, ReservationStatusHistory::class, 'changed_by_user_id'],
            [User::class, 'reservationAssignmentHistoryChanges', HasMany::class, ReservationAssignmentHistory::class, 'changed_by_user_id'],
            [User::class, 'orgRegistrationRequests', HasMany::class, OrgRegistrationRequest::class, 'request_by_user_id'],
            [User::class, 'reviewedOrgRegistrationRequests', HasMany::class, OrgRegistrationRequest::class, 'reviewed_by_user_id'],
            [User::class, 'userOrgRequests', HasMany::class, UserOrgRequest::class, 'requested_by_user_id'],
            [User::class, 'reviewedUserOrgRequests', HasMany::class, UserOrgRequest::class, 'reviewed_by_user_id'],
            [User::class, 'notificationDeliveries', MorphMany::class, NotificationDelivery::class],
            [Organization::class, 'location', BelongsTo::class, Location::class, 'location_id'],
            [Organization::class, 'users', BelongsToMany::class, User::class, null, 'user_organization'],
            [Organization::class, 'reservations', HasMany::class, Reservation::class, 'organization_id'],
            [Organization::class, 'userOrgRequests', HasMany::class, UserOrgRequest::class, 'organization_id'],
            [Driver::class, 'user', BelongsTo::class, User::class, 'user_id'],
            [Driver::class, 'reservations', HasMany::class, Reservation::class, 'driver_id'],
            [Driver::class, 'unavailabilities', HasMany::class, Unavailability::class, 'driver_id'],
            [Driver::class, 'oldAssignmentHistory', HasMany::class, ReservationAssignmentHistory::class, 'old_driver_id'],
            [Driver::class, 'newAssignmentHistory', HasMany::class, ReservationAssignmentHistory::class, 'new_driver_id'],
            [Vehicle::class, 'reservations', HasMany::class, Reservation::class, 'vehicle_id'],
            [Vehicle::class, 'unavailabilities', HasMany::class, Unavailability::class, 'vehicle_id'],
            [Vehicle::class, 'oldAssignmentHistory', HasMany::class, ReservationAssignmentHistory::class, 'old_vehicle_id'],
            [Vehicle::class, 'newAssignmentHistory', HasMany::class, ReservationAssignmentHistory::class, 'new_vehicle_id'],
            [Location::class, 'organizations', HasMany::class, Organization::class, 'location_id'],
            [Location::class, 'pickupReservations', HasMany::class, Reservation::class, 'pickup_location_id'],
            [Location::class, 'destinationReservations', HasMany::class, Reservation::class, 'destination_location_id'],
            [Location::class, 'orgRegistrationRequests', HasMany::class, OrgRegistrationRequest::class, 'location_id'],
            [Municipality::class, 'localities', HasMany::class, MunicipalityLocality::class, 'municipality_id'],
            [Municipality::class, 'activeLocalities', HasMany::class, MunicipalityLocality::class, 'municipality_id'],
            [Municipality::class, 'depotLocation', BelongsTo::class, Location::class, 'depot_location_id'],
            [MunicipalityLocality::class, 'municipality', BelongsTo::class, Municipality::class, 'municipality_id'],
            [Recurrence::class, 'reservations', HasMany::class, Reservation::class, 'recurrence_id'],
            [Reservation::class, 'requestedByUser', BelongsTo::class, User::class, 'requested_by_user_id'],
            [Reservation::class, 'organization', BelongsTo::class, Organization::class, 'organization_id'],
            [Reservation::class, 'pickupLocation', BelongsTo::class, Location::class, 'pickup_location_id'],
            [Reservation::class, 'destinationLocation', BelongsTo::class, Location::class, 'destination_location_id'],
            [Reservation::class, 'driver', BelongsTo::class, Driver::class, 'driver_id'],
            [Reservation::class, 'vehicle', BelongsTo::class, Vehicle::class, 'vehicle_id'],
            [Reservation::class, 'recurrence', BelongsTo::class, Recurrence::class, 'recurrence_id'],
            [Reservation::class, 'validatedByUser', BelongsTo::class, User::class, 'validated_by_user_id'],
            [Reservation::class, 'cancelledByUser', BelongsTo::class, User::class, 'cancelled_by_user_id'],
            [Reservation::class, 'councilDecision', HasOne::class, CouncilDecision::class, 'reservation_id'],
            [Reservation::class, 'documents', HasMany::class, Document::class, 'reservation_id'],
            [Reservation::class, 'statusHistory', HasMany::class, ReservationStatusHistory::class, 'reservation_id'],
            [Reservation::class, 'assignmentHistory', HasMany::class, ReservationAssignmentHistory::class, 'reservation_id'],
            [Reservation::class, 'notificationDeliveries', HasMany::class, NotificationDelivery::class, 'reservation_id'],
            [Reservation::class, 'conflicts', HasMany::class, ReservationConflict::class, 'reservation_id'],
            [CouncilDecision::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [CouncilDecision::class, 'recordedByUser', BelongsTo::class, User::class, 'recorded_by_user_id'],
            [Document::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [Document::class, 'user', BelongsTo::class, User::class, 'user_id'],
            [Document::class, 'notificationDeliveries', HasMany::class, NotificationDelivery::class, 'document_id'],
            [NotificationDelivery::class, 'notifiable', MorphTo::class, User::class],
            [NotificationDelivery::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [NotificationDelivery::class, 'document', BelongsTo::class, Document::class, 'document_id'],
            [Unavailability::class, 'driver', BelongsTo::class, Driver::class, 'driver_id'],
            [Unavailability::class, 'vehicle', BelongsTo::class, Vehicle::class, 'vehicle_id'],
            [Unavailability::class, 'createdByUser', BelongsTo::class, User::class, 'created_by_user_id'],
            [Unavailability::class, 'history', HasMany::class, UnavailabilityHistory::class, 'unavailability_id'],
            [UnavailabilityHistory::class, 'unavailability', BelongsTo::class, Unavailability::class, 'unavailability_id'],
            [UnavailabilityHistory::class, 'changedByUser', BelongsTo::class, User::class, 'changed_by_user_id'],
            [ReservationStatusHistory::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [ReservationStatusHistory::class, 'changedByUser', BelongsTo::class, User::class, 'changed_by_user_id'],
            [ReservationAssignmentHistory::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [ReservationAssignmentHistory::class, 'oldDriver', BelongsTo::class, Driver::class, 'old_driver_id'],
            [ReservationAssignmentHistory::class, 'newDriver', BelongsTo::class, Driver::class, 'new_driver_id'],
            [ReservationAssignmentHistory::class, 'oldVehicle', BelongsTo::class, Vehicle::class, 'old_vehicle_id'],
            [ReservationAssignmentHistory::class, 'newVehicle', BelongsTo::class, Vehicle::class, 'new_vehicle_id'],
            [ReservationAssignmentHistory::class, 'changedByUser', BelongsTo::class, User::class, 'changed_by_user_id'],
            [ReservationConflict::class, 'reservation', BelongsTo::class, Reservation::class, 'reservation_id'],
            [ReservationConflict::class, 'relatedReservation', BelongsTo::class, Reservation::class, 'related_reservation_id'],
            [ReservationConflict::class, 'resolvedByUser', BelongsTo::class, User::class, 'resolved_by_user_id'],
            [OrgRegistrationRequest::class, 'requestedByUser', BelongsTo::class, User::class, 'request_by_user_id'],
            [OrgRegistrationRequest::class, 'location', BelongsTo::class, Location::class, 'location_id'],
            [OrgRegistrationRequest::class, 'reviewedByUser', BelongsTo::class, User::class, 'reviewed_by_user_id'],
            [UserOrgRequest::class, 'organization', BelongsTo::class, Organization::class, 'organization_id'],
            [UserOrgRequest::class, 'requestedByUser', BelongsTo::class, User::class, 'requested_by_user_id'],
            [UserOrgRequest::class, 'reviewedByUser', BelongsTo::class, User::class, 'reviewed_by_user_id'],
            [UserOrganization::class, 'user', BelongsTo::class, User::class, 'user_id'],
            [UserOrganization::class, 'organization', BelongsTo::class, Organization::class, 'organization_id'],
        ];
    }
}
