<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\Organizations\Models\Organization;
use App\Modules\SocialConnections\Enums\Capability;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use App\Modules\SocialConnections\Services\SocialProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Decide dónde se publica cada variante (destinos activos de conexiones
 * conectadas de la marca) y valida antes de programar/publicar que cada red
 * recibe lo que exige (p. ej. Instagram necesita imagen o video).
 */
class PublicationPlanner
{
    public function __construct(
        private readonly SocialProviderManager $manager,
        private readonly EntitlementsService $entitlements,
        private readonly UsageService $usage,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function assertPublishable(ContentItem $content): void
    {
        $content->loadMissing('variants.media');

        $organization = Organization::query()->findOrFail($content->organization_id);
        if (! $this->entitlements->hasAccess($organization)) {
            throw new PlanLimitExceededException(
                'Tu suscripción no está activa. Elige un plan en Facturación para seguir publicando.',
                'subscription',
            );
        }

        if ($content->variants->isEmpty()) {
            throw ValidationException::withMessages([
                'variants' => 'Añade al menos una variante por red social antes de publicar.',
            ]);
        }

        $errors = [];
        $withDestinations = 0;

        foreach ($content->variants as $variant) {
            if ($this->destinationsFor($content, $variant)->isEmpty()) {
                continue;
            }
            $withDestinations++;

            $name = $this->manager->record($variant->provider)->name ?? $variant->provider;
            $capabilities = $this->manager->adapter($variant->provider)?->capabilities() ?? [];

            if (($capabilities[Capability::TEXT] ?? true) === false && $variant->media->isEmpty()) {
                $errors[] = "{$name} exige al menos una imagen o un video.";
            }
            if (($capabilities[Capability::VIDEO] ?? false) === false
                && $variant->media->contains(fn ($m) => $m->isVideo())) {
                $errors[] = "{$name} no admite video.";
            }
        }

        if ($withDestinations === 0) {
            $errors[] = 'No hay cuentas conectadas en esta marca para las redes de este contenido. Conecta una cuenta en Redes sociales.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['variants' => $errors]);
        }
    }

    /**
     * Crea (o reprograma) un target por cada destino disponible de cada variante.
     */
    public function createTargets(ContentItem $content, Carbon $when): int
    {
        $content->loadMissing('variants');

        $pending = [];
        foreach ($content->variants as $variant) {
            foreach ($this->destinationsFor($content, $variant) as $destination) {
                $target = PublicationTarget::query()->firstOrNew([
                    'post_variant_id' => $variant->id,
                    'social_connection_destination_id' => $destination->id,
                ]);
                if ($target->exists && $target->status === TargetStatus::PUBLISHED) {
                    continue; // ya publicado: no se vuelve a publicar
                }
                $pending[] = $target;
            }
        }

        // Límite de plan: publicaciones por mes (sólo cuentan las nuevas; reprogramar no suma).
        $organization = Organization::query()->findOrFail($content->organization_id);
        $this->usage->ensureWithin(
            $organization,
            Entitlement::SCHEDULED_POSTS_MONTH,
            $this->usage->scheduledPostsThisMonth($organization),
            count(array_filter($pending, fn (PublicationTarget $t) => ! $t->exists)),
            'Has alcanzado las publicaciones mensuales incluidas en tu plan.',
        );

        foreach ($pending as $target) {
            $target->forceFill([
                'organization_id' => $content->organization_id,
                'status' => TargetStatus::SCHEDULED->value,
                'scheduled_at' => $when,
                'error' => null,
            ])->save();
        }

        return count($pending);
    }

    /**
     * @return Collection<int, SocialConnectionDestination>
     */
    private function destinationsFor(ContentItem $content, PostVariant $variant): Collection
    {
        return SocialConnectionDestination::query()->withoutGlobalScopes()
            ->where('organization_id', $content->organization_id)
            ->where('is_active', true)
            ->whereHas('connection', fn ($q) => $q
                ->where('brand_id', $content->brand_id)
                ->where('provider', $variant->provider)
                ->where('status', ConnectionStatus::CONNECTED->value))
            ->get();
    }
}
