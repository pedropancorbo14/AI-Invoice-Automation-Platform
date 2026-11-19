<?php

namespace App\Support\Tenancy;

use App\Models\Account;

/**
 * Cuenta (tenant) activa durante la petición o el job actual.
 *
 * - En HTTP la fija el middleware SetCurrentAccount a partir del usuario.
 * - En jobs y comandos se fija explícitamente con set() o run().
 *
 * Mientras haya una cuenta activa, todos los modelos con BelongsToAccount
 * se filtran por ella y se crean asignados a ella.
 */
class CurrentAccount
{
    private ?int $accountId = null;

    public function set(Account|int|null $account): void
    {
        $this->accountId = $account instanceof Account ? $account->getKey() : $account;
    }

    public function id(): ?int
    {
        return $this->accountId;
    }

    public function has(): bool
    {
        return $this->accountId !== null;
    }

    public function forget(): void
    {
        $this->accountId = null;
    }

    /**
     * Ejecuta un callback con una cuenta activa y restaura la anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Account|int $account, callable $callback): mixed
    {
        $previous = $this->accountId;
        $this->set($account);

        try {
            return $callback();
        } finally {
            $this->accountId = $previous;
        }
    }
}
