<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use App\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Knowledge\Enums\KnowledgeDocumentStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento del Brand Brain (PDF, Word, texto) cuyo contenido se indexa en
 * fragmentos para que la IA lo use como fuente (RAG). El archivo es privado.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $brand_id
 * @property string $title
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property KnowledgeDocumentStatus $status
 * @property string|null $error
 * @property int $chunks_count
 * @property int $characters
 * @property bool $truncated
 * @property string|null $embedding_space
 * @property int|null $uploaded_by_user_id
 * @property \Illuminate\Support\Carbon|null $indexed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Brand|null $brand
 * @property-read User|null $uploader
 */
class KnowledgeDocument extends Model
{
    use BelongsToOrganization;
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'brand_id', 'title', 'original_name', 'disk', 'path', 'mime_type', 'extension',
        'size_bytes', 'status', 'error', 'chunks_count', 'characters', 'truncated', 'embedding_space',
        'uploaded_by_user_id', 'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeDocumentStatus::class,
            'truncated' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
