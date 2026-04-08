<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('1 seul profil chauffeur par utilisateur');
            $table->string('licence_number', 20);
            $table->date('licence_expiry_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('user_id', 'uq_drivers_user_id');
            $table->unique('licence_number', 'uq_drivers_licence_number');
            $table->index('licence_expiry_date', 'idx_drivers_licence_expiry_date');
            $table->index('is_active', 'idx_drivers_is_active');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
