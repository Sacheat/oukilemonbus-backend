<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MigrationStructureTest extends TestCase
{
    private string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    public function test_expected_domain_migration_files_exist(): void
    {
        $expectedFiles = [
            '2026_03_30_000001_create_users_table.php',
            '2026_03_30_000002_create_drivers_table.php',
            '2026_03_30_000003_create_vehicles_table.php',
            '2026_03_30_000004_create_locations_table.php',
            '2026_03_30_000005_create_organizations_table.php',
            '2026_03_30_000006_create_user_organization_table.php',
            '2026_03_30_000007_create_recurrences_table.php',
            '2026_03_30_000008_create_reservations_table.php',
            '2026_03_30_000009_create_council_decisions_table.php',
            '2026_03_30_000010_create_documents_table.php',
            '2026_03_30_000011_create_notifications_table.php',
            '2026_03_30_000012_create_unavailabilities_table.php',
            '2026_03_30_000013_create_unavailabilities_history_table.php',
            '2026_03_30_000014_create_reservation_status_history_table.php',
            '2026_03_30_000015_create_reservation_assignment_history_table.php',
            '2026_03_30_000016_create_org_registration_requests_table.php',
            '2026_03_30_000017_create_user_org_requests_table.php',
            '2026_04_02_000018_create_notification_deliveries_table.php',
            '2026_04_15_000019_create_municipalities_table.php',
            '2026_04_15_000020_create_municipality_localities_table.php',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertFileExists($this->migrationPath.DIRECTORY_SEPARATOR.$file, "Missing migration file {$file}");
        }
    }

    public function test_users_and_organizations_migrations_define_the_expected_columns(): void
    {
        $usersMigration = $this->migrationContents('2026_03_30_000001_create_users_table.php');
        $organizationsMigration = $this->migrationContents('2026_03_30_000005_create_organizations_table.php');

        $this->assertStringContainsString("Schema::create('users'", $usersMigration);
        $this->assertStringContainsString("\$table->string('first_name', 100)", $usersMigration);
        $this->assertStringContainsString("\$table->string('last_name', 255)", $usersMigration);
        $this->assertStringContainsString("\$table->unique('email', 'uq_users_email')", $usersMigration);

        $this->assertStringContainsString("Schema::create('organizations'", $organizationsMigration);
        $this->assertStringContainsString("\$table->string('official_email', 255)->nullable()", $organizationsMigration);
        $this->assertStringContainsString("\$table->index('official_email', 'idx_organizations_official_email')", $organizationsMigration);
        $this->assertStringContainsString('$prefix = DB::getTablePrefix();', $organizationsMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'organizations_quota';", $organizationsMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}organizations', $organizationsMigration);
        $this->assertStringContainsString('ADD CONSTRAINT {$constraint}', $organizationsMigration);
    }

    public function test_reservations_documents_and_notification_deliveries_embed_business_constraints(): void
    {
        $reservationsMigration = $this->migrationContents('2026_03_30_000008_create_reservations_table.php');
        $documentsMigration = $this->migrationContents('2026_03_30_000010_create_documents_table.php');
        $deliveriesMigration = $this->migrationContents('2026_04_02_000018_create_notification_deliveries_table.php');

        $this->assertStringContainsString("\$table->string('status', 50)", $reservationsMigration);
        $this->assertStringContainsString("\$table->string('approval_type', 50)", $reservationsMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}reservations', $reservationsMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'reservations_business_rules';", $reservationsMigration);
        $this->assertStringContainsString('ADD CONSTRAINT {$constraint}', $reservationsMigration);
        $this->assertStringContainsString('return_time > departure_time', $reservationsMigration);
        $this->assertStringNotContainsString('(nb_children + nb_adults + 1) <= 41', $reservationsMigration);

        $this->assertStringContainsString("\$table->string('document_type', 50)", $documentsMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}documents', $documentsMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'documents_values';", $documentsMigration);
        $this->assertStringContainsString('ADD CONSTRAINT {$constraint}', $documentsMigration);
        $this->assertStringContainsString('version >= 1', $documentsMigration);

        $this->assertStringContainsString("Schema::create('notification_deliveries'", $deliveriesMigration);
        $this->assertStringContainsString("\$table->uuid('event_id')", $deliveriesMigration);
        $this->assertStringContainsString("\$table->uuid('attempt_id')", $deliveriesMigration);
        $this->assertStringContainsString("\$table->unsignedInteger('attempt_number')->default(1)", $deliveriesMigration);
        $this->assertStringContainsString("\$table->unique('attempt_id', 'uq_notification_deliveries_attempt_id')", $deliveriesMigration);
        $this->assertStringContainsString('uq_notification_deliveries_event_channel_notifiable_attempt', $deliveriesMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}notification_deliveries', $deliveriesMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'notification_deliveries_state';", $deliveriesMigration);
        $this->assertStringContainsString('ADD CONSTRAINT {$constraint}', $deliveriesMigration);
        $this->assertStringContainsString('attempt_number >= 1', $deliveriesMigration);
        $this->assertStringContainsString("(status = 'sent' AND sent_at IS NOT NULL)", $deliveriesMigration);
    }

    public function test_history_and_request_migrations_preserve_traceability_fields(): void
    {
        $statusHistoryMigration = $this->migrationContents('2026_03_30_000014_create_reservation_status_history_table.php');
        $assignmentHistoryMigration = $this->migrationContents('2026_03_30_000015_create_reservation_assignment_history_table.php');
        $unavailabilityHistoryMigration = $this->migrationContents('2026_03_30_000013_create_unavailabilities_history_table.php');
        $userOrgRequestMigration = $this->migrationContents('2026_03_30_000017_create_user_org_requests_table.php');
        $orgRegistrationMigration = $this->migrationContents('2026_03_30_000016_create_org_registration_requests_table.php');
        $municipalitiesMigration = $this->migrationContents('2026_04_15_000019_create_municipalities_table.php');
        $municipalityLocalitiesMigration = $this->migrationContents('2026_04_15_000020_create_municipality_localities_table.php');

        $this->assertStringContainsString("Schema::create('reservation_status_history'", $statusHistoryMigration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('changed_by_user_id')", $statusHistoryMigration);
        $this->assertStringContainsString("\$table->timestamp('created_at')", $statusHistoryMigration);

        $this->assertStringContainsString("Schema::create('reservation_assignment_history'", $assignmentHistoryMigration);
        $this->assertStringContainsString("\$table->string('assignment_type', 50)", $assignmentHistoryMigration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('changed_by_user_id')", $assignmentHistoryMigration);

        $this->assertStringContainsString("Schema::create('unavailabilities_history'", $unavailabilityHistoryMigration);
        $this->assertStringContainsString("\$table->string('change_reason', 500)->nullable()", $unavailabilityHistoryMigration);

        $this->assertStringContainsString("Schema::create('user_org_requests'", $userOrgRequestMigration);
        $this->assertStringContainsString("\$table->bigIncrements('request_id')", $userOrgRequestMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}user_org_requests', $userOrgRequestMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'user_org_requests_confidence_note';", $userOrgRequestMigration);
        $this->assertStringContainsString('ADD CONSTRAINT {$constraint}', $userOrgRequestMigration);

        $this->assertStringContainsString("Schema::create('org_registration_requests'", $orgRegistrationMigration);
        $this->assertStringContainsString("\$table->string('official_email', 255)", $orgRegistrationMigration);
        $this->assertStringContainsString("\$table->string('status', 50)", $orgRegistrationMigration);
        $this->assertStringContainsString("\$table->integer('confidence_note')->nullable()", $orgRegistrationMigration);
        $this->assertStringContainsString('ALTER TABLE {$prefix}org_registration_requests', $orgRegistrationMigration);
        $this->assertStringContainsString("\$constraint = 'chk_'.\$prefix.'org_registration_requests_confidence_note';", $orgRegistrationMigration);

        $this->assertStringContainsString("Schema::create('municipalities'", $municipalitiesMigration);
        $this->assertStringContainsString("\$table->string('external_id', 500)->nullable()", $municipalitiesMigration);
        $this->assertStringContainsString("\$table->string('nis_code', 20)->nullable()", $municipalitiesMigration);
        $this->assertStringContainsString("\$table->boolean('is_active')->default(false)", $municipalitiesMigration);
        $this->assertStringContainsString("Schema::create('municipality_localities'", $municipalityLocalitiesMigration);
        $this->assertStringContainsString("\$table->string('external_id', 500)->nullable()", $municipalityLocalitiesMigration);
        $this->assertStringContainsString("\$table->unique(['municipality_id', 'normalized_name']", $municipalityLocalitiesMigration);
    }

    private function migrationContents(string $filename): string
    {
        return file_get_contents($this->migrationPath.DIRECTORY_SEPARATOR.$filename);
    }
}
