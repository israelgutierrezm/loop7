<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandAudience;
use App\Modules\Brands\Models\BrandGuideline;
use App\Modules\Brands\Models\BrandProduct;
use App\Modules\Brands\Models\BrandService;

/**
 * Compone el "Brand Brain" de una Brand en un contexto de sistema para la IA
 * (docs/07). Estrictamente acotado a la Brand indicada: nunca mezcla contexto
 * entre Organizations/Brands.
 */
class BrandContextBuilder
{
    public function build(Brand $brand): string
    {
        $lines = [
            'Eres un experto en marketing de redes sociales que escribe para la marca "' . $brand->name . '".',
            'Responde siempre en español, con contenido listo para publicar y sin explicaciones meta.',
        ];

        if (! empty($brand->description)) {
            $lines[] = 'Descripción de la marca: ' . $brand->description;
        }

        $guideline = BrandGuideline::query()->where('brand_id', $brand->id)->first();
        if ($guideline !== null) {
            if (! empty($guideline->voice_tone)) {
                $lines[] = 'Voz y tono: ' . $guideline->voice_tone;
            }
            if (! empty($guideline->value_propositions)) {
                $lines[] = 'Propuestas de valor: ' . implode('; ', $guideline->value_propositions);
            }
            if (! empty($guideline->cta)) {
                $lines[] = 'Llamada a la acción preferida: ' . $guideline->cta;
            }
            if (! empty($guideline->hashtags)) {
                $lines[] = 'Hashtags sugeridos: ' . implode(' ', $guideline->hashtags);
            }
            if (! empty($guideline->preferred_vocabulary)) {
                $lines[] = 'Vocabulario preferido: ' . implode(', ', $guideline->preferred_vocabulary);
            }
            if (! empty($guideline->prohibited_terms)) {
                $lines[] = 'Términos prohibidos (no usar): ' . implode(', ', $guideline->prohibited_terms);
            }
        }

        $audiences = BrandAudience::query()->where('brand_id', $brand->id)->pluck('name')->all();
        if ($audiences !== []) {
            $lines[] = 'Audiencias objetivo: ' . implode(', ', $audiences);
        }

        $products = BrandProduct::query()->where('brand_id', $brand->id)->pluck('name')->all();
        $services = BrandService::query()->where('brand_id', $brand->id)->pluck('name')->all();
        $offering = array_merge($products, $services);
        if ($offering !== []) {
            $lines[] = 'Oferta (productos/servicios): ' . implode(', ', $offering);
        }

        return implode("\n", $lines);
    }

    /**
     * Instrucción de adaptación al formato/límites de una red social.
     */
    public function networkInstruction(string $network): string
    {
        return match ($network) {
            'x' => 'Adáptalo para X (Twitter): máximo 280 caracteres, directo y con gancho.',
            'instagram' => 'Adáptalo para Instagram: cercano, con emojis y 3-5 hashtags al final.',
            'facebook' => 'Adáptalo para Facebook: tono conversacional, 1-3 párrafos, una llamada a la acción.',
            'linkedin' => 'Adáptalo para LinkedIn: profesional, aporta valor, sin exceso de emojis.',
            'tiktok' => 'Adáptalo para TikTok: guion breve y enérgico para un video corto.',
            'threads' => 'Adáptalo para Threads: conversacional y breve.',
            default => '',
        };
    }
}
