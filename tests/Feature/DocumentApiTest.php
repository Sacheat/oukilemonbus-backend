<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests d'API pour la gestion des documents générés
 * - Vérifie que les endpoints de listing, de détail et de téléchargement des documents appliquent correctement les règles d'accès définies dans DocumentPolicy et DocumentAccessService
 * - S'assure que les utilisateurs ne peuvent voir ou télécharger que les documents auxquels ils ont droit, et que les informations sensibles comme le file_path ne sont pas exposées dans les réponses API
 */
class DocumentApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed([
            RolesAndPermissionsSeeder::class,
        ]);

        Carbon::setTestNow('2026-04-20 09:00:00');

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_list_documents_and_file_path_is_not_exposed(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.document_type', DocumentType::FEUILLE_ROUTE->value)
            ->assertJsonPath('data.0.document_type_label', DocumentType::FEUILLE_ROUTE->label());

        $this->assertArrayNotHasKey('file_path', $response->json('data.0'));
    }

    public function test_related_user_can_show_document_and_file_path_is_not_exposed(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.file_name', 'feuille-route-test.pdf')
            ->assertJsonPath('data.template_key', 'route_sheets.default');

        $this->assertArrayNotHasKey('file_path', $response->json('data'));
    }

    public function test_unrelated_user_cannot_show_document(): void
    {
        $owner = $this->userWithRole('user');
        $stranger = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_normal_user_cannot_show_unavailable_document(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user, isAvailable: false);

        Sanctum::actingAs($user);

        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_admin_can_show_unavailable_document(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('user');

        $document = $this->createStoredDocument($owner, isAvailable: false);

        Sanctum::actingAs($admin);

        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.is_available', false);
    }

    public function test_user_can_download_own_available_document(): void
    {
        $user = $this->userWithRole('user');
        $document = $this->createStoredDocument($user);

        Sanctum::actingAs($user);

        $response = $this->get("/api/documents/{$document->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Disposition');

        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_download_returns_not_found_when_file_is_missing(): void
    {
        $user = $this->userWithRole('user');

        $document = Document::create([
            'user_id' => $user->id,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'fichier-manquant.pdf',
            'file_path' => 'documents/tests/fichier-manquant.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'checksum' => hash('sha256', 'missing'),
            'generation_mode' => 'auto',
            'generation_context' => [
                'source' => 'test',
            ],
            'template_key' => 'route_sheets.default',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->get("/api/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_normal_user_only_lists_visible_related_documents(): void
    {
        $user = $this->userWithRole('user');
        $otherUser = $this->userWithRole('user');

        $visibleDocument = $this->createStoredDocument($user);
        $this->createStoredDocument($otherUser);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleDocument->id);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createStoredDocument(User $user, bool $isAvailable = true): Document
    {
        $content = 'PDF TEST CONTENT';
        $filePath = 'documents/tests/feuille-route-test.pdf';

        Storage::disk('local')->put($filePath, $content);

        return Document::create([
            'user_id' => $user->id,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'feuille-route-test.pdf',
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'generation_mode' => 'auto',
            'generation_context' => [
                'source' => 'test',
                'template' => 'route_sheets.default',
            ],
            'template_key' => 'route_sheets.default',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => $isAvailable,
        ]);
    }
}
