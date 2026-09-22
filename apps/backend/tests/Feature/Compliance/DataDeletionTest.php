<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Brands\Models\Brand;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-app-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        // Guardar por modelo para que aplique el cast encrypted:array.
        $facebook = SocialProvider::query()->where('key', 'facebook')->first();
        $facebook->credentials = ['client_id' => 'app-1', 'client_secret' => $this->secret];
        $facebook->save();
    }

    private function signedRequest(string $userId): string
    {
        $payload = ['user_id' => $userId, 'algorithm' => 'HMAC-SHA256', 'issued_at' => time()];
        $encPayload = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $encPayload, $this->secret, true)), '+/', '-_'), '=');

        return $sig . '.' . $encPayload;
    }

    public function test_callback_borra_datos_del_usuario_y_devuelve_codigo(): void
    {
        [, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'facebook',
            'status' => 'connected', 'external_account_id' => 'meta-user-1', 'external_account_name' => 'Juan',
        ]);
        $conversation = InboxConversation::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'social_connection_id' => $connection->id,
            'provider' => 'facebook', 'external_id' => 'c-1', 'type' => 'comment', 'status' => 'open',
            'participant_external_id' => 'meta-user-1', 'participant_name' => 'Juan',
        ]);

        $response = $this->postJson('/api/v1/data-deletion/facebook', [
            'signed_request' => $this->signedRequest('meta-user-1'),
        ])->assertOk();

        $code = $response->json('confirmation_code');
        $this->assertNotEmpty($code);
        $this->assertStringContainsString($code, (string) $response->json('url'));

        // La cola sync ejecuta la purga en el acto.
        $this->assertDatabaseMissing('social_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('inbox_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('data_deletion_requests', [
            'confirmation_code' => $code, 'status' => 'completed', 'external_user_id' => 'meta-user-1',
        ]);
    }

    public function test_firma_invalida_es_rechazada(): void
    {
        $this->postJson('/api/v1/data-deletion/facebook', [
            'signed_request' => 'firmafalsa.' . rtrim(strtr(base64_encode('{"user_id":"x"}'), '+/', '-_'), '='),
        ])->assertStatus(400);
    }

    public function test_falta_signed_request(): void
    {
        $this->postJson('/api/v1/data-deletion/facebook', [])->assertStatus(400);
    }

    public function test_estado_por_codigo(): void
    {
        $response = $this->postJson('/api/v1/data-deletion/facebook', [
            'signed_request' => $this->signedRequest('meta-user-2'),
        ])->assertOk();
        $code = $response->json('confirmation_code');

        $this->getJson("/api/v1/data-deletion/status/{$code}")
            ->assertOk()
            ->assertJsonPath('confirmation_code', $code)
            ->assertJsonPath('status', 'completed');

        $this->getJson('/api/v1/data-deletion/status/NOEXISTE')->assertStatus(404);
    }
}
