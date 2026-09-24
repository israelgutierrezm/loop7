<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_sin_el_plan_no_se_configura_y_no_se_aplica(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(); // trial Growth: sin marca blanca

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/organization/branding')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/organization/branding', ['display_name' => 'Agencia Sol'])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.white_label');

        // Lo guardado antes de bajar de plan se ignora.
        $org->forceFill(['branding' => ['display_name' => 'Agencia Sol', 'primary_color' => '#1d4ed8']])->save();
        $this->actingInOrganization($owner, $org)->getJson('/api/v1/context')->assertJsonPath('data.branding', null);
    }

    public function test_configurar_nombre_color_y_logo(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'agency');

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/organization/branding', ['display_name' => 'Agencia Sol', 'primary_color' => '#1D4ED8'])
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#1d4ed8');

        // Un color claro no deja leer el texto blanco de los botones.
        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/organization/branding', ['primary_color' => '#facc15'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_color']);
        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/organization/branding', ['primary_color' => 'azul'])
            ->assertStatus(422);

        $logoUrl = $this->actingInOrganization($owner, $org)
            ->post('/api/v1/organization/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.logo_url');
        $this->assertStringContainsString('signature=', (string) $logoUrl);

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/context')
            ->assertJsonPath('data.branding.name', 'Agencia Sol')
            ->assertJsonPath('data.branding.color', '#1d4ed8');

        // La URL firmada sirve el archivo; sin firma, no.
        $this->get((string) $logoUrl)->assertOk();
        $this->get("/api/v1/branding/{$org->public_id}/logo")->assertForbidden();

        // SVG u otros tipos no se aceptan.
        $this->actingInOrganization($owner, $org)
            ->post('/api/v1/organization/branding/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $path = $org->fresh()->branding['logo_path'];
        $this->actingInOrganization($owner, $org)->deleteJson('/api/v1/organization/branding/logo')->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_solo_quien_edita_la_organizacion_cambia_la_marca_blanca(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'agency');
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);

        $this->actingInOrganization($manager, $org)
            ->putJson('/api/v1/organization/branding', ['display_name' => 'Otra'])
            ->assertForbidden();
    }

    public function test_los_correos_se_presentan_con_la_marca_blanca(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $this->setOrganizationPlan($org, 'agency');
        $org->forceFill(['branding' => ['display_name' => 'Agencia Sol']])->save();

        $mail = (new OrganizationNotice(
            organizationId: $org->id,
            kind: 'test',
            category: NotificationCategory::APPROVALS,
            title: 'Aviso',
            body: 'Cuerpo',
            path: '/app',
        ))->toMail($owner);

        $this->assertSame('Agencia Sol', $mail->from[1]);
        $this->assertSame('Abrir en Agencia Sol', $mail->actionText);
    }

    public function test_logo_de_marca_desde_su_biblioteca(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $otherBrand = Brand::factory()->create(['organization_id' => $org->id]);
        $asset = fn (Brand $b, string $mime) => MediaAsset::query()->create([
            'organization_id' => $org->id, 'brand_id' => $b->id, 'disk' => 'local', 'path' => 'x/' . uniqid(),
            'original_name' => 'logo', 'mime_type' => $mime, 'extension' => 'png', 'size_bytes' => 100,
        ]);
        $image = $asset($brand, 'image/png');

        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/logo", ['media' => $image->public_id])
            ->assertOk()
            ->assertJsonPath('data.logo.id', $image->public_id);

        $brands = collect($this->actingInOrganization($owner, $org)->getJson('/api/v1/context')->json('data.brands'));
        $this->assertSame($image->public_id, $brands->firstWhere('id', $brand->public_id)['logo']['id']);
        $this->assertStringContainsString('signature=', $brands->firstWhere('id', $brand->public_id)['logo']['url']);

        // Ni de otra marca ni un documento.
        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/logo", ['media' => $asset($otherBrand, 'image/png')->public_id])
            ->assertStatus(422);
        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/logo", ['media' => $asset($brand, 'application/pdf')->public_id])
            ->assertStatus(422);

        $this->actingInOrganization($owner, $org)
            ->putJson("/api/v1/brands/{$brand->public_id}/logo", ['media' => null])
            ->assertOk()
            ->assertJsonPath('data.logo', null);
    }
}
