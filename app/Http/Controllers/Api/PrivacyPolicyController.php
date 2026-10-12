<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Privacy\PrivacyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Politique de confidentialité : version en vigueur (publique, pour le formulaire d'inscription) et prise de
 * connaissance par la personne connectée. Voir PrivacyPolicy.
 */
class PrivacyPolicyController extends Controller
{
    public function __construct(
        private readonly PrivacyPolicy $privacyPolicy
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->privacyPolicy->current()]);
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate(['version' => ['required', 'string', 'max:30']]);

        $this->privacyPolicy->accept($request->user(), $validated['version']);

        return response()->json(['data' => $this->privacyPolicy->statusFor($request->user())]);
    }
}
