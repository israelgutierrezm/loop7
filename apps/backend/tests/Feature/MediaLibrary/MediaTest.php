<?php

declare(strict_types=1);

namespace Tests\Feature\MediaLibrary;

use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('local');
    }

    public function test_subir_y_listar_una_imagen(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $file = UploadedFile::fake()->image('foto.jpg', 640, 480);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/media", ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.is_image', true)
            ->assertJsonPath('data.original_name', 'foto.jpg');

        $this->assertDatabaseHas('media_assets', ['brand_id' => $brand->id, 'original_name' => 'foto.jpg']);

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/media")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_rechaza_tipos_de_archivo_no_permitidos(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $file = UploadedFile::fake()->create('script.php', 10, 'text/x-php');

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/media", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_respeta_el_limite_de_almacenamiento_del_plan(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'starter'); // storage.gb = 2
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        // Simula 2 GB ya usados.
        MediaAsset::query()->create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'disk' => 'local',
            'path' => 'media/x/used.bin',
            'original_name' => 'used.bin',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2 * 1024 ** 3,
        ]);

        $file = UploadedFile::fake()->image('extra.jpg');

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/media", ['file' => $file])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_no_se_puede_subir_a_una_marca_de_otra_organizacion(): void
    {
        [$ownerA, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $brandB = Brand::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrganization($ownerA, $orgA)
            ->postJson("/api/v1/brands/{$brandB->public_id}/media", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertNotFound();
    }

    public function test_carpetas_etiquetas_y_filtros(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $otherBrand = Brand::factory()->create(['organization_id' => $org->id]);
        $as = fn () => $this->actingInOrganization($owner, $org);
        $base = "/api/v1/brands/{$brand->public_id}/media";

        $folder = $as()->postJson("{$base}/folders", ['name' => 'Campaña verano'])->assertCreated()->json('data.id');
        $as()->postJson("{$base}/folders", ['name' => 'campaña VERANO'])->assertStatus(422); // duplicada
        $foreignFolder = $as()->postJson("/api/v1/brands/{$otherBrand->public_id}/media/folders", ['name' => 'Otra'])->json('data.id');

        $inFolder = $as()->post($base, ['file' => UploadedFile::fake()->image('playa.jpg'), 'folder' => $folder], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.folder.name', 'Campaña verano')
            ->json('data.id');
        $loose = $as()->postJson($base, ['file' => UploadedFile::fake()->create('guia.pdf', 20, 'application/pdf')])->assertCreated()->json('data.id');
        $as()->post($base, ['file' => UploadedFile::fake()->image('x.jpg'), 'folder' => $foreignFolder], ['Accept' => 'application/json'])
            ->assertStatus(422); // carpeta de otra marca

        $as()->getJson("{$base}/folders")->assertOk()
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unfiled', 1);

        // Filtros: carpeta, sin carpeta, tipo y búsqueda.
        $as()->getJson("{$base}?folder={$folder}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inFolder);
        $as()->getJson("{$base}?folder=none")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $loose);
        $as()->getJson("{$base}?type=document")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $loose);
        $as()->getJson("{$base}?type=image")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inFolder);
        $as()->getJson("{$base}?q=PLAY")->assertJsonCount(1, 'data');

        // Etiquetas: se crean al vuelo, sin duplicados, y filtran.
        $as()->patchJson("/api/v1/media/{$inFolder}", ['tags' => ['Verano', 'verano ', 'Producto']])
            ->assertOk()
            ->assertJsonCount(2, 'data.tags');
        $tags = collect($as()->getJson("{$base}/tags")->assertOk()->json('data'));
        $this->assertSame(['Producto', 'Verano'], $tags->pluck('name')->all());
        $as()->getJson("{$base}?tag=" . $tags->firstWhere('name', 'Verano')['id'])->assertJsonCount(1, 'data');

        // Quitar etiquetas elimina las que quedan sin uso; mover a la raíz.
        $as()->patchJson("/api/v1/media/{$inFolder}", ['tags' => [], 'folder' => null])
            ->assertOk()
            ->assertJsonPath('data.folder', null);
        $this->assertDatabaseCount('media_tags', 0);

        // Borrar una carpeta deja sus archivos sin carpeta.
        $as()->patchJson("/api/v1/media/{$loose}", ['folder' => $folder])->assertJsonPath('data.folder.id', $folder);
        $as()->deleteJson("/api/v1/media/folders/{$folder}")->assertOk();
        $this->assertNull(MediaAsset::query()->where('public_id', $loose)->value('folder_id'));
    }

    public function test_organizar_requiere_permiso_de_edicion(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $viewer = $this->addMember($org, \App\Modules\AccessControl\Enums\OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/media/folders", ['name' => 'X'])
            ->assertForbidden();
        $this->actingInOrganization($viewer, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/media/folders")
            ->assertOk();
    }

    public function test_la_url_firmada_sirve_el_archivo(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/media", [
                'file' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertCreated();

        $url = $response->json('data.url');
        $parts = parse_url($url);
        $path = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');

        // La URL firmada funciona sin sesión.
        $this->get($path)->assertOk();

        // Sin firma → 403.
        $this->get($parts['path'])->assertForbidden();
    }
}
