<?php

namespace App\Console\Commands;

use App\Enums\DriverAlertStatus;
use App\Models\ApiIdempotencyKey;
use App\Models\DocumentAccessLog;
use App\Models\DomainEvent;
use App\Models\DriverAlert;
use App\Models\TripEvent;
use App\Models\User;
use App\Notifications\Privacy\InactiveAccountWarning;
use App\Services\Privacy\UserAnonymizationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * RGPD : applique les durées de conservation de config/privacy.php (planifiée chaque nuit). 0 désactive une purge.
 *
 * - Positions GPS des chauffeurs : latitude, longitude, précision et instant de mesure sont effacés des étapes de
 *   trajet (d'après occurred_at) et des demandes d'aide clôturées (d'après closed_at) ; une alerte ouverte garde sa
 *   position. position_state, le dernier lieu connu (fallback_*) et les tracés prévus (trip_routes) restent.
 * - Clés d'idempotence, journal d'accès aux documents, journal d'audit (domain_events) : lignes supprimées.
 * - Comptes inactifs (dernière activité, sinon création) : avertis par email `inactive_accounts_warning_days` avant
 *   le délai (un compte désactivé est marqué averti sans email : il ne peut pas se reconnecter), puis anonymisés
 *   (UserAnonymizationService, sans auteur). Un compte encore engagé (réservation à venir, dernier admin…) est
 *   sauté et journalisé ; revenir sur l'app annule l'avertissement (EnsureUserIsActive).
 */
class PrunePersonalDataCommand extends Command
{
    protected $signature = 'privacy:prune {--dry-run : Compte les lignes concernées sans rien modifier}';

    protected $description = 'Efface les données personnelles dont la durée de conservation (config/privacy.php) est dépassée';

    private const array CLEARED_POSITION = [
        'latitude' => null,
        'longitude' => null,
        'accuracy_m' => null,
        'position_captured_at' => null,
    ];

    public function handle(UserAnonymizationService $anonymizationService): int
    {
        $this->pruneDriverPositions();
        $this->deleteOlderThan('Clés d\'idempotence', ApiIdempotencyKey::query(), 'created_at', 'idempotency_keys_days');
        $this->deleteOlderThan('Accès aux documents', DocumentAccessLog::query(), 'created_at', 'document_access_logs_days');
        $this->deleteOlderThan('Événements d\'audit', DomainEvent::query(), 'created_at', 'domain_events_days');
        $this->handleInactiveAccounts($anonymizationService);

        return self::SUCCESS;
    }

    private function pruneDriverPositions(): void
    {
        $threshold = $this->threshold('driver_positions_days');

        if ($threshold === null) {
            $this->line('Positions GPS : purge désactivée.');

            return;
        }

        $tripEvents = TripEvent::query()
            ->where('occurred_at', '<', $threshold)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'));

        $alerts = DriverAlert::query()
            ->where('status', DriverAlertStatus::CLOTUREE->value)
            ->where('closed_at', '<', $threshold)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'));

        $this->report('Positions effacées des étapes de trajet', $this->isDryRun() ? $tripEvents->count() : $tripEvents->update(self::CLEARED_POSITION));
        $this->report("Positions effacées des demandes d'aide", $this->isDryRun() ? $alerts->count() : $alerts->update(self::CLEARED_POSITION));
    }

    private function deleteOlderThan(string $label, Builder $query, string $column, string $retentionKey): void
    {
        $threshold = $this->threshold($retentionKey);

        if ($threshold === null) {
            $this->line("{$label} : purge désactivée.");

            return;
        }

        $query->where($column, '<', $threshold);

        $this->report("{$label} supprimés", $this->isDryRun() ? $query->count() : $query->delete());
    }

    private function handleInactiveAccounts(UserAnonymizationService $anonymizationService): void
    {
        $days = (int) config('privacy.retention.inactive_accounts_days', 1095);

        if ($days <= 0) {
            $this->line('Comptes inactifs : anonymisation désactivée.');

            return;
        }

        $warningDays = min(max((int) config('privacy.retention.inactive_accounts_warning_days', 30), 0), $days);
        $anonymizeBefore = now()->subDays($days);

        // Avertissement : inactif depuis (délai - préavis), pas encore averti
        $toWarn = $this->inactiveSince(now()->subDays($days - $warningDays))->whereNull('inactivity_warned_at');
        $warned = 0;

        if ($this->isDryRun()) {
            $warned = $toWarn->count();
        } else {
            $toWarn->chunkById(100, function ($users) use (&$warned, $warningDays): void {
                foreach ($users as $user) {
                    $user->forceFill(['inactivity_warned_at' => now()])->save();

                    if ($user->active) {
                        $user->notify(new InactiveAccountWarning(now()->addDays($warningDays)));
                    }

                    $warned++;
                }
            });
        }

        $this->report('Comptes inactifs avertis', $warned);

        // Anonymisation : délai dépassé et averti depuis au moins le préavis
        $toAnonymize = $this->inactiveSince($anonymizeBefore)
            ->whereNotNull('inactivity_warned_at')
            ->where('inactivity_warned_at', '<=', now()->subDays($warningDays));
        $anonymized = 0;
        $skipped = 0;

        if ($this->isDryRun()) {
            $anonymized = $toAnonymize->count();
        } else {
            // Chaque anonymisation retire le compte de la sélection : on relit les ids d'abord
            foreach ($toAnonymize->pluck('id') as $userId) {
                try {
                    $anonymizationService->anonymize(User::query()->findOrFail($userId), null);
                    $anonymized++;
                } catch (ValidationException $exception) {
                    $skipped++;
                    Log::warning('privacy.inactive_account.skipped', [
                        'user_id' => $userId,
                        'reasons' => collect($exception->errors())->flatten()->values()->all(),
                    ]);
                }
            }
        }

        $this->report('Comptes inactifs anonymisés', $anonymized);

        if ($skipped > 0) {
            $this->warn("Comptes inactifs sautés (engagements en cours, voir les logs) : {$skipped}");
        }
    }

    /** Comptes non anonymisés dont la dernière activité (sinon la création) précède $before. */
    private function inactiveSince(Carbon $before): Builder
    {
        return User::query()
            ->whereNull('anonymized_at')
            ->where(DB::raw('COALESCE(last_seen_at, created_at)'), '<', $before);
    }

    private function threshold(string $retentionKey): ?Carbon
    {
        $days = (int) config("privacy.retention.{$retentionKey}", 0);

        return $days > 0 ? now()->subDays($days) : null;
    }

    private function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    private function report(string $label, int $count): void
    {
        $this->info("{$label} : {$count}".($this->isDryRun() ? ' (dry-run)' : ''));
    }
}
