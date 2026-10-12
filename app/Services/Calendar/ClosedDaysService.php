<?php

namespace App\Services\Calendar;

use App\Models\Municipality;
use App\Models\MunicipalityCalendarPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Jours où le bus ne roule pas, pour toutes les années (règlement, art. 4 : jours ouvrables uniquement) :
 *   - week-ends ;
 *   - les 10 jours fériés légaux belges, calculés (Pâques et les fêtes qui en dépendent changent chaque année) ;
 *   - les fermetures propres à la commune active (municipality_calendar_periods, kind = fermeture) ;
 *   - les dates de config/reservations.php `closed_dates` (ancien réglage, toujours lu).
 * Les vacances scolaires (kind = vacances_scolaires) ne ferment rien : seules les séries récurrentes les sautent
 * (schoolHolidayReason).
 */
class ClosedDaysService
{
    /**
     * Jours fériés légaux belges d'une année : ['AAAA-MM-JJ' => 'Lundi de Pâques', …]
     *
     * @return array<string, string>
     */
    public function publicHolidays(int $year): array
    {
        $easter = $this->easterSunday($year);

        $holidays = [
            "{$year}-01-01" => 'Jour de l’An',
            $easter->addDay()->toDateString() => 'Lundi de Pâques',
            "{$year}-05-01" => 'Fête du Travail',
            $easter->addDays(39)->toDateString() => 'Ascension',
            $easter->addDays(50)->toDateString() => 'Lundi de Pentecôte',
            "{$year}-07-21" => 'Fête nationale',
            "{$year}-08-15" => 'Assomption',
            "{$year}-11-01" => 'Toussaint',
            "{$year}-11-11" => 'Armistice',
            "{$year}-12-25" => 'Noël',
        ];
        ksort($holidays);

        return $holidays;
    }

    /**
     * Pourquoi aucune réservation n'est possible ce jour-là, ou null s'il est ouvrable.
     * Ex. « Week-end », « Jour férié : Ascension », « Fermeture communale : Fête de Wallonie ».
     */
    public function closedReason(string $date): ?string
    {
        $day = CarbonImmutable::parse($date);
        $dateString = $day->toDateString();

        if ($day->isWeekend()) {
            return 'Week-end';
        }

        $holiday = config('reservations.public_holidays', true) ? ($this->publicHolidays($day->year)[$dateString] ?? null) : null;
        if ($holiday !== null) {
            return "Jour férié : {$holiday}";
        }

        $closure = $this->periodCovering(MunicipalityCalendarPeriod::CLOSURE, $dateString);
        if ($closure !== null) {
            return "Fermeture communale : {$closure->label}";
        }

        if (in_array($dateString, config('reservations.closed_dates', []), true)) {
            return 'Jour férié ou date de fermeture';
        }

        return null;
    }

    /** « Vacances scolaires : Toussaint » si ce jour est dans des vacances scolaires de la commune active, sinon null */
    public function schoolHolidayReason(string $date): ?string
    {
        $period = $this->periodCovering(MunicipalityCalendarPeriod::SCHOOL_HOLIDAYS, CarbonImmutable::parse($date)->toDateString());

        return $period ? "Vacances scolaires : {$period->label}" : null;
    }

    /**
     * Périodes de la commune active, lues une fois par instance (une série de 200 dates = 2 requêtes, pas 400).
     * Pas de singleton : une nouvelle instance par demande voit toujours les périodes à jour.
     */
    private ?Collection $periods = null;

    private function periodCovering(string $kind, string $date): ?MunicipalityCalendarPeriod
    {
        $this->periods ??= MunicipalityCalendarPeriod::query()
            ->whereIn('municipality_id', Municipality::query()->where('is_active', true)->orderBy('id')->limit(1)->pluck('id'))
            ->orderBy('start_date')
            ->get();

        return $this->periods->first(
            fn (MunicipalityCalendarPeriod $period) => $period->kind === $kind
                && $period->start_date->toDateString() <= $date
                && $period->end_date->toDateString() >= $date
        );
    }

    /** Dimanche de Pâques (calendrier grégorien, algorithme de Meeus / Jones / Butcher : sans extension PHP) */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
