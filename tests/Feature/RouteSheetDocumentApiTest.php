<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RouteSheetDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_generate_route_sheet_document_from_api(): void
    {
        $agent = User::factory()->create();

        $permission = Permission::findOrCreate('documents.generate', 'web');

        $role = Role::findOrCreate('agent_communal', 'web');
        $role->givePermissionTo($permission);

        $agent->assignRole($role);

        Sanctum::actingAs($agent);

        $document = Document::query()->create([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-2026-03-02-2026-03-06-v001.pdf',
            'file_path' => 'documents/route-sheets/2026-03-02-2026-03-06/route-sheet-2026-03-02-2026-03-06-v001.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'checksum' => 'fake-checksum',
            'generation_mode' => 'auto',
            'generation_context' => [
                'period' => [
                    'start_date' => '2026-03-02',
                    'end_date' => '2026-03-06',
                ],
                'rows' => [],
            ],
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->once())
            ->method('generateForPeriod')
            ->with('2026-03-02', '2026-03-06')
            ->willReturn($document);

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $response = $this->postJson('/api/documents/route-sheets/generate', [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-06',
        ]);

        $response->assertCreated();

        $response->assertJsonPath('message', 'Feuille de route générée avec succès.');
        $response->assertJsonPath('document.id', $document->id);
        $response->assertJsonPath('document.document_type', DocumentType::FEUILLE_ROUTE->value);
        $response->assertJsonPath('document.file_name', 'route-sheet-2026-03-02-2026-03-06-v001.pdf');
        $response->assertJsonPath('document.version', 1);
    }

    public function test_start_date_is_required_to_generate_route_sheet_document(): void
    {
        $agent = User::factory()->create();

        $permission = Permission::findOrCreate('documents.generate', 'web');

        $role = Role::findOrCreate('agent_communal', 'web');
        $role->givePermissionTo($permission);

        $agent->assignRole($role);

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/documents/route-sheets/generate', [
            'end_date' => '2026-03-06',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['start_date']);
    }

    public function test_end_date_is_required_to_generate_route_sheet_document(): void
    {
        $agent = User::factory()->create();

        $permission = Permission::findOrCreate('documents.generate', 'web');

        $role = Role::findOrCreate('agent_communal', 'web');
        $role->givePermissionTo($permission);

        $agent->assignRole($role);

        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/documents/route-sheets/generate', [
            'start_date' => '2026-03-02',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['end_date']);
    }

    public function test_user_without_permission_cannot_generate_route_sheet_document(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents/route-sheets/generate', [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-06',
        ]);

        $response->assertForbidden();
    }
}
