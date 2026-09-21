<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MediaLibrary\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve archivos privados mediante URL firmada y temporal (middleware 'signed').
 * No requiere sesión: la firma (y su expiración) autoriza el acceso puntual.
 */
class MediaFileController extends Controller
{
    public function show(string $asset): StreamedResponse
    {
        // Sin contexto de tenant (petición firmada); la firma garantiza el acceso.
        $model = MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('public_id', $asset)
            ->firstOrFail();

        $disk = Storage::disk($model->disk);
        abort_unless($disk->exists($model->path), 404);

        return $disk->response($model->path, $model->original_name, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
