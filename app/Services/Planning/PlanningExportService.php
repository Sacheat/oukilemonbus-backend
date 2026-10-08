<?php

namespace App\Services\Planning;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Reservations\ReservationOccupationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export du planning en fichier Excel (.xlsx, PhpSpreadsheet).
 *
 * Un vrai .xlsx plutôt que du HTML servi comme .xls : LibreOffice coupait chaque retour à la ligne en une nouvelle
 * ligne du tableur et ignorait largeurs de colonnes et mise en page ; le fichier s'affiche maintenant de la même façon
 * dans Excel et LibreOffice.
 *
 * Mise en page : une ligne de titre par jour, puis un trajet par ligne en 6 colonnes (horaire avec la fenêtre
 * d'occupation de ReservationOccupationService, chauffeur, bus, organisation et contact, trajet et objet, passagers).
 * Le statut n'est affiché que s'il n'est pas « Confirmé ». En-tête figé et répété à l'impression, paysage ajusté à la
 * largeur de la page.
 */
class PlanningExportService
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Colonnes et largeur (en caractères) */
    private const COLUMNS = [
        'Horaire' => 15,
        'Chauffeur' => 20,
        'Bus' => 12,
        'Organisation' => 30,
        'Trajet' => 60,
        'Pass.' => 7,
    ];

    private const GREY = '6B7280';

    private const BORDER = 'D1D5DB';

    public function __construct(
        private readonly ReservationOccupationService $occupationService
    ) {}

    public function download(Collection $reservations, string $startDate, string $endDate): Response
    {
        $fileName = sprintf('planning-%s-%s.xlsx', $startDate, $endDate);

        return response($this->content($reservations, $startDate, $endDate), 200, [
            'Content-Type' => self::MIME_TYPE,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** Contenu binaire du fichier .xlsx */
    public function content(Collection $reservations, string $startDate, string $endDate): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Planning');
        $lastColumn = chr(ord('A') + count(self::COLUMNS) - 1);

        $this->header($sheet, $lastColumn, $startDate, $endDate);

        $row = 3;
        $days = $reservations->values()->groupBy(fn (Reservation $reservation): string => (string) $reservation->trip_date?->toDateString());

        if ($days->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Aucun trajet sur cette période.');
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB(self::GREY);
        }

        foreach ($days as $date => $trips) {
            $this->dayRow($sheet, $row++, $lastColumn, $date, $trips->count());

            foreach ($trips->values() as $index => $trip) {
                $this->tripRow($sheet, $row++, $lastColumn, $trip, $index);
            }
        }

        $this->pageSetup($sheet, $lastColumn);

        return $this->write($spreadsheet);
    }

    private function header(Worksheet $sheet, string $lastColumn, string $startDate, string $endDate): void
    {
        $sheet->setCellValue('A1', 'Planning du '.$this->date($startDate).' au '.$this->date($endDate));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $column = 'A';
        foreach (self::COLUMNS as $label => $width) {
            $sheet->setCellValue("{$column}2", $label);
            $sheet->getColumnDimension($column)->setWidth($width);
            $column++;
        }

        $style = $sheet->getStyle("A2:{$lastColumn}2");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(20);
    }

    /** Ligne de titre d'un jour : remplace la colonne Date */
    private function dayRow(Worksheet $sheet, int $row, string $lastColumn, string $date, int $count): void
    {
        $label = ucfirst(CarbonImmutable::parse($date)->locale('fr')->isoFormat('dddd D MMMM YYYY'));
        $text = new RichText();
        $text->createTextRun($label)->getFont()?->setBold(true)->setName('Arial')->setSize(11);
        $text->createTextRun('  ·  '.$count.' trajet'.($count > 1 ? 's' : ''))->getFont()?->setName('Arial')->setSize(10)->setColor(new Color('4B5563'));

        $sheet->setCellValue("A{$row}", $text);
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle("A{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    private function tripRow(Worksheet $sheet, int $row, string $lastColumn, Reservation $reservation, int $index): void
    {
        $occupation = $this->occupationService->windowForReservation($reservation);
        $driverName = trim(($reservation->driver?->user?->first_name ?? '').' '.($reservation->driver?->user?->last_name ?? ''));
        $contact = $reservation->organization?->phone_contact
            ?? $reservation->requestedByUser?->phone
            ?? $reservation->organization?->official_email
            ?? $reservation->requestedByUser?->email;

        // Le statut n'apparaît que s'il n'est pas « Confirmé » (le cas normal du planning)
        $status = $reservation->status !== null && $reservation->status !== ReservationStatus::CONFIRME
            ? $reservation->status->label()
            : null;

        $sheet->setCellValue("A{$row}", $this->lines(
            $reservation->departure_time?->format('H:i').' → '.$reservation->return_time?->format('H:i'),
            'occ. '.$occupation['start']->format('H:i').'–'.$occupation['end']->format('H:i'),
            bold: true
        ));
        $sheet->setCellValue("B{$row}", $driverName !== '' ? $driverName : 'Non assigné');
        $sheet->setCellValue("C{$row}", $reservation->vehicle?->licence_plate ?? 'Non assigné');
        $sheet->setCellValue("D{$row}", $this->lines($reservation->organization?->name ?? '', $contact));
        $sheet->setCellValue("E{$row}", $this->lines(
            $reservation->pickupLocation?->formatted_address.' → '.$reservation->destinationLocation?->formatted_address,
            $reservation->trip_purpose,
            status: $status
        ));
        $sheet->setCellValue("F{$row}", ($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0));

        foreach (['B' => $driverName === '', 'C' => $reservation->vehicle === null] as $column => $missing) {
            if ($missing) {
                $sheet->getStyle("{$column}{$row}")->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');
            }
        }

        $style = $sheet->getStyle("A{$row}:{$lastColumn}{$row}");
        $style->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDER);
        if ($index % 2 === 1) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        }
        $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** Cellule sur deux lignes : la principale, puis un détail en petit et en gris (et le statut en orange) */
    private function lines(string $main, ?string $detail, bool $bold = false, ?string $status = null): RichText
    {
        $text = new RichText();
        $text->createTextRun($main)->getFont()?->setName('Arial')->setSize(10)->setBold($bold);

        if ($detail !== null && $detail !== '') {
            $text->createTextRun("\n".$detail)->getFont()?->setName('Arial')->setSize(9)->setColor(new Color(self::GREY));
        }

        if ($status !== null) {
            $text->createTextRun("\n".$status)->getFont()?->setName('Arial')->setSize(9)->setBold(true)->setColor(new Color('B45309'));
        }

        return $text;
    }

    private function pageSetup(Worksheet $sheet, string $lastColumn): void
    {
        $sheet->freezePane('A3');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 2)
            ->setPrintArea("A1:{$lastColumn}".$sheet->getHighestRow());
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()->setOddFooter('&L&8Planning&R&8Page &P / &N');
    }

    private function write(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planning-');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
}
