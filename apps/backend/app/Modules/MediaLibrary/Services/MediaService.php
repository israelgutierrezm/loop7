<?php

declare(strict_types=1);

namespace App\Modules\MediaLibrary\Services;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\MediaLibrary\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MediaService
{
    private const DISK = 'local';

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

    public function delete(MediaAsset $asset): void
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
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
