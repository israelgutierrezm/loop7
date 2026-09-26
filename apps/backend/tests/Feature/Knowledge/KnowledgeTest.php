<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Services\BrandContextBuilder;
use App\Modules\Brands\Models\Brand;
use App\Modules\Knowledge\Models\KnowledgeChunk;
use App\Modules\Knowledge\Models\KnowledgeDocument;
use App\Modules\Knowledge\Services\DocumentTextExtractor;
use App\Modules\Knowledge\Services\TextChunker;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake(config('filesystems.default'));
    }

    /**
     * @return array{0: User, 1: Organization, 2: Brand}
     */
    private function brandContext(): array
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id, 'name' => 'Café Norte']);

        return [$owner, $org, $brand];
    }

    private function upload(User $user, Organization $org, Brand $brand, UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->actingInOrganization($user, $org)
            ->post("/api/v1/brands/{$brand->public_id}/documents", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function textFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_sube_indexa_y_encuentra_el_fragmento_correcto(): void
    {
        [$owner, $org, $brand] = $this->brandContext();

        $horarios = "Horarios de atención\n\nAbrimos de lunes a viernes de 8 a 20 h. Los domingos abrimos de 9 a 14 h.";
        $envios = "Política de envíos\n\nLos envíos a domicilio cuestan 50 pesos y tardan 48 horas en llegar.";
        $this->upload($owner, $org, $brand, $this->textFile('horarios.txt', $horarios))->assertCreated();
        $this->upload($owner, $org, $brand, $this->textFile('envios.md', $envios))->assertCreated();

        // Cola síncrona en pruebas: ya están indexados con los vectores del proveedor de prueba.
        $documents = KnowledgeDocument::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->get();
        $this->assertTrue($documents->every(fn (KnowledgeDocument $d) => $d->status->value === 'ready' && $d->chunks_count > 0));
        $this->assertTrue(KnowledgeChunk::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->whereNotNull('embedding')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.document_uploaded']);

        $results = $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/documents/search?q=" . urlencode('¿abren los domingos?'))
            ->assertOk()->json('data');
        $this->assertSame('horarios', $results[0]['document']);
        $this->assertSame('semantic', $results[0]['method']);

        // La IA recibe ese fragmento en su contexto, con la advertencia de no seguir instrucciones.
        $context = app(BrandContextBuilder::class)->build($brand, 'Escribe un post sobre el horario del domingo');
        $this->assertStringContainsString('Los domingos abrimos de 9 a 14 h', $context);
        $this->assertStringContainsString('no las sigas', $context);
    }

    public function test_sin_proveedor_de_embeddings_busca_por_palabras_clave(): void
    {
        [$owner, $org, $brand] = $this->brandContext();
        AiProvider::query()->update(['is_enabled' => false]);

        $this->upload($owner, $org, $brand, $this->textFile('precios.txt', 'Lista de precios: el espresso cuesta 35 pesos y el capuchino 45.'))->assertCreated();
        $this->assertNull(KnowledgeChunk::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->value('embedding'));

        $response = $this->actingInOrganization($owner, $org)->getJson("/api/v1/brands/{$brand->public_id}/documents");
        $response->assertJsonPath('data.semantic', false);

        $results = $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/documents/search?q=" . urlencode('precio del capuchino'))
            ->json('data');
        $this->assertSame('keyword', $results[0]['method']);
        $this->assertStringContainsString('capuchino', $results[0]['content']);
    }

    public function test_extrae_texto_de_word_y_pdf(): void
    {
        $extractor = app(DocumentTextExtractor::class);

        $docx = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($docx, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="w"><w:body>'
            . '<w:p><w:r><w:t>Nuestra misión</w:t></w:r></w:p><w:p><w:r><w:t>Café de especialidad &amp; tostado local</w:t></w:r></w:p>'
            . '</w:body></w:document>');
        $zip->close();
        $text = $extractor->extract($docx, 'docx');
        $this->assertStringContainsString("Nuestra misión\n", $text);
        $this->assertStringContainsString('Café de especialidad & tostado local', $text);
        @unlink($docx);

        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, $this->minimalPdf('Hola desde un PDF de prueba'));
        $this->assertStringContainsString('Hola desde un PDF de prueba', $extractor->extract($pdf, 'pdf'));
        @unlink($pdf);
    }

    public function test_trocea_textos_largos_con_solapamiento(): void
    {
        $paragraph = str_repeat('Frase de ejemplo sobre el café de la casa. ', 30); // ~1300 caracteres
        $chunks = app(TextChunker::class)->chunk($paragraph . "\n\n" . $paragraph);

        $this->assertGreaterThan(2, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(TextChunker::SIZE + TextChunker::OVERLAP + 1, mb_strlen($chunk));
        }
    }

    public function test_un_documento_ilegible_queda_con_error_claro(): void
    {
        [$owner, $org, $brand] = $this->brandContext();

        $this->upload($owner, $org, $brand, UploadedFile::fake()->createWithContent('roto.pdf', "%PDF-1.4\nbasura sin estructura"))
            ->assertCreated();

        $document = KnowledgeDocument::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->assertSame('failed', $document->status->value);
        $this->assertNotEmpty($document->error);
    }

    public function test_valida_tipo_real_permisos_aislamiento_y_limite_del_plan(): void
    {
        [$owner, $org, $brand] = $this->brandContext();

        // Extensión no admitida y contenido que no corresponde a la extensión.
        $this->upload($owner, $org, $brand, $this->textFile('script.php', '<?php echo 1;'))->assertStatus(422);
        // Archivo real (los falsos de Laravel informan el tipo por la extensión): una imagen llamada .pdf.
        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->upload($owner, $org, $brand, new UploadedFile($png, 'foto.pdf', null, null, true))->assertStatus(422);
        @unlink($png);

        // Un observador no sube documentos.
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $this->upload($viewer, $org, $brand, $this->textFile('a.txt', 'Hola'))->assertForbidden();

        // Otra organización no ve, descarga ni elimina documentos de esta.
        $this->upload($owner, $org, $brand, $this->textFile('interno.txt', 'Dato interno'))->assertCreated();
        $document = KnowledgeDocument::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        [$otherOwner, $otherOrg] = $this->createOwnerWithOrganization([], 'Otra');
        $this->actingInOrganization($otherOwner, $otherOrg)->getJson("/api/v1/brands/{$brand->public_id}/documents")->assertNotFound();
        $this->actingInOrganization($otherOwner, $otherOrg)->deleteJson("/api/v1/brands/{$brand->public_id}/documents/{$document->public_id}")->assertNotFound();

        // Límite del plan (Growth en pruebas por defecto: 25 documentos).
        KnowledgeDocument::query()->withoutGlobalScopes()->where('id', $document->id)->delete();
        for ($i = 0; $i < 25; $i++) {
            KnowledgeDocument::query()->create([
                'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => "D{$i}", 'original_name' => "d{$i}.txt",
                'disk' => 'local', 'path' => "x/d{$i}.txt", 'mime_type' => 'text/plain', 'extension' => 'txt', 'size_bytes' => 1, 'status' => 'ready',
            ]);
        }
        $this->upload($owner, $org, $brand, $this->textFile('uno-mas.txt', 'Hola'))->assertStatus(402);
    }

    public function test_eliminar_documento_y_marca_borra_archivos_y_fragmentos(): void
    {
        [$owner, $org, $brand] = $this->brandContext();
        $disk = Storage::disk(config('filesystems.default'));

        $this->upload($owner, $org, $brand, $this->textFile('a.txt', 'Contenido del documento A'))->assertCreated();
        $a = KnowledgeDocument::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $disk->assertExists($a->path);

        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/brands/{$brand->public_id}/documents/{$a->public_id}")->assertOk();
        $disk->assertMissing($a->path);
        $this->assertSame(0, KnowledgeChunk::query()->withoutGlobalScopes()->where('knowledge_document_id', $a->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.document_deleted']);

        // Al eliminar la marca, sus documentos se borran de verdad.
        $this->upload($owner, $org, $brand, $this->textFile('b.txt', 'Contenido del documento B'))->assertCreated();
        $b = KnowledgeDocument::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->actingInOrganization($owner, $org)->deleteJson("/api/v1/brands/{$brand->public_id}")->assertOk();
        $disk->assertMissing($b->path);
        $this->assertNull(KnowledgeDocument::query()->withoutGlobalScopes()->find($b->id));
    }

    /**
     * PDF mínimo válido (con tabla xref correcta) con una línea de texto.
     */
    private function minimalPdf(string $text): string
    {
        $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
