<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use App\Support\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fragmento de un documento, con su vector (float32 empaquetados) y el espacio
 * de embeddings en que se calculó (vectores de espacios distintos no se comparan).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $brand_id
 * @property int $knowledge_document_id
 * @property int $position
 * @property string $content
 * @property string|null $embedding
 * @property string|null $embedding_space
 * @property-read KnowledgeDocument|null $document
 */
class KnowledgeChunk extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'brand_id', 'knowledge_document_id', 'position', 'content', 'embedding', 'embedding_space',
    ];

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    /**
     * @param  list<float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public function vector(): array
    {
        if ($this->embedding === null || $this->embedding === '') {
            return [];
        }
        $values = unpack('g*', $this->embedding);

        return $values === false ? [] : array_values(array_map('floatval', $values));
    }
}
