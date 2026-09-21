<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'mimetypes' valida el MIME real (finfo), no sólo la extensión.
            // Se excluye SVG a propósito (riesgo XSS en imágenes vectoriales).
            'file' => [
                'required',
                'file',
                'max:51200', // 50 MB
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,application/pdf',
            ],
            'folder' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debes seleccionar un archivo.',
            'file.max' => 'El archivo supera el tamaño máximo permitido (50 MB).',
            'file.mimetypes' => 'Tipo de archivo no permitido.',
        ];
    }
}
