<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 128);
            $table->string('method', 10);
            $table->string('route_name', 191);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['user_id', 'idempotency_key', 'method', 'route_name'], 'uq_api_idempotency_user_key_method_route');
            $table->index(['user_id', 'created_at'], 'idx_api_idempotency_user_created_at');
            $table->index('completed_at', 'idx_api_idempotency_completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
