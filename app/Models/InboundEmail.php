<?php

namespace App\Models;

use App\Enums\InboundEmailStatus;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\InboundEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Correo recibido en el buzón. Los que no tienen cuenta (sin token válido)
 * nunca los ve un cliente: el global scope los excluye.
 *
 * @property InboundEmailStatus $status
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $summary
 */
class InboundEmail extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<InboundEmailFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fingerprint',
        'message_id',
        'from_address',
        'from_name',
        'recipient',
        'subject',
        'sent_at',
        'disk',
        'raw_path',
        'size',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => InboundEmailStatus::class,
            'sent_at' => 'datetime',
            'size' => 'integer',
            'summary' => 'array',
        ];
    }

    /**
     * Huella para no procesar dos veces el mismo correo.
     */
    public static function fingerprintFor(?string $messageId, string $raw): string
    {
        $messageId = trim((string) $messageId, " \t\n\r\0\x0B<>");

        return hash('sha256', $messageId !== '' ? 'mid:'.$messageId : 'raw:'.$raw);
    }

    public function raw(): string
    {
        return (string) Storage::disk($this->disk)->get($this->raw_path);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markAs(InboundEmailStatus $status, ?string $reason = null, ?array $summary = null): void
    {
        $this->status = $status;
        $this->status_reason = $reason;

        if ($summary !== null) {
            $this->summary = $summary;
        }

        $this->save();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
