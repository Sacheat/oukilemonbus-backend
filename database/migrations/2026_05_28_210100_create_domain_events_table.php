<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 150);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_user_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_domain_events_aggregate');
            $table->index('event_type', 'idx_domain_events_event_type');
            $table->index('created_at', 'idx_domain_events_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
