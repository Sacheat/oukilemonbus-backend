<?php

namespace App\Services\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service de gestion du stockage des documents générés
 * - Stockage des fichiers sur le disque local (configurable via le disque 'local' de Laravel)
 * - Génération d'un checksum pour chaque fichier stocké, afin d'identifier techniquement le contenu généré.
 * - Fournit une méthode de téléchargement sécurisé des fichiers via Laravel Storage
 */
class DocumentStorageService
{
    private const DISK = 'local';

    public function storeGeneratedFile(string $directory, string $fileName, string $content): array
    {
        $cleanDirectory = trim($directory, '/');
        $cleanFileName = basename($fileName);
        // Si un répertoire est fourni, on le préfixe au nom de fichier, sinon on stocke à la racine du disque
        $filePath = $cleanDirectory !== ''
            ? $cleanDirectory.'/'.$cleanFileName
            : $cleanFileName;

        Storage::disk(self::DISK)->put($filePath, $content);

        return [
            'file_path' => $filePath,
            'file_name' => $cleanFileName,
            'mime_type' => $this->guessMimeType($cleanFileName),
            'file_size' => Storage::disk(self::DISK)->size($filePath),
            'checksum' => $this->checksum($filePath),
        ];
    }

    public function exists(string $filePath): bool
    {
        return Storage::disk(self::DISK)->exists($filePath);
    }

    /**
     * Supprime physiquement un fichier du stockage privé.
     *
     * Cette méthode est utilisée notamment en cas d'échec lors de la création
     * de l'enregistrement Document en base de données.
     *
     * Comme le stockage fichier n'est pas transactionnel contrairement à la base
     * de données, cette suppression permet d'éviter de conserver des fichiers
     * orphelins dans storage/app/private.
     */
    public function delete(string $filePath): bool
    {
        if (! $this->exists($filePath)) {
            return false;
        }

        return Storage::disk(self::DISK)->delete($filePath);
    }

    public function checksum(string $filePath): ?string
    {
        if (! $this->exists($filePath)) {
            return null;
        }

        $content = Storage::disk(self::DISK)->get($filePath);

        return hash('sha256', $content);
    }

    public function download(Document $document): StreamedResponse
    {
        return Storage::disk(self::DISK)->download(
            $document->file_path,
            $document->file_name
        );
    }

    private function guessMimeType(string $fileName): string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
