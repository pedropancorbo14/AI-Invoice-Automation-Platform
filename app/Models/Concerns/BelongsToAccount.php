<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Support\Tenancy\AccountScope;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aísla un modelo por cuenta: filtra las consultas por la cuenta activa
 * y asigna account_id automáticamente al crear.
 *
 * @mixin Model
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model): void {
            $current = app(CurrentAccount::class);

            if ($model->getAttribute('account_id') === null && $current->has()) {
                $model->setAttribute('account_id', $current->id());
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
