<?php

namespace App\Services\Documents\Generators;

use Barryvdh\DomPDF\Facade\Pdf;

class RouteSheetGenerator
{
    private const VIEW = 'documents.route_sheets.weekly';
    private const DAILY_VIEW = 'documents.route_sheets.daily';

    public function generateHtml(array $context): string
    {
        return view($this->viewFor($context), [
            'context' => $context,
        ])->render();
    }

    public function generatePdf(array $context): string
    {
        $html = $this->generateHtml($context);

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->output();
    }

    public function defaultFileName(array $context): string
    {
        if (data_get($context, 'document.template_key') === 'route_sheets.daily') {
            return $this->safeFileName(
                'route-sheet-'
                .data_get($context, 'service.date', 'date')
                .'-'
                .data_get($context, 'service.vehicle.licence_plate', 'vehicle')
                .'.html'
            );
        }

        $startDate = data_get($context, 'period.start_date', 'start');
        $endDate = data_get($context, 'period.end_date', 'end');

        return $this->safeFileName(
            'route-sheet-'.$startDate.'-'.$endDate.'.html'
        );
    }

    public function defaultPdfFileName(array $context): string
    {
        if (data_get($context, 'document.template_key') === 'route_sheets.daily') {
            return $this->safeFileName(
                'route-sheet-'
                .data_get($context, 'service.date', 'date')
                .'-'
                .data_get($context, 'service.vehicle.licence_plate', 'vehicle')
                .'.pdf'
            );
        }

        $startDate = data_get($context, 'period.start_date', 'start');
        $endDate = data_get($context, 'period.end_date', 'end');

        return $this->safeFileName(
            'route-sheet-'.$startDate.'-'.$endDate.'.pdf'
        );
    }

    private function viewFor(array $context): string
    {
        return data_get($context, 'document.template_key') === 'route_sheets.daily'
            ? self::DAILY_VIEW
            : self::VIEW;
    }

    private function safeFileName(string $fileName): string
    {
        $fileName = strtolower($fileName);
        $fileName = preg_replace('/[^a-z0-9\.\-_]+/', '-', $fileName);
        $fileName = preg_replace('/-+/', '-', $fileName);

        return trim($fileName, '-');
    }
}
