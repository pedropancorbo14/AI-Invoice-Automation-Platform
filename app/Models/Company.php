<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Cliente de una cuenta (la empresa cuyas facturas se procesan).
 */
#[Fillable(['name', 'tax_id'])]
#[Hidden(['inbox_token'])]
class Company extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->inbox_token ??= self::generateInboxToken();
            $company->tax_id = self::normalizeTaxId($company->tax_id);
        });
    }

    public static function generateInboxToken(): string
    {
        return Str::lower(Str::random(24));
    }

    public static function normalizeTaxId(string $taxId): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $taxId));
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
