<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Services;

use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Marca blanca (feature.white_label): nombre, color y logo con los que la
 * plataforma se presenta a los miembros de una Organization (panel y correos).
 * Sin el entitlement se ignora lo guardado y rige la marca de la plataforma.
 */
class OrganizationBranding
{
    public const DISK = 'local';

    /** Contraste mínimo del color con texto blanco (WCAG AA, texto normal). */
    public const MIN_CONTRAST = 4.5;

    /** El SPA mantiene el contexto horas; el enlace del logo dura una semana. */
    private const LOGO_URL_MINUTES = 60 * 24 * 7;

    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    public function enabled(Organization $organization): bool
    {
        return $this->entitlements->allows($organization, Entitlement::FEATURE_WHITE_LABEL);
    }

    /**
     * Marca vigente para la UI y los correos; null si el plan no la incluye o
     * no se personalizó nada.
     *
     * @return array{name: string, color: string|null, logo_url: string|null}|null
     */
    public function active(Organization $organization): ?array
    {
        if (! $this->enabled($organization)) {
            return null;
        }

        $branding = $organization->branding ?? [];
        $name = trim((string) ($branding['display_name'] ?? ''));
        $color = $branding['primary_color'] ?? null;
        $logo = $branding['logo_path'] ?? null;

        if ($name === '' && $color === null && $logo === null) {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : $organization->name,
            'color' => $color,
            'logo_url' => $logo !== null ? $this->logoUrl($organization) : null,
        ];
    }

    /**
     * Nombre con el que se presenta la plataforma a esta Organization.
     */
    public function productName(Organization $organization): string
    {
        return $this->active($organization)['name'] ?? (string) config('app.name');
    }

    /**
     * Lo guardado (para la pantalla de configuración), esté o no activo.
     *
     * @return array{available: bool, display_name: string|null, primary_color: string|null, logo_url: string|null}
     */
    public function settings(Organization $organization): array
    {
        $branding = $organization->branding ?? [];

        return [
            'available' => $this->enabled($organization),
            'display_name' => $branding['display_name'] ?? null,
            'primary_color' => $branding['primary_color'] ?? null,
            'logo_url' => ($branding['logo_path'] ?? null) !== null ? $this->logoUrl($organization) : null,
        ];
    }

    public function update(Organization $organization, ?string $displayName, ?string $primaryColor): void
    {
        $branding = $organization->branding ?? [];
        $branding['display_name'] = $displayName !== null && trim($displayName) !== '' ? trim($displayName) : null;
        $branding['primary_color'] = $primaryColor !== null ? strtolower($primaryColor) : null;

        $organization->forceFill(['branding' => $branding])->save();
    }

    public function storeLogo(Organization $organization, UploadedFile $file): void
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        // Nombre aleatorio: nunca el original (evita rutas o extensiones peligrosas).
        $path = $file->storeAs("branding/{$organization->public_id}", Str::ulid() . '.' . $extension, self::DISK);

        $branding = $organization->branding ?? [];
        $previous = $branding['logo_path'] ?? null;
        $branding['logo_path'] = $path;
        $organization->forceFill(['branding' => $branding])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }

    public function removeLogo(Organization $organization): void
    {
        $branding = $organization->branding ?? [];
        $previous = $branding['logo_path'] ?? null;
        $branding['logo_path'] = null;
        $organization->forceFill(['branding' => $branding])->save();

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }

    public function logoPath(Organization $organization): ?string
    {
        return $organization->branding['logo_path'] ?? null;
    }

    public function logoUrl(Organization $organization): string
    {
        return URL::temporarySignedRoute(
            'branding.logo',
            now()->addMinutes(self::LOGO_URL_MINUTES),
            ['organization' => $organization->public_id],
        );
    }

    /**
     * Relación de contraste (WCAG 2.x) entre un color #rrggbb y el blanco.
     */
    public static function contrastWithWhite(string $hex): float
    {
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return 0.0;
        }

        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05);
    }
}
