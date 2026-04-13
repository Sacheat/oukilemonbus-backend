<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_by_user_id');
            $table->string('organization_name', 255);
            $table->string('organization_type', 50);
            $table->string('enterprise_number', 50)->nullable()->comment('Numéro BCE (ASBL)');
            $table->string('official_email', 255);
            $table->string('phone', 20)->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('contact_person_name', 100)->nullable();
            $table->string('contact_person_role', 100)->nullable();
            $table->string('proof_document_path', 500)->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('request_by_user_id', 'idx_org_registration_requests_request_by_user_id');
            $table->index('organization_type', 'idx_org_registration_requests_organization_type');
            $table->index('official_email', 'idx_org_registration_requests_official_email');
            $table->index('location_id', 'idx_org_registration_requests_location_id');
            $table->index('status', 'idx_org_registration_requests_status');
            $table->index('reviewed_by_user_id', 'idx_org_registration_requests_reviewed_by_user_id');
            $table->index('created_at', 'idx_org_registration_requests_created_at');
            $table->index(['status', 'created_at'], 'idx_org_registration_requests_status_created_at');
            $table->index('enterprise_number', 'idx_org_registration_requests_enterprise_number');

            $table->foreign('request_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_registration_requests');
    }
};
