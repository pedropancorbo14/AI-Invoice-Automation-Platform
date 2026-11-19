<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class AccountScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $accountId = app(CurrentAccount::class)->id();

        if ($accountId !== null) {
            $builder->where($model->qualifyColumn('account_id'), $accountId);
        }
    }
}
