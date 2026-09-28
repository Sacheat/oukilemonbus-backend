<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentAccessTokenService;
use App\Services\Documents\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Gère la consultation et le téléchargement des documents administratifs.
 * Deux niveaux de sécurité sont appliqués :
 * - le middleware de route vérifie la permission Spatie globale (documents.view, documents.download) ;
 * - Gate::authorize() vérifie ensuite si l'utilisateur peut accéder à ce document précis (DocumentPolicy).
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly DocumentAccessTokenService $tokenService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        // DocumentService filtre selon le rôle : agent voit tout, chauffeur voit ses feuilles de route, etc.
        $documents = $this->documentService->listVisibleForUser($request->user());

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        $document->load(['reservation', 'user']);

        return new DocumentResource($document);
    }

    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        return $this->documentService->download($document);
    }

    /**
     * Téléchargement via token signé (liens partagés hors session, ex: pièce jointe email).
     * Le token est à usage unique et expiré après consommation par validateAndConsume().
     */
    public function signedDownload(Request $request, Document $document): StreamedResponse
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $this->tokenService->validateAndConsume($document, $token, $request)) {
            abort(403, 'Lien de téléchargement invalide ou expiré.');
        }

        return $this->documentService->download($document);
    }
}
