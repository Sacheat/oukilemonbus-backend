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
use App\Enums\ReservationStatus;
use App\Enums\UnavailabilityReasonType;
use Tests\TestCase;

class DomainEnumsTest extends TestCase
{
    public function test_reservation_status_behaviour_matches_business_meaning(): void
    {
        $this->assertFalse(ReservationStatus::EN_ATTENTE->isTerminal());
        $this->assertFalse(ReservationStatus::APPROUVE->isTerminal());
        $this->assertFalse(ReservationStatus::CONFIRME->isTerminal());
        $this->assertTrue(ReservationStatus::REFUSE->isTerminal());
        $this->assertTrue(ReservationStatus::ANNULE->isTerminal());
        $this->assertFalse(ReservationStatus::EN_CONFLIT->isTerminal());
        $this->assertSame('warning', ReservationStatus::EN_ATTENTE->color());
    }

    public function test_document_type_identifies_official_documents(): void
    {
        $this->assertFalse(DocumentType::FEUILLE_ROUTE->requiresOfficial());
        $this->assertTrue(DocumentType::ATTESTATION->requiresOfficial());
        $this->assertTrue(DocumentType::DELIBERATION->requiresOfficial());
        $this->assertFalse(DocumentType::PLANNING->requiresOfficial());
        $this->assertSame('calendar', DocumentType::PLANNING->icon());
    }

    public function test_organization_type_priority_defaults_are_explicit(): void
    {
        $this->assertTrue(OrganizationType::ECOLE_COMMUNALE->isPriorityByDefault());
        $this->assertFalse(OrganizationType::ASBL->isPriorityByDefault());
        $this->assertFalse(OrganizationType::EXTERNE->isPriorityByDefault());
    }

    public function test_request_and_delivery_status_helpers_are_consistent(): void
    {
        $this->assertTrue(RequestStatus::EN_ATTENTE->isPending());
        $this->assertFalse(RequestStatus::APPROUVE->isPending());
        $this->assertTrue(DeliveryStatus::SENT->isSuccessful());
        $this->assertFalse(DeliveryStatus::FAILED->isSuccessful());
    }

    public function test_supporting_enums_expose_expected_values_and_descriptions(): void
    {
        $this->assertSame('Automatique', ApprovalType::AUTO->label());
        $this->assertSame('Validation manuelle requise', ApprovalType::MANUEL->description());
        $this->assertSame('Assignation de chauffeur', AssignmentType::DRIVER->description());
        $this->assertSame('warning', CouncilDecisionStatus::EN_ATTENTE->color());
        $this->assertTrue(ReservationActivityType::GYMNASTIQUE->isSchoolPriorityActivity());
        $this->assertFalse(ReservationActivityType::STANDARD->isSchoolPriorityActivity());
        $this->assertStringContainsString('veh', strtolower($this->normalize(UnavailabilityReasonType::VEHICLE->description())));
    }

    public function test_to_array_helpers_keep_all_cases(): void
    {
        $this->assertCount(count(ReservationStatus::cases()), ReservationStatus::toArray());
        $this->assertCount(count(ReservationActivityType::cases()), ReservationActivityType::toArray());
        $this->assertCount(count(DocumentType::cases()), DocumentType::toArray());
        $this->assertCount(count(OrganizationType::cases()), OrganizationType::toArray());
        $this->assertCount(count(RequestStatus::cases()), RequestStatus::toArray());
        $this->assertCount(count(DeliveryStatus::cases()), DeliveryStatus::toArray());
    }

    private function normalize(string $value): string
    {
        $replacements = [
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'à' => 'a',
            'â' => 'a',
            'ù' => 'u',
            'û' => 'u',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ç' => 'c',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'À' => 'A',
            'Â' => 'A',
            'Ù' => 'U',
            'Û' => 'U',
            'Î' => 'I',
            'Ï' => 'I',
            'Ô' => 'O',
            'Ç' => 'C',
        ];

        return strtr(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), $replacements);
    }
}
