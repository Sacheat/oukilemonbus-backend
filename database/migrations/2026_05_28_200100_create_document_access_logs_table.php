<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('document_access_token_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_success');
            $table->string('failure_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['document_id', 'is_success'], 'idx_document_access_logs_document_success');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};

