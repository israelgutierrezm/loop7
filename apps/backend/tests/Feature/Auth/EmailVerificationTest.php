<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_el_enlace_verifica_y_vuelve_a_la_app(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user))->assertRedirectContains('/verificar-correo?status=success');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified']);
    }

    public function test_un_enlace_caducado_o_alterado_vuelve_a_la_app_con_error(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user, -5))->assertRedirectContains('/verificar-correo?status=invalid');
        $this->get($this->link($user) . 'x')->assertRedirectContains('/verificar-correo?status=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_reenviar_el_enlace(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/email/verification-notification')->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);

        $user->markEmailAsVerified();
        $this->postJson('/api/v1/email/verification-notification')->assertOk()->assertJsonPath('message', 'Tu correo ya está verificado.');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }
}
