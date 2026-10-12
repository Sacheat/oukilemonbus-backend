<?php

namespace App\Http\Requests\Municipalities;

use App\Models\MunicipalityCalendarPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout (POST /municipalities/{id}/calendar-periods) ou modification (PATCH /municipality-calendar-periods/{id})
 * d'une période : fermeture de la commune ou vacances scolaires. Un seul jour : start_date = end_date.
 * En modification, chaque champ est facultatif ; la cohérence des dates est vérifiée avec les valeurs gardées.
 */
class SaveCalendarPeriodRequest extends FormRequest
{
    /** Une période plus longue est très probablement une erreur de saisie (vacances d'été : environ 7 semaines) */
    public const MAX_DAYS = 100;

    public function authorize(): bool
    {
        return true; // permission:municipalities.manage sur la route
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'kind' => [$required, Rule::in(MunicipalityCalendarPeriod::KINDS)],
            'label' => [$required, 'string', 'min:2', 'max:100'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => [$required, 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $period = $this->route('municipalityCalendarPeriod');
            $start = $this->input('start_date', $period?->start_date?->toDateString());
            $end = $this->input('end_date', $period?->end_date?->toDateString());
            if ($validator->errors()->isNotEmpty() || ! $start || ! $end) {
                return;
            }
            if ($end < $start) {
                $validator->errors()->add('end_date', 'La fin doit être le même jour que le début ou après.');
            } elseif ((new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days >= self::MAX_DAYS) {
                $validator->errors()->add('end_date', 'Une période ne peut pas dépasser '.self::MAX_DAYS.' jours.');
            } elseif ($overlap = $this->overlappingPeriod($period, $start, $end)) {
                // Pas de doublon : deux périodes de même sorte ne se chevauchent pas (une fermeture et des vacances, oui)
                $validator->errors()->add('start_date', sprintf(
                    'Cette période chevauche « %s » (du %s au %s).',
                    $overlap->label,
                    $overlap->start_date->format('d/m/Y'),
                    $overlap->end_date->format('d/m/Y'),
                ));
            }
        }];
    }

    /** Autre période de même sorte et de même commune qui touche [start, end], ou null */
    private function overlappingPeriod(?MunicipalityCalendarPeriod $period, string $start, string $end): ?MunicipalityCalendarPeriod
    {
        $municipalityId = $period?->municipality_id ?? $this->route('municipality')?->id;
        $kind = $this->input('kind', $period?->kind);

        return MunicipalityCalendarPeriod::query()
            ->where('municipality_id', $municipalityId)
            ->where('kind', $kind)
            ->when($period, fn ($query) => $query->whereKeyNot($period->id))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->first();
    }

    public function messages(): array
    {
        return [
            'kind.in' => 'Choisissez une fermeture de la commune ou des vacances scolaires.',
            'label.required' => 'Indiquez un nom (ex. Congé d’automne, Fête de Wallonie).',
            'start_date.required' => 'Indiquez la date de début.',
            'end_date.required' => 'Indiquez la date de fin.',
        ];
    }
}
