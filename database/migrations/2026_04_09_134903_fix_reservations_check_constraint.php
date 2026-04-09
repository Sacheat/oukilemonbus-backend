<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $prefix     = DB::getTablePrefix();
        $oldName    = 'chk_' . $prefix . 'reservations_business_rules';
        $newName    = 'chk_' . $prefix . 'reservations_business_rules';

        // Supprimer l'ancien CHECK qui contenait 17h et 50km
        DB::statement("ALTER TABLE {$prefix}reservations DROP CHECK {$oldName}");

        // Recréer le CHECK sans ces deux règles
        DB::statement("
            ALTER TABLE {$prefix}reservations
            ADD CONSTRAINT {$newName}
            CHECK (
                return_time > departure_time
                AND nb_adults >= 1
                AND nb_children >= 0
                AND (nb_children + nb_adults) >= 1
                AND (nb_children + nb_adults + 1) <= 41
                AND (estimated_duration_min IS NULL OR estimated_duration_min > 0)
            )
        ");
    }

    public function down(): void
    {
        $prefix  = DB::getTablePrefix();
        $name    = 'chk_' . $prefix . 'reservations_business_rules';

        // Restaurer l'ancien CHECK si rollback
        DB::statement("ALTER TABLE {$prefix}reservations DROP CHECK {$name}");

        DB::statement("
            ALTER TABLE {$prefix}reservations
            ADD CONSTRAINT {$name}
            CHECK (
                return_time > departure_time
                AND return_time <= '17:00:00'
                AND nb_adults >= 1
                AND nb_children >= 0
                AND (nb_children + nb_adults) >= 1
                AND (nb_children + nb_adults + 1) <= 41
                AND (distance_km IS NULL OR distance_km <= 50.00)
                AND (estimated_duration_min IS NULL OR estimated_duration_min > 0)
            )
        ");
    }
};
