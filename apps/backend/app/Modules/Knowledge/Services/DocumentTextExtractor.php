<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Exceptions\DocumentExtractionException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Texto de un documento subido al Brand Brain: PDF (con capa de texto), Word
 * (.docx), texto plano, Markdown y CSV. Nunca ejecuta nada del archivo y acota
 * memoria (sin imágenes del PDF; XML de Word con tamaño máximo contra zip bombs).
 */
final class DocumentTextExtractor
{
    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    /** Tipos MIME reales (finfo) aceptados por extensión. */
    public const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
    ];

    private const MAX_DOCX_XML_BYTES = 20 * 1024 * 1024;

    /** Tope de caracteres que se procesan de un documento. */
    public const MAX_CHARS = 1_000_000;

    public function extract(string $absolutePath, string $extension): string
    {
        $text = match ($extension) {
            'pdf' => $this->pdf($absolutePath),
            'docx' => $this->docx($absolutePath),
            'txt', 'md', 'csv' => $this->plain($absolutePath),
            default => throw new DocumentExtractionException('Tipo de archivo no admitido.'),
        };

        $text = trim($text);
        if ($text === '') {
            throw new DocumentExtractionException('No se encontró texto en el documento (¿es un PDF escaneado sin texto seleccionable?).');
        }

        return $text;
    }

    private function pdf(string $path): string
    {
        try {
            $config = new Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);

            return (new Parser([], $config))->parseFile($path)->getText();
        } catch (Throwable) {
            throw new DocumentExtractionException('No se pudo leer el PDF: puede estar protegido con contraseña o dañado.');
        }
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DocumentExtractionException('No se pudo abrir el documento de Word.');
        }

        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new DocumentExtractionException('El archivo no es un documento de Word (.docx) válido.');
            }
            if ($stat['size'] > self::MAX_DOCX_XML_BYTES) {
                throw new DocumentExtractionException('El documento de Word es demasiado grande para procesarlo.');
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml)) {
            throw new DocumentExtractionException('No se pudo leer el documento de Word.');
        }

        // Párrafos y saltos de línea → líneas; tabulaciones → espacio.
        $xml = preg_replace(['/<w:tab\/>/', '/<w:br[^>]*\/>/', '/<\/w:p>/'], [' ', "\n", "\n\n"], $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function plain(string $path): string
    {
        $raw = (string) file_get_contents($path);
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM

        // Texto de Windows (Latin-1) a UTF-8.
        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
