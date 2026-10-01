<?php

use App\Enums\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feuilles de route journalières : date de service et véhicule en colonnes (au lieu du seul chemin du fichier),
 * pour lister, filtrer et paginer côté base (GET /route-sheets) et ne garder que la dernière version.
 * Les feuilles existantes sont reprises depuis leur chemin : documents/route-sheets/AAAA-MM-JJ/vehicle-N/…
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->date('service_date')->nullable()->after('version');
            $table->foreignId('vehicle_id')->nullable()->after('service_date')->constrained()->nullOnDelete();
            $table->index(['document_type', 'service_date', 'vehicle_id'], 'idx_documents_type_service_date_vehicle');
        });

        DB::table('documents')
            ->where('document_type', DocumentType::FEUILLE_ROUTE->value)
            ->where('file_path', 'like', 'documents/route-sheets/%/vehicle-%/%')
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    if (preg_match('#^documents/route-sheets/(\d{4}-\d{2}-\d{2})/vehicle-(\d+)/#', $document->file_path, $matches) !== 1) {
                        continue;
                    }

                    $vehicleExists = DB::table('vehicles')->where('id', (int) $matches[2])->exists();

                    DB::table('documents')->where('id', $document->id)->update([
                        'service_date' => $matches[1],
                        'vehicle_id' => $vehicleExists ? (int) $matches[2] : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('idx_documents_type_service_date_vehicle');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('service_date');
        });
    }
};
