<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal du Backend Inspector (module Tooling).
 * Une ligne par requête API mutante enregistrée ; purgée après `tooling.inspector.retention_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspector_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Copie de l'email au moment de la requête : reste lisible si l'utilisateur est supprimé.
            $table->string('user_email')->nullable();
            $table->string('method', 10);
            $table->string('path', 2048);
            $table->string('route_name')->nullable()->index();
            $table->string('flow_key')->nullable()->index();
            $table->json('route_params')->nullable();
            $table->unsignedSmallInteger('http_status')->index();
            $table->string('outcome', 10)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspector_entries');
    }
};
