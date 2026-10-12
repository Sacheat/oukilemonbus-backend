<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composition du Collège imprimée sur les délibérations (réglée par un admin dans Communes, change après les
 * élections) : bloc « PRÉSENTS », signataires et destinataire « écoles » du modèle sans subvention.
 * NULL = valeur de config/deliberations.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->text('council_present_text')->nullable()->after('weekly_planning_owner_user_id');
            $table->string('council_director_title', 100)->nullable()->after('council_present_text');
            $table->string('council_director_name', 150)->nullable()->after('council_director_title');
            $table->string('council_president_title', 100)->nullable()->after('council_director_name');
            $table->string('council_president_name', 150)->nullable()->after('council_president_title');
            $table->string('council_mayor_title', 100)->nullable()->after('council_president_name');
            $table->string('council_mayor_name', 150)->nullable()->after('council_mayor_title');
            $table->string('council_schools_recipient', 255)->nullable()->after('council_mayor_name');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn([
                'council_present_text', 'council_director_title', 'council_director_name', 'council_president_title',
                'council_president_name', 'council_mayor_title', 'council_mayor_name', 'council_schools_recipient',
            ]);
        });
    }
};
