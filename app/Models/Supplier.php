<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lo que el sistema recuerda de un proveedor para una empresa cliente: su
 * nombre, la cuenta contable, el IVA y la categoría habituales. Se rellena
 * con cada factura validada y con cada corrección humana (fase 5).
 *
 * @property Carbon|null $last_validated_at
 */
class Supplier extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id', 'company_id', 'tax_id', 'name', 'normalized_name',
        'ledger_account', 'usual_vat_rate', 'category',
    ];

    protected function casts(): array
    {
        return [
            'validated_count' => 'integer',
            'last_validated_at' => 'datetime',
            'usual_vat_rate' => 'decimal:2',
        ];
    }

    public function isKnown(): bool
    {
        return $this->validated_count > 0;
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
