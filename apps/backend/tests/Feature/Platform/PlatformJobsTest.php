<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
    }

    private function failedJob(string $exception = 'RuntimeException: fallo'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'publishing',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Modules\\Content\\Jobs\\PublishSocialPost', 'attempts' => 3]),
            'exception' => $exception,
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_lista_colas_y_fallidos_sin_secretos(): void
    {
        $this->failedJob('ConnectionException: cURL error 28 for https://graph.facebook.com/v25.0/1/feed?access_token=EAABsecreto&fields=id');

        $response = $this->getJson('/api/v1/platform/jobs')
            ->assertOk()
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.failed.0.name', 'PublishSocialPost')
            ->assertJsonCount(5, 'data.queues');

        $this->assertStringNotContainsString('EAABsecreto', (string) $response->json('data.failed.0.exception'));
    }

    public function test_reintentar_y_descartar_quedan_auditados(): void
    {
        $retry = $this->failedJob();
        $discard = $this->failedJob();

        $this->postJson("/api/v1/platform/jobs/{$retry}/retry")->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $retry]);
        $this->assertDatabaseCount('jobs', 1);

        $this->deleteJson("/api/v1/platform/jobs/{$discard}")->assertOk();
        $this->deleteJson("/api/v1/platform/jobs/{$discard}")->assertNotFound();
        $this->postJson('/api/v1/platform/jobs/' . Str::uuid() . '/retry')->assertNotFound();

        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.failed_jobs_retried']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'superadmin.failed_jobs_discarded']);
    }

    public function test_acciones_masivas(): void
    {
        $this->failedJob();
        $this->failedJob();
        $this->postJson('/api/v1/platform/jobs/retry-all')->assertOk()->assertJsonPath('message', '2 trabajos reencolados.');
        $this->assertDatabaseCount('failed_jobs', 0);

        $this->failedJob();
        $this->deleteJson('/api/v1/platform/jobs')->assertOk()->assertJsonPath('message', '1 trabajo descartado.');
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_solo_superadmin(): void
    {
        [$owner] = $this->createOwnerWithOrganization();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/jobs')->assertForbidden();
        $this->deleteJson('/api/v1/platform/jobs')->assertForbidden();
    }
}
