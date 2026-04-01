<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100)->comment('Prénom');
            $table->string('last_name', 255)->comment('Nom de famille');
            $table->string('email', 255)->comment('Identifiant de connexion Laravel Auth');
            $table->string('password', 255)->comment('Hash bcrypt');
            $table->string('phone', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique('email', 'uq_users_email');
            $table->index('active', 'idx_users_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
