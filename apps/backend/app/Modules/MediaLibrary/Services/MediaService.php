<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Services;

use App\Models\User;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use App\Modules\MediaLibrary\Models\MediaFolder;
use App\Modules\MediaLibrary\Models\MediaTag;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaService
{
    private const DISK = 'local';

    public function __construct(private readonly EntitlementsService $entitlements)
    {
    }

    public function upload(Brand $brand, UploadedFile $file, ?int $folderId, User $uploader): MediaAsset
    {
        $realPath = $file->getRealPath();
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $extension = mb_strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        $checksum = $realPath ? (hash_file('sha256', $realPath) ?: null) : null;

        [$width, $height] = $this->dimensions($realPath, $mime);

        // Nombre aleatorio: nunca se usa el nombre original en disco (evita ejecución).
        $storedName = (string) Str::ulid() . ($extension !== '' ? ".{$extension}" : '');
        $path = $file->storeAs("media/{$brand->public_id}", $storedName, self::DISK);

        return MediaAsset::query()->create([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'folder_id' => $folderId,
            'uploaded_by_user_id' => $uploader->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'extension' => $extension !== '' ? $extension : null,
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
        ]);
    }

    /**
     * Guarda en la biblioteca un archivo generado por el sistema (p. ej. una
     * imagen de IA). Sólo acepta imágenes rasterizadas.
     */
    public function storeGenerated(Brand $brand, string $contents, string $mime, string $name, User $creator): MediaAsset
    {
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime])) {
            throw new \InvalidArgumentException('Formato de imagen no admitido: ' . $mime);
        }

        $extension = $extensions[$mime];
        $path = "media/{$brand->public_id}/" . Str::ulid() . '.' . $extension;
        Storage::disk(self::DISK)->put($path, $contents);

        $info = @getimagesizefromstring($contents);

        return MediaAsset::query()->create([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'folder_id' => null,
            'uploaded_by_user_id' => $creator->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => Str::limit(Str::slug($name), 60, '') . '.' . $extension,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'width' => $info !== false ? (int) $info[0] : null,
            'height' => $info !== false ? (int) $info[1] : null,
            'checksum' => hash('sha256', $contents),
        ]);
    }

    /**
     * Lanza 402 si sumar `$bytes` supera el almacenamiento del plan.
     */
    public function ensureStorageAvailable(Organization $organization, int $bytes): void
    {
        $limitGb = $this->entitlements->limit($organization, Entitlement::STORAGE_GB);
        if ($limitGb === Entitlement::UNLIMITED) {
            return;
        }

        if ($this->storageUsedBytes($organization->id) + $bytes > $limitGb * 1024 ** 3) {
            throw new PlanLimitExceededException('Has alcanzado el límite de almacenamiento de tu plan.', Entitlement::STORAGE_GB);
        }
    }

    public function delete(MediaAsset $asset): void
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
    }

    /**
     * Carpeta de la Brand por su public_id (null = sin carpeta). Una carpeta de
     * otra Brand se rechaza como si no existiera.
     */
    public function folderId(int $brandId, ?string $folderPublicId): ?int
    {
        if ($folderPublicId === null || $folderPublicId === '') {
            return null;
        }

        $id = MediaFolder::query()->where('brand_id', $brandId)->where('public_id', $folderPublicId)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['folder' => 'La carpeta no existe en esta marca.']);
        }

        return (int) $id;
    }

    /**
     * Sustituye las etiquetas de un archivo (se crean al vuelo por nombre dentro
     * de su Brand; las que se quedan sin archivos se eliminan).
     *
     * @param  list<string>  $names
     */
    public function syncTags(MediaAsset $asset, array $names): void
    {
        $unique = collect($names)
            ->map(fn (string $name): string => trim(preg_replace('/\s+/', ' ', $name) ?? ''))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();

        $existing = MediaTag::query()->where('brand_id', $asset->brand_id)->get()
            ->keyBy(fn (MediaTag $tag): string => mb_strtolower($tag->name));

        $ids = $unique->map(fn (string $name): int => ($existing->get(mb_strtolower($name))
            ?? MediaTag::query()->create([
                'organization_id' => $asset->organization_id,
                'brand_id' => $asset->brand_id,
                'name' => mb_substr($name, 0, 40),
            ]))->id)->all();

        $asset->tags()->sync($ids);

        MediaTag::query()->where('brand_id', $asset->brand_id)->doesntHave('assets')->delete();
    }

    /**
     * Bytes usados por la Organization (suma de assets vivos).
     */
    public function storageUsedBytes(int $organizationId): int
    {
        return (int) MediaAsset::query()
            ->where('organization_id', $organizationId)
            ->sum('size_bytes');
    }

    /**
     * URL firmada y temporal para servir un archivo privado.
     */
    public function temporaryUrl(MediaAsset $asset, int $minutes = 30): string
    {
        return URL::temporarySignedRoute(
            'media.file',
            now()->addMinutes($minutes),
            ['asset' => $asset->public_id],
        );
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(?string $realPath, string $mime): array
    {
        if ($realPath === null || ! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $info = @getimagesize($realPath);
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
