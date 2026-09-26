<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Content\Services\PublishingService;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recorrido mínimo de punta a punta por la API (CLAUDE.md → Testing): registro,
 * login, onboarding, crear marca, conectar una cuenta simulada, crear un post,
 * enviarlo a revisión, aprobarlo, programarlo y que el scheduler lo publique.
 */
class MainJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_de_la_alta_a_la_publicacion_programada(): void
    {
        $this->seedRbac();

        // 1. Registro: crea la cuenta, la organización (OWNER) y su prueba.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Dueña',
            'email' => 'ana@agencia.test',
            'password' => 'Secreta-123',
            'password_confirmation' => 'Secreta-123',
            'organization_name' => 'Agencia Sol',
            'accept_terms' => true,
        ])->assertCreated();

        $owner = User::query()->where('email', 'ana@agencia.test')->firstOrFail();
        $org = Organization::query()->where('owner_user_id', $owner->id)->firstOrFail();
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => 'trialing']);

        // 2. Login con sus credenciales.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@agencia.test', 'password' => 'Secreta-123'])->assertOk();
        // A partir de aquí cada persona actúa con su propia sesión (no la de la dueña).
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // 3. Onboarding: el dashboard guía los primeros pasos.
        $api = fn (User $user) => $this->actingInOrganization($user->fresh(), $org);
        $api($owner)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.onboarding.has_brand', false)
            ->assertJsonPath('data.onboarding.has_published', false);

        // 4. Crear la marca.
        $brandId = $api($owner)->postJson('/api/v1/brands', ['name' => 'Café Norte'])->assertCreated()->json('data.id');

        // 5. Conectar una cuenta simulada con su página de destino.
        $api($owner)->postJson("/api/v1/brands/{$brandId}/social/connections/fake/manual", [
            'external_account_name' => 'Café Norte',
            'external_account_id' => 'page-1',
            'access_token' => 'token-simulado',
            'destinations' => [['external_id' => 'page-1', 'name' => 'Café Norte', 'type' => 'page']],
        ])->assertCreated();

        // 6. El equipo: una creadora de contenido y un aprobador.
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $approver = $this->addMember($org, OrganizationRole::APPROVER->value);

        // 7. La creadora escribe el post con su variante y lo envía a revisión.
        $contentId = $api($creator)->postJson("/api/v1/brands/{$brandId}/content", [
            'title' => 'Nuevo café de temporada',
            'body' => 'Llegó el café de otoño.',
            'variants' => [['provider' => 'fake', 'body' => 'Llegó el café de otoño ☕']],
        ])->assertCreated()->json('data.id');
        $api($creator)->postJson("/api/v1/content/{$contentId}/submit")->assertOk();
        $api($creator)->postJson("/api/v1/content/{$contentId}/approve")->assertForbidden();

        // 8. El aprobador lo aprueba.
        $api($approver)->postJson("/api/v1/content/{$contentId}/approve")->assertOk();

        // 9. La dueña lo programa para dentro de 10 minutos.
        $api($owner)->postJson("/api/v1/content/{$contentId}/schedule", [
            'scheduled_at' => now()->addMinutes(10)->toIso8601String(),
        ])->assertOk();
        $this->assertDatabaseHas('content_items', ['public_id' => $contentId, 'status' => 'scheduled']);

        // 10. Llega la hora: el scheduler lo despacha y se publica (cola síncrona en pruebas).
        $this->travel(11)->minutes();
        app(PublishingService::class)->dispatchDue();

        $this->assertDatabaseHas('content_items', ['public_id' => $contentId, 'status' => 'published']);
        $this->assertDatabaseHas('publication_targets', ['organization_id' => $org->id, 'status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.published', 'organization_id' => $org->id]);

        // 11. El onboarding queda completo en lo que depende de la marca.
        $api($owner)->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.onboarding.has_brand', true)
            ->assertJsonPath('data.onboarding.has_connection', true)
            ->assertJsonPath('data.onboarding.has_content', true)
            ->assertJsonPath('data.onboarding.has_published', true);
    }
}
