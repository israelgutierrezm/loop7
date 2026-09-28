<?php

declare(strict_types=1);

namespace App\Modules\Automations\Services;

use App\Modules\Automations\Exceptions\FeedException;
use App\Support\Security\OutboundUrl;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Descarga y lee feeds RSS 2.0, RSS 1.0 (RDF) y Atom con las defensas de
 * cualquier URL que configura un cliente: anti-SSRF (IP fijada y cada
 * redirección revalidada), tiempo y tamaño máximos, y XML sin entidades ni
 * DTD externas (anti XXE / «billion laughs»).
 */
final class FeedReader
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 10;

    private const MAX_ITEMS = 50;

    /**
     * @throws FeedException con un mensaje apto para el usuario
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedResult
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $target = OutboundUrl::resolve($current);
            } catch (InvalidArgumentException $e) {
                throw new FeedException($e->getMessage(), 0, $e);
            }

            $headers = array_filter([
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5',
                'If-None-Match' => $etag,
                'If-Modified-Since' => $lastModified,
            ]);

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(5)
                    ->withOptions(OutboundUrl::pinnedOptions($target) + ['stream' => true])
                    ->withUserAgent('Loop7-FeedReader/1.0')
                    ->withHeaders($headers)
                    ->get($current);
            } catch (ConnectionException) {
                throw new FeedException('No se pudo conectar con el servidor del feed.');
            }

            if ($response->status() === 304) {
                return new FeedResult(notModified: true, etag: $etag, lastModified: $lastModified);
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new FeedException('El feed redirige sin indicar a dónde.');
                }
                $current = $this->absolute($current, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new FeedException('El servidor del feed respondió ' . $response->status() . '.');
            }

            return $this->parse(
                $this->body($response),
                $response->header('ETag') ?: null,
                $response->header('Last-Modified') ?: null,
            );
        }

        throw new FeedException('El feed redirige demasiadas veces.');
    }

    /**
     * @throws FeedException
     */
    public function parse(string $xml, ?string $etag = null, ?string $lastModified = null): FeedResult
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\n\r");
        if ($xml === '') {
            throw new FeedException('El feed está vacío.');
        }
        // Sin declaraciones de entidades: evita expansiones exponenciales y XXE.
        if (preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new FeedException('El feed no es válido: declara entidades XML.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded || $dom->documentElement === null) {
            throw new FeedException('La URL no devuelve un feed RSS o Atom válido.');
        }

        $xpath = new DOMXPath($dom);
        // local-name(): funciona igual con y sin espacios de nombres (Atom, RDF, dc:, media:…).
        [$title, $nodes, $atom] = match (strtolower($dom->documentElement->localName ?? '')) {
            'rss' => [$this->value($xpath, '/*/*[local-name()="channel"]/*[local-name()="title"]'), $xpath->query('/*/*[local-name()="channel"]/*[local-name()="item"]'), false],
            'rdf' => [$this->value($xpath, '/*/*[local-name()="channel"]/*[local-name()="title"]'), $xpath->query('/*/*[local-name()="item"]'), false],
            'feed' => [$this->value($xpath, '/*/*[local-name()="title"]'), $xpath->query('/*/*[local-name()="entry"]'), true],
            default => throw new FeedException('La URL no devuelve un feed RSS o Atom.'),
        };

        $items = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $items[] = $atom ? $this->atomEntry($xpath, $node) : $this->rssItem($xpath, $node);
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return new FeedResult(false, $this->clean($title, 200), $items, $etag, $lastModified);
    }

    /**
     * @return array{id: string, title: string, link: string|null, summary: string, author: string|null, published_at: string|null, image: string|null}
     */
    private function rssItem(DOMXPath $xpath, DOMElement $item): array
    {
        $link = $this->link($this->value($xpath, '*[local-name()="link"]', $item));
        $title = $this->clean($this->value($xpath, '*[local-name()="title"]', $item), 300);
        $published = $this->date($this->value($xpath, '*[local-name()="pubDate"]', $item) ?: $this->value($xpath, '*[local-name()="date"]', $item));
        $summary = $this->value($xpath, '*[local-name()="description"]', $item) ?: $this->value($xpath, '*[local-name()="encoded"]', $item);
        $image = $this->value($xpath, '*[local-name()="enclosure"][starts-with(@type, "image")]/@url', $item)
            ?: $this->value($xpath, '*[local-name()="content" or local-name()="thumbnail"][@url][1]/@url', $item);

        return [
            'id' => $this->value($xpath, '*[local-name()="guid"]', $item) ?: ($link ?? sha1($title . $published)),
            'title' => $title,
            'link' => $link,
            'summary' => $this->clean($summary, 1000),
            'author' => $this->clean($this->value($xpath, '*[local-name()="author" or local-name()="creator"]', $item), 150) ?: null,
            'published_at' => $published,
            'image' => $this->link($image),
        ];
    }

    /**
     * @return array{id: string, title: string, link: string|null, summary: string, author: string|null, published_at: string|null, image: string|null}
     */
    private function atomEntry(DOMXPath $xpath, DOMElement $entry): array
    {
        $link = $this->link($this->value($xpath, '*[local-name()="link"][not(@rel) or @rel="alternate"]/@href', $entry));
        $title = $this->clean($this->value($xpath, '*[local-name()="title"]', $entry), 300);
        $published = $this->date($this->value($xpath, '*[local-name()="published"]', $entry) ?: $this->value($xpath, '*[local-name()="updated"]', $entry));
        $summary = $this->value($xpath, '*[local-name()="summary"]', $entry) ?: $this->value($xpath, '*[local-name()="content"]', $entry);

        return [
            'id' => $this->value($xpath, '*[local-name()="id"]', $entry) ?: ($link ?? sha1($title . $published)),
            'title' => $title,
            'link' => $link,
            'summary' => $this->clean($summary, 1000),
            'author' => $this->clean($this->value($xpath, '*[local-name()="author"]/*[local-name()="name"]', $entry), 150) ?: null,
            'published_at' => $published,
            'image' => $this->link($this->value($xpath, '*[local-name()="link"][@rel="enclosure"][starts-with(@type, "image")]/@href', $entry)),
        ];
    }

    private function value(DOMXPath $xpath, string $query, ?DOMElement $context = null): string
    {
        $result = $xpath->evaluate('string(' . $query . ')', $context);

        return is_string($result) ? trim($result) : '';
    }

    /**
     * Texto plano: sin etiquetas HTML ni entidades y con espacios normalizados.
     */
    private function clean(string $text, int $limit): string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $plain)), $limit);
    }

    private function link(string $url): ?string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? Str::limit($url, 2000, '')
            : null;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lee el cuerpo sin pasar del tamaño máximo (no descarga archivos enormes).
     */
    private function body(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > self::MAX_BYTES) {
                throw new FeedException('El feed supera los 2 MB.');
            }
        }

        return $body;
    }

    /**
     * URL absoluta de una cabecera Location (puede ser relativa).
     */
    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') . $location;
    }
}
