<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration de données : au plus une organisation par défaut par utilisateur.
 *
 * L'approbation d'une demande de création d'organisation (et le seeder de test) marquaient
 * chaque nouvelle appartenance is_default = true sans retirer l'ancienne. On garde :
 *   - la plus ancienne appartenance déjà marquée par défaut ;
 *   - à défaut (aucune marquée), la plus ancienne appartenance.
 * Égalité de created_at départagée par organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userIds = DB::table('user_organization')->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $memberships = DB::table('user_organization')
                ->where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->orderBy('organization_id')
                ->get(['organization_id', 'is_default']);

            $keep = $memberships->first()->organization_id;
            $defaults = $memberships->where('is_default', true)->pluck('organization_id');

            if ($defaults->count() === 1 && $defaults->first() === $keep) {
                continue;
            }

            DB::transaction(function () use ($userId, $keep): void {
                DB::table('user_organization')
                    ->where('user_id', $userId)
                    ->where('organization_id', '!=', $keep)
                    ->update(['is_default' => false]);

                DB::table('user_organization')
                    ->where('user_id', $userId)
                    ->where('organization_id', $keep)
                    ->update(['is_default' => true]);
            });
        }
    }

    public function down(): void
    {
        // Irréversible : les anciens marquages multiples étaient une anomalie.
    }
};
