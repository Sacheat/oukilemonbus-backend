<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Models\Document;
use App\Models\Vehicle;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class DemoDocumentController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly RouteSheetDocumentService $routeSheetDocumentService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'viewAny', Document::class);

        $this->recordInspectorView($request, 'documents.index', [
            'status' => 'success',
            'message' => 'Ouverture de la page documents.',
            'actor_id' => $actor->id,
        ]);

        return view('demo.documents', [
            ...$this->shared($request),
            'documents' => $this->demoData->documents($actor),
            'vehicles' => Vehicle::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }

    public function generateRouteSheet(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'start_date' => ['required_without:vehicle_id', 'date'],
            'end_date' => ['required_without:vehicle_id', 'date', 'after_or_equal:start_date'],
            'service_date' => ['required_with:vehicle_id', 'nullable', 'date'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        return $this->runDemoAction(
            function () use ($actor, $validated): void {
                $this->authorizeFor($actor, 'generate', Document::class);

                if (! empty($validated['vehicle_id'])) {
                    $this->routeSheetDocumentService->generateForServiceDateVehicle(
                        $validated['service_date'],
                        (int) $validated['vehicle_id']
                    );

                    return;
                }

                $this->routeSheetDocumentService->generateForPeriod(
                    $validated['start_date'],
                    $validated['end_date']
                );
            },
            'Feuille de route generee.',
            $request,
            'documents.route_sheets.generate',
            [
                'mode' => ! empty($validated['vehicle_id']) ? 'daily_vehicle' : 'period',
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'service_date' => $validated['service_date'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
            ]
        );
    }

    public function download(Request $request, Document $document)
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'download', $document);

        abort_unless($document->is_available, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download(
            $document->file_path,
            $document->file_name,
            ['Content-Type' => $document->mime_type]
        );
    }
}
