<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('document_type', ['feuille_route', 'attestation', 'deliberation', 'planning']);
            $table->string('file_name', 255);
            $table->string('file_path', 500)->comment('Chemin storage/app/private/...');
            $table->string('mime_type', 100);
            $table->integer('file_size')->nullable();
            $table->string('generation_mode', 50)->comment('auto | manuel');
            $table->timestamp('generated_at')->nullable()->comment('Horodatage effectif');
            $table->boolean('is_official')->default(false);
            $table->integer('version')->default(1);
            $table->boolean('is_available')->default(true)->comment('false = expiré ou supprimé');
            $table->timestamps();

            $table->index('reservation_id', 'idx_documents_reservation_id');
            $table->index('user_id', 'idx_documents_user_id');
            $table->index('document_type', 'idx_documents_document_type');
            $table->index('generated_at', 'idx_documents_generated_at');
            $table->index('is_official', 'idx_documents_is_official');
            $table->index('is_available', 'idx_documents_is_available');
            $table->index(['reservation_id', 'document_type'], 'idx_documents_reservation_type');
            $table->index(['document_type', 'is_available'], 'idx_documents_type_available');
            $table->index('mime_type', 'idx_documents_mime_type');

            $table->foreign('reservation_id')
                ->references('id')
                ->on('reservations')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_documents_values
            CHECK (
                (file_size IS NULL OR file_size >= 0)
                AND version >= 1
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
