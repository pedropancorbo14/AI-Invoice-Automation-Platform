<?php

namespace App\Models;

use App\Support\Extraction\Confidence;
use App\Support\Inbox\InboxAddress;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant: la gestoría o empresa que contrata el servicio.
 */
#[Fillable(['name', 'min_confidence', 'digest_frequency'])]
#[Hidden(['inbox_token'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_digest_sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Account $account): void {
            $account->inbox_token ??= Company::generateInboxToken();
        });
    }

    /**
     * Confianza mínima de los campos clave para validar sin revisión.
     */
    public function minConfidence(): Confidence
    {
        return Confidence::tryFrom((string) $this->min_confidence) ?? Confidence::High;
    }

    /**
     * Dirección general de la cuenta: lo que llega va a "sin asignar".
     */
    public function inboxAddress(): string
    {
        return InboxAddress::for($this->inbox_token);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
