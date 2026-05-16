<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/* * Migration des champs de génération pour les documents générés
 * - checksum : pour identifier de manière unique le contenu généré, utile pour éviter les doublons
 * - generation_context : pour stocker les paramètres de génération (ex: modele officiel, etc.)
 * - template_key et template_version : pour identifier le template utilisé lors de la génération
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('checksum', 128) // pour eviter les problèmes de longueur d'index avec les moteurs de base de données comme MySQL
                ->nullable()
                ->after('file_size');

            // Stockage du contexte de génération en JSON (reservation_id, organization_name, trip_date,pickup_location,destination,decision_status)
            $table->json('generation_context')
                ->nullable()
                ->after('generation_mode');

            // modele de document utilisé pour la génération (ex : deliberations.school ou deliberations.external)
            $table->string('template_key', 100)
                ->nullable()
                ->after('generation_context');

            //version du template utilisé pour la génération (gestion d'evolution des templates dans le temps)
            $table->string('template_version', 30)
                ->nullable()
                ->after('template_key');

            $table->index('checksum', 'idx_documents_checksum');
            $table->index('template_key', 'idx_documents_template_key');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('idx_documents_checksum');
            $table->dropIndex('idx_documents_template_key');

            $table->dropColumn([
                'checksum',
                'generation_context',
                'template_key',
                'template_version',
            ]);
        });
    }
};
