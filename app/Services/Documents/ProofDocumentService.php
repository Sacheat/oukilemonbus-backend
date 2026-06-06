<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stocke et valide les justificatifs téléversés par les utilisateurs (PDF, JPG, PNG).
 *
 * Pipeline de sécurité (validateContent) :
 *   1. Pas de null byte dans le nom de fichier (path traversal)
 *   2. Pas de double extension (ex. virus.pdf.php)
 *   3. Taille ≤ max_size_bytes (configurable, défaut 5 Mo)
 *   4. Extension dans la liste blanche (pdf, jpg, jpeg, png)
 *   5. MIME réel via finfo (défense contre les fichiers renommés)
 *   6. Cohérence extension déclarée / MIME réel
 *   7. Magic bytes correspondant au MIME (%PDF, \xFF\xD8\xFF, \x89PNG)
 *   8. Absence de signatures malveillantes génériques (<?php, eval(, exec(, …)
 *   9. Pour les PDF : absence de patterns dangereux (/JS, /OpenAction, /Launch, /XFA, …)
 *
 * Le nom de fichier stocké est un UUID pour éliminer tout vecteur d'injection via le nom original.
 * En cas d'échec DB après stockage, le fichier est supprimé pour éviter les orphelins.
 * `delete()` supprime à la fois l'enregistrement DB et le fichier physique.
 */
class ProofDocumentService
{
    // Signatures génériques dangereuses (tous types de fichiers)
    private const GENERIC_DANGEROUS_SIGNATURES = [
        '<?php',
        '<?=',
        '<script',
        'eval(',
        'base64_decode(',
        'system(',
        'exec(',
        'passthru(',
        'shell_exec(',
    ];

    // Patterns spécifiques aux PDFs malveillants
    private const PDF_DANGEROUS_PATTERNS = [
        '/JS',          // Objet JavaScript embarqué
        '/JavaScript',  // Stream JavaScript
        '/OpenAction',  // Action auto-exécutée à l'ouverture
        '/Launch',      // Lance un programme externe
        '/EmbeddedFile',// Fichier embarqué dans le PDF
        '/XFA',         // XML Forms Architecture (vecteur d'attaque courant)
        '/RichMedia',   // Contenu riche (Flash, etc.)
        '/AA ',         // Automatic Actions (avec espace pour éviter faux positifs)
    ];

    // Magic bytes attendus par type MIME réel
    private const MAGIC_BYTES = [
        'application/pdf' => '%PDF',
        'image/jpeg'      => "\xFF\xD8\xFF",
        'image/png'       => "\x89PNG",
    ];

    public function __construct(
        private readonly DocumentStorageService $storageService,
    ) {}

    public function store(UploadedFile $file, string $ownerType, int $ownerId): Document
    {
        $this->validateContent($file);

        $directory = "documents/proofs/{$ownerType}/{$ownerId}";
        $fileName  = Str::uuid().'.'.$this->safeExtension($file);

        $storedFilePath = null;

        try {
            return DB::transaction(function () use ($file, $directory, $fileName, &$storedFilePath): Document {
                $content        = $file->get();
                $stored         = $this->storageService->storeGeneratedFile($directory, $fileName, $content);
                $storedFilePath = $stored['file_path'];

                return Document::create([
                    'document_type'   => DocumentType::JUSTIFICATIF,
                    'file_name'       => $stored['file_name'],
                    'file_path'       => $stored['file_path'],
                    'mime_type'       => $stored['mime_type'],
                    'file_size'       => $stored['file_size'],
                    'checksum'        => $stored['checksum'],
                    'generation_mode' => 'manuel',
                    'generated_at'    => now(),
                    'is_official'     => false,
                    'version'         => 1,
                    'is_available'    => true,
                ]);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                $this->storageService->delete($storedFilePath);
            }

            throw $exception;
        }
    }

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $document->update(['is_available' => false]);
            $this->storageService->delete($document->file_path);
            $document->delete();
        });
    }

    // ── Pipeline de validation ────────────────────────────────────────────────

    private function validateContent(UploadedFile $file): void
    {
        $this->ensureNoNullByte($file);
        $this->ensureNoDoubleExtension($file);
        $this->ensureSizeAllowed($file);
        $this->ensureExtensionAllowed($file);

        $realMime = $this->resolveRealMime($file);
        $this->ensureMimeAllowed($realMime, $file);
        $this->ensureMagicBytesMatch($file, $realMime);

        $content = $this->readContent($file);
        $this->ensureNoGenericMaliciousContent($content);

        if ($realMime === 'application/pdf') {
            $this->ensureNoPdfMaliciousContent($content);
        }
    }

    // ── Vérifications nom de fichier ──────────────────────────────────────────

    private function ensureNoNullByte(UploadedFile $file): void
    {
        if (str_contains($file->getClientOriginalName(), "\0")) {
            throw ValidationException::withMessages([
                'proof_document' => 'Nom de fichier invalide.',
            ]);
        }
    }

    private function ensureNoDoubleExtension(UploadedFile $file): void
    {
        // Retire la dernière extension et vérifie qu'il n'en reste pas une autre
        $nameWithoutLastExt = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        if (pathinfo($nameWithoutLastExt, PATHINFO_EXTENSION) !== '') {
            throw ValidationException::withMessages([
                'proof_document' => 'Le nom de fichier contient plusieurs extensions, ce qui est interdit.',
            ]);
        }
    }

    // ── Vérifications taille et extension ────────────────────────────────────

    private function ensureSizeAllowed(UploadedFile $file): void
    {
        $max = (int) config('uploads.proof_documents.max_size_bytes', 5 * 1024 * 1024);

        if ($file->getSize() > $max) {
            $maxMb = round($max / 1024 / 1024, 1);
            throw ValidationException::withMessages([
                'proof_document' => "Le fichier ne doit pas depasser {$maxMb} Mo.",
            ]);
        }
    }

    private function ensureExtensionAllowed(UploadedFile $file): void
    {
        $allowed = config('uploads.proof_documents.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png']);
        $ext     = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, $allowed, true)) {
            throw ValidationException::withMessages([
                'proof_document' => 'Format non autorise. Seuls les fichiers PDF, JPG et PNG sont acceptes.',
            ]);
        }
    }

    // ── Vérifications MIME et magic bytes ────────────────────────────────────

    private function resolveRealMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($file->getRealPath()) ?: 'application/octet-stream';
    }

    private function ensureMimeAllowed(string $realMime, UploadedFile $file): void
    {
        $allowed = config('uploads.proof_documents.allowed_mimes', [
            'application/pdf', 'image/jpeg', 'image/png',
        ]);

        if (! in_array($realMime, $allowed, true)) {
            throw ValidationException::withMessages([
                'proof_document' => "Type de fichier invalide. PDF, JPEG ou PNG uniquement.",
            ]);
        }

        // Cohérence extension déclarée / MIME réel
        $ext = strtolower($file->getClientOriginalExtension());
        $expectedMime = match ($ext) {
            'pdf'        => 'application/pdf',
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            default      => null,
        };

        if ($expectedMime !== null && $realMime !== $expectedMime) {
            throw ValidationException::withMessages([
                'proof_document' => "L extension du fichier ne correspond pas a son contenu reel.",
            ]);
        }
    }

    private function ensureMagicBytesMatch(UploadedFile $file, string $realMime): void
    {
        $expected = self::MAGIC_BYTES[$realMime] ?? null;

        if ($expected === null) {
            return;
        }

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'proof_document' => 'Impossible de lire le fichier.',
            ]);
        }

        $header = fread($handle, strlen($expected));
        fclose($handle);

        if ($header !== $expected) {
            throw ValidationException::withMessages([
                'proof_document' => 'La structure du fichier est invalide.',
            ]);
        }
    }

    // ── Vérifications contenu ─────────────────────────────────────────────────

    private function readContent(UploadedFile $file): string
    {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            throw ValidationException::withMessages([
                'proof_document' => 'Impossible de lire le fichier.',
            ]);
        }

        return $content;
    }

    private function ensureNoGenericMaliciousContent(string $content): void
    {
        foreach (self::GENERIC_DANGEROUS_SIGNATURES as $signature) {
            if (str_contains($content, $signature)) {
                throw ValidationException::withMessages([
                    'proof_document' => 'Le fichier contient du contenu non autorise.',
                ]);
            }
        }
    }

    private function ensureNoPdfMaliciousContent(string $content): void
    {
        foreach (self::PDF_DANGEROUS_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                throw ValidationException::withMessages([
                    'proof_document' => 'Le fichier PDF contient des elements potentiellement dangereux (scripts ou actions automatiques).',
                ]);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function safeExtension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
    }
}