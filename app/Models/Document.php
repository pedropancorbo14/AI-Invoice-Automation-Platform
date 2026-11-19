<?php

namespace App\Models;

use App\Enums\DocumentEventType;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentTransition;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @property DocumentStatus $status
 * @property DocumentSource $source
 */
class Document extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * El estado solo cambia mediante transitionTo(), nunca por asignación masiva.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'source',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'sha256',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'received',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'source' => DocumentSource::class,
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Document $document): void {
            $document->recordEvent(DocumentEventType::Created, payload: [
                'source' => $document->source->value,
                'original_filename' => $document->original_filename,
            ]);
        });
    }

    /**
     * Cambia de estado validando la transición y dejando rastro en la auditoría.
     *
     * Bloquea la fila para que dos workers no muevan el mismo documento a la vez.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidDocumentTransition
     */
    public function transitionTo(
        DocumentStatus $to,
        ?string $reason = null,
        ?User $by = null,
        array $payload = [],
    ): void {
        DB::transaction(function () use ($to, $reason, $by, $payload): void {
            $current = static::query()
                ->withoutGlobalScopes()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->value('status');

            $from = $current instanceof DocumentStatus ? $current : DocumentStatus::from((string) $current);

            if (! $from->canTransitionTo($to)) {
                throw InvalidDocumentTransition::between($from, $to);
            }

            $this->status = $to;
            $this->status_reason = $reason;
            $this->save();

            $this->recordEvent(
                DocumentEventType::StatusChanged,
                from: $from,
                to: $to,
                reason: $reason,
                by: $by,
                payload: $payload,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(
        DocumentEventType $type,
        ?DocumentStatus $from = null,
        ?DocumentStatus $to = null,
        ?string $reason = null,
        ?User $by = null,
        array $payload = [],
    ): DocumentEvent {
        $by ??= Auth::user() instanceof User ? Auth::user() : null;

        return $this->events()->create([
            'account_id' => $this->account_id,
            'user_id' => $by?->getKey(),
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<DocumentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('id');
    }
}
