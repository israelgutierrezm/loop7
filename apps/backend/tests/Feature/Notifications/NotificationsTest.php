<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\AccessControl\Enums\OrganizationRole;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Events\ContentPublicationFailed;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Inbox\Models\InboxConversation;
use App\Modules\Notifications\Notifications\OrganizationNotice;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return list<int>
     */
    private function notifiedIds(string $kind): array
    {
        return DB::table('notifications')->where('type', $kind)
            ->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * @param  list<User>  $users
     * @return list<int>
     */
    private function ids(array $users): array
    {
        return collect($users)->map(fn (User $u) => $u->id)->sort()->values()->all();
    }

    public function test_enviar_a_revision_avisa_a_los_aprobadores_con_acceso_a_la_marca(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(); // trial Growth: con aprobaciones
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $approver = $this->addMember($org, OrganizationRole::APPROVER->value);
        $otherBrandApprover = $this->addMember($org, OrganizationRole::APPROVER->value, allBrandsAccess: false);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Lanzamiento',
            'created_by_user_id' => $creator->id,
        ]);

        $this->actingInOrganization($creator, $org)
            ->postJson("/api/v1/content/{$content->public_id}/submit")
            ->assertOk();

        $this->assertSame($this->ids([$owner, $approver]), $this->notifiedIds('content.submitted'));
        $this->assertNotContains($otherBrandApprover->id, $this->notifiedIds('content.submitted'));

        // El aprobador pide cambios: se avisa al autor, no a quien revisó.
        $this->actingInOrganization($approver, $org)
            ->postJson("/api/v1/content/{$content->public_id}/request-changes", ['note' => 'Cambia la imagen'])
            ->assertOk();

        $this->assertSame([$creator->id], $this->notifiedIds('content.changes_requested'));
        $data = json_decode((string) DB::table('notifications')->where('type', 'content.changes_requested')->value('data'), true);
        $this->assertStringContainsString('Cambia la imagen', $data['body']);
        $this->assertSame('warning', $data['level']);
    }

    public function test_la_campana_lista_solo_los_avisos_propios_de_la_organizacion_actual(): void
    {
        [$owner, $orgA] = $this->createOwnerWithOrganization([], 'Org A');
        [, $orgB] = $this->createOwnerWithOrganization([], 'Org B');
        $orgB->users()->attach($owner->id, ['status' => 'active', 'all_brands_access' => true, 'joined_at' => now()]);
        $other = $this->addMember($orgA, OrganizationRole::VIEWER->value);

        $notice = fn (Organization $org, string $title) => new OrganizationNotice(
            organizationId: $org->id,
            kind: 'test.notice',
            category: \App\Modules\Notifications\Enums\NotificationCategory::APPROVALS,
            title: $title,
            body: 'Cuerpo',
            path: '/app',
            mailable: false,
        );
        $owner->notify($notice($orgA, 'De A 1'));
        $this->travel(1)->minutes();
        $owner->notify($notice($orgA, 'De A 2'));
        $owner->notify($notice($orgB, 'De B'));
        $other->notify($notice($orgA, 'De otro usuario'));

        $list = $this->actingInOrganization($owner, $orgA)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(['De A 2', 'De A 1'], collect($list->json('data'))->pluck('title')->all());
        $this->assertSame(2, $list->json('meta.unread'));

        $id = $list->json('data.0.id');
        $this->actingInOrganization($owner, $orgA)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('meta.unread', 1);

        // El aviso de otro usuario no se puede tocar.
        $foreign = DB::table('notifications')->where('notifiable_id', $other->id)->value('id');
        $this->actingInOrganization($owner, $orgA)->postJson("/api/v1/notifications/{$foreign}/read")->assertNotFound();

        $this->actingInOrganization($owner, $orgA)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->actingInOrganization($owner, $orgA)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread', 0);

        // Los de la Org B siguen sin leer.
        $this->actingInOrganization($owner, $orgB)
            ->getJson('/api/v1/notifications?unread=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'De B');
    }

    public function test_preferencias_de_correo_por_categoria(): void
    {
        Notification::fake();
        [$owner, $org] = $this->createOwnerWithOrganization();

        $this->actingInOrganization($owner, $org)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.categories.0.key', 'approvals')
            ->assertJsonPath('data.categories.0.mail', true);

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/notification-preferences', ['mail' => ['billing' => false, 'inbox' => true]])
            ->assertOk();

        $owner->refresh();
        app(SubscriptionService::class)->enterGrace(
            Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail(),
            'payment_failed',
        );

        Notification::assertSentTo($owner, OrganizationNotice::class, fn (OrganizationNotice $n, array $channels) => $n->kind === 'billing.payment_failed'
            && ! in_array('mail', $channels, true));

        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/notification-preferences', ['mail' => ['desconocida' => 'x']])
            ->assertOk(); // claves desconocidas se ignoran
        $this->actingInOrganization($owner, $org)
            ->putJson('/api/v1/me/notification-preferences', ['mail' => ['billing' => 'quizá']])
            ->assertStatus(422);
    }

    public function test_avisos_de_facturacion_llegan_a_quien_gestiona_el_billing(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $billing = $this->addMember($org, OrganizationRole::BILLING->value);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $subscription = Subscription::query()->withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        // Aviso de fin de prueba: una sola vez aunque el sync corra varias veces.
        $subscription->forceFill(['trial_ends_at' => now()->addDays(2), 'current_period_end' => now()->addDays(2)])->save();
        app(SubscriptionService::class)->syncDue();
        app(SubscriptionService::class)->syncDue();

        $this->assertSame($this->ids([$owner, $billing]), $this->notifiedIds('billing.trial_ending'));
        $this->assertNotContains($creator->id, $this->notifiedIds('billing.trial_ending'));

        // Al ampliar la prueba se podrá volver a avisar.
        app(SubscriptionService::class)->extendTrial($subscription->fresh(), 10);
        $this->assertNull($subscription->fresh()->trial_reminder_sent_at);
        $this->assertSame($this->ids([$owner, $billing]), $this->notifiedIds('billing.trial_extended'));
    }

    public function test_fallo_de_publicacion_avisa_al_autor_y_al_aprobador(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $creator = $this->addMember($org, OrganizationRole::CONTENT_CREATOR->value);
        $content = ContentItem::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Oferta',
            'status' => 'failed', 'created_by_user_id' => $creator->id, 'approved_by_user_id' => $owner->id,
        ]);

        ContentPublicationFailed::dispatch($content);

        $this->assertSame($this->ids([$owner, $creator]), $this->notifiedIds('content.publish_failed'));
    }

    public function test_conexion_caducada_avisa_a_quien_puede_reconectarla(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $manager = $this->addMember($org, OrganizationRole::MANAGER->value);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value);
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Mi página',
        ]);

        app(SocialConnectionService::class)->markExpired($connection, 'Token revocado');
        app(SocialConnectionService::class)->markExpired($connection->fresh(), 'Otra vez'); // ya expirada: no repite

        $this->assertSame($this->ids([$owner, $manager]), $this->notifiedIds('social.connection_expired'));
        $this->assertNotContains($viewer->id, $this->notifiedIds('social.connection_expired'));
    }

    public function test_asignar_conversacion_avisa_al_asignado_y_valida_su_acceso(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization(); // Growth: con inbox
        $brand = Brand::factory()->create(['organization_id' => $org->id]);
        $agent = $this->addMember($org, OrganizationRole::MANAGER->value);
        $viewer = $this->addMember($org, OrganizationRole::VIEWER->value); // sin permiso de inbox
        $connection = SocialConnection::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'provider' => 'fake',
            'status' => 'connected', 'external_account_name' => 'Demo',
        ]);
        $conversation = InboxConversation::query()->create([
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'social_connection_id' => $connection->id,
            'provider' => 'fake', 'external_id' => 'c-1', 'type' => 'dm', 'status' => 'open',
            'participant_name' => 'Ana',
        ]);

        $this->actingInOrganization($owner, $org)
            ->getJson("/api/v1/brands/{$brand->public_id}/inbox/assignees")
            ->assertOk()
            ->assertJsonMissing(['id' => $viewer->public_id])
            ->assertJsonFragment(['id' => $agent->public_id]);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conversation->public_id}/assign", ['assignee' => $viewer->public_id])
            ->assertStatus(422);

        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conversation->public_id}/assign", ['assignee' => $agent->public_id])
            ->assertOk();

        $this->assertSame([$agent->id], $this->notifiedIds('inbox.assigned'));
        $path = json_decode((string) DB::table('notifications')->where('type', 'inbox.assigned')->value('data'), true)['path'];
        $this->assertStringContainsString("conversation={$conversation->public_id}", $path);

        // Asignarse a uno mismo no genera aviso.
        $this->actingInOrganization($owner, $org)
            ->postJson("/api/v1/inbox/{$conversation->public_id}/assign", ['assignee' => $owner->public_id])
            ->assertOk();
        $this->assertNotContains($owner->id, $this->notifiedIds('inbox.assigned'));
    }

    public function test_el_correo_enlaza_a_la_organizacion_del_aviso(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $notice = new OrganizationNotice(
            organizationId: $org->id,
            kind: 'content.publish_failed',
            category: \App\Modules\Notifications\Enums\NotificationCategory::PUBLISHING,
            title: 'No se pudo publicar',
            body: 'Detalle',
            path: '/app/content/abc',
            level: 'danger',
        );

        $this->assertContains('mail', $notice->via($owner));
        $mail = $notice->toMail($owner);
        $this->assertSame('No se pudo publicar', $mail->subject);
        $this->assertStringEndsWith("/app/content/abc?org={$org->public_id}", (string) $mail->actionUrl);
    }

    public function test_limpieza_de_avisos_antiguos(): void
    {
        [$owner, $org] = $this->createOwnerWithOrganization();
        $row = fn (string $id, ?string $readAt, int $daysAgo) => [
            'id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id,
            'organization_id' => $org->id, 'data' => '{}', 'read_at' => $readAt,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ];
        DB::table('notifications')->insert([
            $row('00000000-0000-0000-0000-000000000001', now()->toDateTimeString(), 100), // leído y viejo → se va
            $row('00000000-0000-0000-0000-000000000002', null, 100),                      // sin leer, aún se queda
            $row('00000000-0000-0000-0000-000000000003', null, 200),                      // sin leer y muy viejo → se va
            $row('00000000-0000-0000-0000-000000000004', now()->toDateTimeString(), 5),   // reciente
        ]);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(
            ['00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000004'],
            DB::table('notifications')->orderBy('id')->pluck('id')->all(),
        );
    }
}
