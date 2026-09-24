<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Ai\Enums\AiOperation;
use App\Modules\Ai\Services\AiGenerationService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array{0: Organization, 1: Brand}
     */
    private function orgWithBrand(): array
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);

        return [$org, $brand];
    }

    public function test_owner_genera_texto_consume_creditos_y_registra_uso(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/text", [
                'prompt' => 'Lanzamiento de nuestro nuevo producto',
                'operation' => 'generate_post',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'fake')
            ->assertJsonPath('data.byok', false)
            ->assertJsonPath('data.credits', AiOperation::GENERATE_POST->defaultCredits());

        // Se acumuló el consumo del periodo actual.
        $this->assertDatabaseHas('usage_counters', [
            'organization_id' => $org->id,
            'key' => 'ai_credits.month',
            'period' => Carbon::now()->format('Y-m'),
            'used' => AiOperation::GENERATE_POST->defaultCredits(),
        ]);

        // Se registró el uso (sin prompt en claro).
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'provider' => 'fake',
            'operation' => 'generate_post',
            'status' => 'succeeded',
        ]);
    }

    public function test_content_creator_puede_generar_texto(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);

        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/text", ['prompt' => 'Idea de post'])
            ->assertOk();
    }

    public function test_viewer_no_puede_generar_texto(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);

        $this->actingInOrganization($viewer, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/text", ['prompt' => 'Idea de post'])
            ->assertForbidden();
    }

    public function test_sin_creditos_devuelve_402(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $owner = $org->owner;

        // Agota los créditos del periodo.
        DB::table('usage_counters')->insert([
            'organization_id' => $org->id,
            'key' => 'ai_credits.month',
            'period' => Carbon::now()->format('Y-m'),
            'used' => 1_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/text", ['prompt' => 'Otro post'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_no_puede_generar_para_brand_de_otra_organizacion(): void
    {
        [$orgA] = $this->orgWithBrand();
        [, $brandB] = $this->orgWithBrand();
        $ownerA = $orgA->owner;

        // Acotado por OrganizationScope: la brand de otra Org no se resuelve (anti-IDOR).
        $this->actingInOrganization($ownerA, $orgA)
            ->postJson("/api/v1/brands/{$brandB->public_id}/ai/text", ['prompt' => 'Post'])
            ->assertNotFound();
    }

    public function test_genera_imagen(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $owner = $org->owner;

        \Illuminate\Support\Facades\Storage::fake('local');

        $response = $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/image", ['prompt' => 'Foto de producto minimalista'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'fake')
            ->assertJsonPath('data.credits', AiOperation::GENERATE_IMAGE->defaultCredits())
            ->assertJsonCount(1, 'data.media');

        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $org->id,
            'modality' => 'image',
            'status' => 'succeeded',
        ]);

        // La imagen queda en la biblioteca de la marca (PNG real) para adjuntarla a publicaciones.
        $asset = \App\Modules\MediaLibrary\Models\MediaAsset::query()->withoutGlobalScopes()
            ->where('public_id', $response->json('data.media.0.id'))->firstOrFail();
        $this->assertSame($brand->id, $asset->brand_id);
        $this->assertSame('image/png', $asset->mime_type);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($asset->path);
    }

    public function test_fallo_del_proveedor_no_consume_creditos_y_registra_fallo(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $owner = $org->owner;

        try {
            app(AiGenerationService::class)->generateText(
                $brand,
                $owner,
                'Esto va a fallar [[FAIL]]',
                AiOperation::GENERATE_POST,
            );
            $this->fail('Se esperaba una excepción del proveedor.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $org->id,
            'status' => 'failed',
        ]);
        // No se consumieron créditos.
        $this->assertDatabaseMissing('usage_counters', [
            'organization_id' => $org->id,
            'key' => 'ai_credits.month',
        ]);
    }

    public function test_byok_no_disponible_en_plan_sin_feature(): void
    {
        [$org] = $this->orgWithBrand(); // trial = Growth (sin BYOK)
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/ai/keys', [
                'provider' => 'fake',
                'credentials' => ['api_key' => 'sk-propia-123'],
            ])
            ->assertStatus(402)
            ->assertJsonPath('errors.entitlement', 'feature.byok');
    }

    public function test_con_byok_usa_clave_propia_y_no_consume_creditos(): void
    {
        [$org, $brand] = $this->orgWithBrand();
        $this->setOrganizationPlan($org, 'professional'); // feature.byok = true
        $owner = $org->owner;

        // Guarda la clave propia (cifrada, no vuelve al navegador).
        $response = $this->actingInOrganization($owner, $org)
            ->postJson('/api/v1/ai/keys', [
                'provider' => 'fake',
                'credentials' => ['api_key' => 'sk-propia-123'],
            ])
            ->assertOk();
        $this->assertStringNotContainsString('sk-propia-123', $response->getContent());

        // Generar ahora usa BYOK → 0 créditos.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/brands/{$brand->public_id}/ai/text", ['prompt' => 'Post con clave propia'])
            ->assertOk()
            ->assertJsonPath('data.byok', true)
            ->assertJsonPath('data.credits', 0);

        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $org->id,
            'byok' => true,
            'credits' => 0,
        ]);
        $this->assertDatabaseMissing('usage_counters', [
            'organization_id' => $org->id,
            'key' => 'ai_credits.month',
        ]);
    }

    public function test_endpoint_de_uso_devuelve_creditos(): void
    {
        [$org] = $this->orgWithBrand();
        $owner = $org->owner;

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/ai/usage')
            ->assertOk()
            ->assertJsonPath('data.period', Carbon::now()->format('Y-m'))
            ->assertJsonPath('data.limit', 500) // Growth
            ->assertJsonPath('data.used', 0)
            ->assertJsonPath('data.byok_available', false);
    }

    public function test_superadmin_configura_proveedor_de_ia(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $this->putJson('/api/v1/platform/ai-providers/openai', ['is_enabled' => true, 'is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_default', true);

        // La clave se guarda cifrada y no vuelve en claro.
        $secret = 'sk-openai-XYZ987';
        $response = $this->putJson('/api/v1/platform/ai-providers/openai/credentials', [
            'credentials' => ['api_key' => $secret],
        ])->assertOk();
        $this->assertStringNotContainsString($secret, $response->getContent());

        $this->assertDatabaseHas('ai_providers', ['key' => 'openai', 'is_enabled' => true, 'is_default' => true]);
        // Sólo un proveedor por defecto: el fake dejó de serlo.
        $this->assertDatabaseHas('ai_providers', ['key' => 'fake', 'is_default' => false]);
    }
}
