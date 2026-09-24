<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Resources;

use App\Modules\MediaLibrary\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Brands\Models\Brand
 */
class BrandResource extends JsonResource
{
    /** El SPA conserva el contexto durante horas: el enlace del logo dura una semana. */
    private const LOGO_URL_MINUTES = 60 * 24 * 7;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $logo = $this->logo; // precargado en listados (with('logo'))

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'website' => $this->website,
            'description' => $this->description,
            'logo' => $logo === null ? null : [
                'id' => $logo->public_id,
                'url' => app(MediaService::class)->temporaryUrl($logo, self::LOGO_URL_MINUTES),
            ],
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
