<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();
        $constraint = 'chk_'.$prefix.'organizations_quota';

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->enum('type', ['ecole_communale', 'asbl', 'externe']);
            $table->string('official_email', 255)->nullable();
            $table->string('phone_contact', 20)->nullable();
            $table->string('name_contact', 255)->nullable();
            $table->unsignedBigInteger('location_id')->nullable()->comment('Siège social géocodé');
            $table->boolean('is_priority')->default(false)->comment('true si type = ecole_communale');
            $table->integer('annual_quota')->default(5)->comment('Max réservations/an — surtout asbl/externe');
            $table->integer('reservations_used_this_year')->default(0)->comment('Cache métier — reset automatique 1er janvier par Scheduler');
            $table->timestamp('quota_reset_year')->useCurrent()->comment('Horodatage du dernier reset');
            $table->string('document_proof_path', 500)->nullable()->comment('Justificatif statuts ASBL...');
            $table->timestamps();

            $table->index('name', 'idx_organizations_name');
            $table->index('type', 'idx_organizations_type');
            $table->index('location_id', 'idx_organizations_location_id');
            $table->index('is_priority', 'idx_organizations_is_priority');
            $table->index('official_email', 'idx_organizations_official_email');
            $table->index(['type', 'is_priority'], 'idx_organizations_type_priority');
            $table->index('quota_reset_year', 'idx_organizations_quota_reset_year');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE {$prefix}organizations
            ADD CONSTRAINT {$constraint}
            CHECK (
                annual_quota >= 0
                AND reservations_used_this_year >= 0
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
