<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandAudience;
use App\Modules\Brands\Models\BrandGuideline;
use App\Modules\Brands\Models\BrandKnowledgeItem;
use App\Modules\Brands\Models\BrandProduct;
use App\Modules\Brands\Models\BrandService;
use Illuminate\Support\Str;

/**
 * Compone el "Brand Brain" de una Brand en un contexto de sistema para la IA
 * (docs/07): pautas de voz, notas, audiencias, oferta y base de conocimiento.
 * Estrictamente acotado a la Brand indicada: nunca mezcla contexto entre
 * Organizations/Brands. Cada sección tiene tope para que el prompt no crezca
 * sin límite con marcas muy documentadas.
 */
class BrandContextBuilder
{
    private const MAX_ITEMS = 25;

    private const MAX_KNOWLEDGE = 30;

    private const MAX_TEXT = 400;

    private const MAX_KNOWLEDGE_TEXT = 800;

    public function build(Brand $brand): string
    {
        $lines = [
            'Eres un experto en marketing de redes sociales que escribe para la marca "' . $brand->name . '".',
            'Responde siempre en español, con contenido listo para publicar y sin explicaciones meta.',
        ];

        if (! empty($brand->description)) {
            $lines[] = 'Descripción de la marca: ' . $this->clip($brand->description);
        }
        if (! empty($brand->website)) {
            $lines[] = 'Sitio web: ' . $brand->website;
        }

        $guideline = BrandGuideline::query()->where('brand_id', $brand->id)->first();
        if ($guideline !== null) {
            if (! empty($guideline->voice_tone)) {
                $lines[] = 'Voz y tono: ' . $this->clip($guideline->voice_tone, self::MAX_KNOWLEDGE_TEXT);
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
            if (! empty($guideline->notes)) {
                $lines[] = 'Notas del equipo: ' . $this->clip($guideline->notes, self::MAX_KNOWLEDGE_TEXT);
            }
        }

        $audiences = BrandAudience::query()->where('brand_id', $brand->id)->oldest('id')->limit(self::MAX_ITEMS)->get()
            ->map(fn (BrandAudience $a) => '- ' . $a->name . ($a->description ? ': ' . $this->clip($a->description) : ''))
            ->all();
        if ($audiences !== []) {
            $lines[] = "Audiencias objetivo:\n" . implode("\n", $audiences);
        }

        $offering = [
            ...BrandProduct::query()->where('brand_id', $brand->id)->oldest('id')->limit(self::MAX_ITEMS)->get()
                ->map(fn (BrandProduct $p) => '- ' . $p->name
                    . ($p->price ? ' (' . $p->price . ')' : '')
                    . ($p->description ? ': ' . $this->clip($p->description) : '')
                    . ($p->url ? ' — ' . $p->url : ''))
                ->all(),
            ...BrandService::query()->where('brand_id', $brand->id)->oldest('id')->limit(self::MAX_ITEMS)->get()
                ->map(fn (BrandService $s) => '- ' . $s->name
                    . ($s->description ? ': ' . $this->clip($s->description) : '')
                    . ($s->url ? ' — ' . $s->url : ''))
                ->all(),
        ];
        if ($offering !== []) {
            $lines[] = "Oferta (productos y servicios):\n" . implode("\n", $offering);
        }

        $knowledge = BrandKnowledgeItem::query()->where('brand_id', $brand->id)->oldest('id')->limit(self::MAX_KNOWLEDGE)->get()
            ->map(fn (BrandKnowledgeItem $k) => match ($k->type) {
                'faq' => '- P: ' . $k->title . ($k->body ? "\n  R: " . $this->clip($k->body, self::MAX_KNOWLEDGE_TEXT) : ''),
                'url' => '- Referencia: ' . $k->title . ($k->url ? ' — ' . $k->url : ''),
                default => '- ' . $k->title . ($k->body ? ': ' . $this->clip($k->body, self::MAX_KNOWLEDGE_TEXT) : ''),
            })
            ->all();
        if ($knowledge !== []) {
            $lines[] = "Base de conocimiento (datos verificados; úsalos y no inventes otros):\n" . implode("\n", $knowledge);
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

    private function clip(string $text, int $max = self::MAX_TEXT): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), $max);
    }
}
