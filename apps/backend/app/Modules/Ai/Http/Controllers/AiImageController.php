<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Services\AiGenerationService;
use App\Modules\Brands\Http\Concerns\ResolvesBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Services\MediaService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Generación de imágenes con IA acotada a una Brand (permiso ai.generate_image).
 * Las imágenes se guardan en la biblioteca de la marca (las URLs de algunos
 * proveedores caducan en minutos) para poder adjuntarlas a publicaciones.
 */
class AiImageController extends Controller
{
    use ResolvesBrand;

    /** Tamaño máximo de una imagen generada que se descarga (bytes). */
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly AiGenerationService $generation,
        private readonly MediaService $media,
    ) {
    }

    public function generate(Request $request, string $brand): JsonResponse
    {
        $brandModel = $this->resolveBrand($brand);
        abort_unless($request->user()->can('ai.generate_image'), 403);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'size' => ['nullable', Rule::in(['1024x1024', '1024x1792', '1792x1024'])],
        ]);

        $result = $this->generation->generateImage(
            $brandModel,
            $request->user(),
            $data['prompt'],
            $data['size'] ?? '1024x1024',
        );

        $saved = [];
        foreach ($result['images'] as $index => $image) {
            $asset = $this->saveToLibrary($brandModel, $request, $image['url'], $data['prompt'] . '-' . ($index + 1));
            if ($asset !== null) {
                $saved[] = [
                    'id' => $asset->public_id,
                    'original_name' => $asset->original_name,
                    'is_image' => true,
                    'is_video' => false,
                    'url' => $this->media->temporaryUrl($asset),
                ];
            }
        }

        return ApiResponse::success(
            [...$result, 'media' => $saved],
            $saved !== [] ? 'Imagen generada y guardada en la biblioteca.' : 'Imagen generada.',
        );
    }

    private function saveToLibrary(Brand $brand, Request $request, string $url, string $name): ?MediaAsset
    {
        try {
            [$contents, $mime] = $this->fetch($url);
            $organization = $brand->organization;
            if ($organization !== null) {
                $this->media->ensureStorageAvailable($organization, strlen($contents));
            }

            return $this->media->storeGenerated($brand, $contents, $mime, $name, $request->user());
        } catch (Throwable $e) {
            // La imagen se devuelve igualmente; sólo no queda guardada.
            Log::warning('No se pudo guardar la imagen generada en la biblioteca.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{0: string, 1: string} contenido binario y tipo MIME
     */
    private function fetch(string $url): array
    {
        if (preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#s', $url, $m) === 1) {
            $contents = (string) base64_decode($m[2], true);

            return [$contents, $m[1]];
        }

        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('URL de imagen no admitida.');
        }

        $response = Http::timeout(30)->get($url);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $contents = $response->body();

        if ($response->failed() || strlen($contents) > self::MAX_BYTES) {
            throw new \RuntimeException('No se pudo descargar la imagen generada.');
        }

        return [$contents, $mime];
    }
}
