<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Contrôleur d'API pour la gestion des documents générés.
 *
 * Il fournit des endpoints pour :
 * - lister les documents visibles par l'utilisateur connecté ;
 * - consulter les détails d'un document précis ;
 * - télécharger un document de manière sécurisée.
 *
 * Les autorisations fines sont vérifiées via Gate::authorize(), qui délègue
 * ensuite à DocumentPolicy. Ce choix évite d'utiliser $this->authorize(),
 * car le contrôleur de base du projet ne fournit pas cette méthode directement.
 *
 * Les routes restent aussi protégées par les permissions Spatie :
 * - documents.view ;
 * - documents.download.
 *
 * On garde donc deux niveaux de sécurité :
 * - le middleware de route vérifie la permission générale ;
 * - la Policy vérifie si l'utilisateur peut accéder au document précis.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documentService
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // On vérifie que l'utilisateur a le droit de voir au moins un document avant de lancer la requête de récupération (moins coûteux que de récupérer les documents puis de vérifier les droits sur chaque document)
        Gate::authorize('viewAny', Document::class);
        // retourne uniquement les documents visible par l'utilisateur connecté, en appliquant les règles d'accès définies dans DocumentAccessService
        $documents = $this->documentService->listVisibleForUser($request->user());

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);
        // Vérifie si l’utilisateur peut voir ce document précis
        $document->load(['reservation', 'user']);

        return new DocumentResource($document);
    }

    public function download(Document $document): StreamedResponse
    {
        // Vérifie que l’utilisateur peut télécharger ce document précis
        Gate::authorize('download', $document);

        return $this->documentService->download($document);
    }
}
