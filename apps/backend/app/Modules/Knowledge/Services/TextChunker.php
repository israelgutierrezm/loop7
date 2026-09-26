<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Services;

/**
 * Trocea un texto en fragmentos de ~1000 caracteres respetando párrafos y
 * frases, con un pequeño solapamiento para no cortar ideas entre fragmentos.
 */
final class TextChunker
{
    public const SIZE = 1000;

    public const OVERLAP = 150;

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return [];
        }

        $pieces = [];
        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= self::SIZE) {
                $pieces[] = $paragraph;

                continue;
            }
            // Párrafo largo: por frases; y frases enormes (sin puntuación), a trozos.
            foreach (preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [$paragraph] as $sentence) {
                foreach (mb_str_split($sentence, self::SIZE) as $part) {
                    if (trim($part) !== '') {
                        $pieces[] = trim($part);
                    }
                }
            }
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($piece) > self::SIZE) {
                $chunks[] = $current;
                $current = $this->overlap($current) . $piece;
            } else {
                $current = $current === '' ? $piece : $current . "\n" . $piece;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** Final del fragmento anterior (desde un límite de palabra) para arrancar el siguiente. */
    private function overlap(string $chunk): string
    {
        $tail = mb_substr($chunk, -self::OVERLAP);
        $tail = (string) preg_replace('/^\S*\s+/u', '', $tail);

        return trim($tail) === '' ? '' : trim($tail) . "\n";
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x{00A0}\t ]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);

        return trim($text);
    }
}
