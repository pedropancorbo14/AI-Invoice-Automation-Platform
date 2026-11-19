<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Support\Extraction\DocumentType;
use App\Support\Extraction\InputMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado de una extracción. Si se reprocesa el documento se crea otra;
 * la vigente es la última (Document::latestExtraction).
 *
 * @property InputMode $input_mode
 * @property DocumentType $document_type
 * @property array<string, mixed> $data
 * @property array<string, string> $confidence
 * @property list<array{field: string, note: string}> $notes
 * @property string $cost_usd
 */
class DocumentExtraction extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id', 'model', 'input_mode', 'document_type', 'data', 'confidence', 'notes',
        'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'input_mode' => InputMode::class,
            'document_type' => DocumentType::class,
            'data' => 'array',
            'confidence' => 'array',
            'notes' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    /**
     * @return list<string>
     */
    public function doubtfulFields(): array
    {
        return array_keys(array_filter($this->confidence, fn (string $level) => $level !== 'high'));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
