<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_org_requests', function (Blueprint $table) {
            $table->bigIncrements('request_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('requested_role', 50)->comment('prof | directeur | animateur | responsable');
            $table->text('justification')->nullable();
            $table->string('proof_document_path', 500)->nullable();
            $table->enum('status', ['en_attente', 'approuve', 'refuse']);
            $table->integer('confidence_note')->nullable()->comment('Note interne admin (optionnel)');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('organization_id', 'idx_user_org_requests_organization_id');
            $table->index('requested_by_user_id', 'idx_user_org_requests_requested_by_user_id');
            $table->index('status', 'idx_user_org_requests_status');
            $table->index('reviewed_by_user_id', 'idx_user_org_requests_reviewed_by_user_id');
            $table->index('created_at', 'idx_user_org_requests_created_at');
            $table->index(['organization_id', 'status'], 'idx_user_org_requests_org_status');
            $table->index(['requested_by_user_id', 'status'], 'idx_user_org_requests_user_status');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE user_org_requests
            ADD CONSTRAINT chk_user_org_requests_confidence_note
            CHECK (
                confidence_note IS NULL
                OR (confidence_note BETWEEN 0 AND 100)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_org_requests');
    }
};
