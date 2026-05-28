<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_export_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->json('statuses')->nullable();
            $table->json('filters')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnUpdate()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by_user_id', 'status'], 'idx_planning_exports_user_status');
            $table->index('requested_at', 'idx_planning_exports_requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_export_requests');
    }
};
