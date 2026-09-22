<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabeceras_de_seguridad_presentes(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_hsts_no_se_envia_sobre_http(): void
    {
        // En pruebas la petición es HTTP: no debe fijarse HSTS.
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_request_id_se_genera(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_request_id_entrante_valido_se_conserva(): void
    {
        $this->withHeader('X-Request-Id', 'req-abc_123')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-abc_123');
    }

    public function test_request_id_invalido_se_reemplaza(): void
    {
        $response = $this->withHeader('X-Request-Id', 'inseguro con espacios <script>')
            ->getJson('/api/v1/health')
            ->assertOk();

        $this->assertNotSame('inseguro con espacios <script>', $response->headers->get('X-Request-Id'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_auditoria_redacta_secretos(): void
    {
        [, $org] = $this->createOwnerWithOrganization();

        app(AuditLogger::class)->log('test.action', null, [
            'api_key' => 'l7_secreto',
            'access_token' => 'ya29.secreto',
            'nombre' => 'visible',
        ], organizationId: $org->id);

        $row = DB::table('audit_logs')->latest('id')->first();
        $props = json_decode((string) $row->properties, true);

        $this->assertSame('[REDACTED]', $props['api_key']);
        $this->assertSame('[REDACTED]', $props['access_token']);
        $this->assertSame('visible', $props['nombre']);
    }
}
