<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\GenerateRouteSheetRequest;
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
            $document = $routeSheetDocumentService->generateForPeriod(
                $data['start_date'],
                $data['end_date']
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'message' => 'Feuille de route générée avec succès.',
            'document' => [
                'id' => $document->id,
                'document_type' => $document->document_type instanceof \BackedEnum
                    ? $document->document_type->value
                    : $document->document_type,
                'file_name' => $document->file_name,
                'file_path' => $document->file_path,
                'version' => $document->version,
            ],
        ], 201);
    }
}
