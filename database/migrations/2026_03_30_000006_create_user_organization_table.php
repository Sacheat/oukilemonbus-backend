<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_organization', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('relationship_type', 50)->comment('prof | directeur | animateur | responsable | referent');
            $table->boolean('is_default')->default(false)->comment('Organisation pré-sélectionnée à la connexion');
            $table->timestamps();

            $table->primary(['user_id', 'organization_id']);
            $table->index('user_id', 'idx_user_organization_user_id');
            $table->index('organization_id', 'idx_user_organization_organization_id');
            $table->index(['user_id', 'is_default'], 'idx_user_organization_user_default');
            $table->index(['organization_id', 'relationship_type'], 'idx_user_organization_org_role');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_organization');
    }
};
