<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Services\DocumentTextExtractor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Documento para el Brand Brain: PDF, Word (.docx), TXT, Markdown o CSV de
 * hasta 10 MB, y su tipo real (finfo) debe corresponder a la extensión.
 */
class UploadKnowledgeDocumentRequest extends FormRequest
{
    public const MAX_KB = 10240;

    public function authorize(): bool
    {
        return true; // el controlador resuelve la marca y exige brands.update
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:' . self::MAX_KB, function (string $attribute, mixed $file, Closure $fail): void {
                if (! $file instanceof UploadedFile) {
                    return;
                }
                $allowed = DocumentTextExtractor::MIME_TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
                if ($allowed === null) {
                    $fail('Formato no admitido: sube un PDF, Word (.docx), TXT, Markdown o CSV.');
                } elseif (! in_array($file->getMimeType(), $allowed, true)) {
                    $fail('El contenido del archivo no corresponde a su extensión.');
                }
            }],
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'El archivo supera los 10 MB.',
            'file.required' => 'Elige un archivo.',
        ];
    }
}
