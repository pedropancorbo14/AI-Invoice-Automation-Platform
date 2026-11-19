<?php

namespace App\Models;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Entrada de auditoría. Es inmutable: solo se insertan filas.
 *
 * @property DocumentEventType $type
 * @property DocumentStatus|null $from_status
 * @property DocumentStatus|null $to_status
 * @property array<string, mixed>|null $payload
 */
class DocumentEvent extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'user_id',
        'type',
        'from_status',
        'to_status',
        'reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentEventType::class,
            'from_status' => DocumentStatus::class,
            'to_status' => DocumentStatus::class,
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('La auditoría no se puede borrar.'));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
