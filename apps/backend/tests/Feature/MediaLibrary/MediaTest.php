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
