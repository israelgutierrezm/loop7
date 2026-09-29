<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\PostMetricSnapshot;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\TargetStatus;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Content\Models\PostVariant;
use App\Modules\Content\Models\PublicationTarget;
use App\Modules\SocialConnections\Enums\ConnectionStatus;
use App\Modules\SocialConnections\Models\SocialConnection;
use App\Modules\SocialConnections\Models\SocialConnectionDestination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Historial sintético de publicaciones ya medidas (sólo desarrollo/demo) para
 * ver los mejores horarios sin esperar semanas de datos reales. Sólo actúa en
 * la primera cuenta simulada de la marca (proveedor `fake`) y nunca en
 * producción. Cuenta para el cupo mensual del plan como lo haría un historial
 * real: sólo lo publicado este mes.
 */
class DemoPublicationHistory
{
    public const DAYS = 60;

    /** Horas locales de publicación (separadas: el suavizado no mezcla franjas). */
    private const HOURS = [8, 10, 12, 14, 16, 19, 21];

    /**
     * Franjas que la muestra hace rendir mejor o peor: [día ISO, hora local, factor].
     * Separadas lo bastante para que el orden de los recomendados no dependa de la
     * rotación de horas ni del ±4 %.
     */
    private const BOOSTS = [
        [2, 10, 2.8],  // martes 10:00
        [4, 10, 2.0],  // jueves 10:00
        [3, 19, 1.5],  // miércoles 19:00
        [6, 8, 0.6],   // sábado temprano
        [7, 8, 0.6],   // domingo temprano
    ];

    private const BASE_ENGAGEMENT = 100;

    /**
     * @return int publicaciones de muestra creadas
     */
    public function generate(Brand $brand): int
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El historial de muestra no está disponible en producción.');
        }

        $destination = $this->simulatedDestination($brand);
        if ($destination === null) {
            return 0;
        }

        // Una sola vez: repetirlo duplicaría el historial.
        $exists = PostMetricSnapshot::query()->withoutGlobalScopes()
            ->where('brand_id', $brand->id)
            ->where('remote_id', 'like', 'demo-%')
            ->exists();

        return $exists ? 0 : DB::transaction(fn (): int => $this->history($brand, $destination));
    }

    /**
     * Dos publicaciones al día durante 60 días (hasta hace tres, con métricas ya
     * asentadas), rotando las horas para que cada día y hora tenga varias.
     */
    private function history(Brand $brand, SocialConnectionDestination $destination): int
    {
        $timezone = $brand->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $created = 0;

        for ($daysAgo = 3; $daysAgo <= self::DAYS; $daysAgo++) {
            $day = $today->subDays($daysAgo);
            $week = intdiv($daysAgo, 7);
            foreach ([$week, $week + 3] as $rotation) {
                $hour = self::HOURS[$rotation % count(self::HOURS)];
                $this->publication($brand, $destination, $day->setTime($hour, 0), $created);
                $created++;
            }
        }

        return $created;
    }

    private function publication(Brand $brand, SocialConnectionDestination $destination, CarbonImmutable $local, int $n): void
    {
        $publishedAt = $local->utc();
        // Fechados cuando se habrían creado (el día anterior), como un historial real:
        // así el cupo mensual del plan sólo cuenta los de este mes.
        $createdAt = $publishedAt->subDay();

        $content = $this->saveAt(new ContentItem([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'title' => 'Publicación de muestra ' . ($n + 1),
            'body' => 'Publicación generada para la demostración de analítica.',
            'status' => 'published',
            'scheduled_at' => $publishedAt,
        ]), $createdAt);
        $variant = $this->saveAt(new PostVariant([
            'organization_id' => $brand->organization_id,
            'content_item_id' => $content->id,
            'provider' => 'fake',
            'body' => $content->body,
            'format' => 'text',
        ]), $createdAt);
        // Sin remote_id: la sincronización de métricas las omite y no pisa la muestra
        // con los valores del proveedor simulado. La clave «demo» queda en el snapshot.
        $key = "demo-{$destination->id}-{$n}";
        $target = $this->saveAt(new PublicationTarget([
            'organization_id' => $brand->organization_id,
            'post_variant_id' => $variant->id,
            'social_connection_destination_id' => $destination->id,
            'status' => TargetStatus::PUBLISHED->value,
            'published_at' => $publishedAt,
        ]), $createdAt);

        // ±4 % determinista: lo habitual no llega a «destacar» (el mínimo es +5 %).
        $jitter = 0.96 + (crc32($key) % 9) / 100;
        $engagement = (int) round(self::BASE_ENGAGEMENT * $this->boost($local->dayOfWeekIso, $local->hour) * $jitter);

        $measuredAt = $publishedAt->addDays(2);
        $this->saveAt(new PostMetricSnapshot([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'publication_target_id' => $target->id,
            'provider' => 'fake',
            'remote_id' => $key,
            'date' => $measuredAt->toDateString(),
            'impressions' => $engagement * 20,
            'reach' => $engagement * 16,
            'likes' => (int) round($engagement * 0.7),
            'comments' => (int) round($engagement * 0.1),
            'shares' => (int) round($engagement * 0.1),
            'clicks' => (int) round($engagement * 0.1),
            'engagement' => $engagement,
        ]), $measuredAt);
    }

    /**
     * Guarda un registro nuevo con fecha de creación propia (Eloquent respeta las
     * marcas de tiempo ya fijadas).
     *
     * @template TModel of Model
     *
     * @param TModel $model
     * @return TModel
     */
    private function saveAt(Model $model, CarbonImmutable $at): Model
    {
        $model->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $model;
    }

    private function boost(int $weekday, int $hour): float
    {
        foreach (self::BOOSTS as [$day, $at, $factor]) {
            if ($day === $weekday && $at === $hour) {
                return $factor;
            }
        }

        return 1.0;
    }

    /**
     * Primera cuenta simulada activa de la marca (basta una para la muestra).
     */
    private function simulatedDestination(Brand $brand): ?SocialConnectionDestination
    {
        $connectionIds = SocialConnection::query()->withoutGlobalScopes()
            ->where('brand_id', $brand->id)
            ->where('provider', 'fake')
            ->where('status', ConnectionStatus::CONNECTED->value)
            ->pluck('id');

        return SocialConnectionDestination::query()->withoutGlobalScopes()
            ->whereIn('social_connection_id', $connectionIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
