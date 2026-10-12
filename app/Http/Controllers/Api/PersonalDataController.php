<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Privacy\PersonalDataExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * RGPD, droits d'accès et de portabilité : export JSON des données personnelles d'un compte
 * (PersonalDataExportService). Chacun pour son compte ; un admin (users.export-data) pour n'importe quel compte.
 */
class PersonalDataController extends Controller
{
    public function __construct(
        private readonly PersonalDataExportService $exportService
    ) {}

    public function mine(Request $request): JsonResponse
    {
        return $this->download($request->user());
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('exportData', $user);

        return $this->download($user);
    }

    private function download(User $user): JsonResponse
    {
        return response()
            ->json(['data' => $this->exportService->export($user)], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="donnees-personnelles-'.$user->getKey().'.json"');
    }
}
