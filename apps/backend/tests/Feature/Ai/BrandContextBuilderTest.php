<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\Services\BrandContextBuilder;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandAudience;
use App\Modules\Brands\Models\BrandGuideline;
use App\Modules\Brands\Models\BrandKnowledgeItem;
use App\Modules\Brands\Models\BrandProduct;
use App\Modules\Brands\Models\BrandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_contexto_usa_todo_el_brand_brain_de_la_marca(): void
    {
        $this->seedRbac();
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Norte']);
        $other = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Otra']);
        $ids = ['organization_id' => $org->id, 'brand_id' => $brand->id];

        BrandGuideline::query()->create([...$ids, 'voice_tone' => 'Cercano', 'notes' => 'Nunca hablar de precios de la competencia']);
        BrandAudience::query()->create([...$ids, 'name' => 'Oficinistas', 'description' => 'Compran café para llevar']);
        BrandProduct::query()->create([...$ids, 'name' => 'Espresso', 'price' => '$35', 'description' => 'Doble carga']);
        BrandService::query()->create([...$ids, 'name' => 'Catering', 'url' => 'https://cafenorte.test/catering']);
        BrandKnowledgeItem::query()->create([...$ids, 'type' => 'faq', 'title' => '¿Abren domingos?', 'body' => 'Sí, de 9 a 14 h']);
        BrandProduct::query()->create(['organization_id' => $org->id, 'brand_id' => $other->id, 'name' => 'Producto ajeno']);

        $context = app(BrandContextBuilder::class)->build($brand);

        $this->assertStringContainsString('Nunca hablar de precios de la competencia', $context);
        $this->assertStringContainsString('Oficinistas: Compran café para llevar', $context);
        $this->assertStringContainsString('Espresso ($35): Doble carga', $context);
        $this->assertStringContainsString('Catering — https://cafenorte.test/catering', $context);
        $this->assertStringContainsString('P: ¿Abren domingos?', $context);
        $this->assertStringContainsString('R: Sí, de 9 a 14 h', $context);
        $this->assertStringNotContainsString('Producto ajeno', $context);
    }

    public function test_los_textos_largos_se_recortan(): void
    {
        $this->seedRbac();
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        BrandKnowledgeItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id,
            'type' => 'note', 'title' => 'Historia', 'body' => str_repeat('palabra ', 2000),
        ]);

        $this->assertLessThan(3000, mb_strlen(app(BrandContextBuilder::class)->build($brand)));
    }
}
