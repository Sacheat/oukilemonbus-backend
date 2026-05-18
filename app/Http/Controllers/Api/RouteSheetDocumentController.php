<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\GenerateRouteSheetRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class RouteSheetDocumentController extends Controller
{
    public function generate(
        GenerateRouteSheetRequest $request,
        RouteSheetDocumentService $routeSheetDocumentService
    ): JsonResponse {
        Gate::authorize('generate', Document::class);

        $data = $request->validated();

        try {
            $document = isset($data['service_date'])
                ? $routeSheetDocumentService->generateForServiceDateVehicle(
                    $data['service_date'],
                    (int) $data['vehicle_id']
                )
                : $routeSheetDocumentService->generateForPeriod(
                    $data['start_date'],
                    $data['end_date']
                );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return (new DocumentResource($document))
            ->additional([
                'message' => 'Feuille de route generee avec succes.',
            ])
            ->response()
            ->setStatusCode(201);
    }
}
