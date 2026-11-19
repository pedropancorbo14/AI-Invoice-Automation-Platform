<?php

namespace App\Support\Inbox;

/**
 * Buzón del que se leen los correos nuevos.
 */
interface Mailbox
{
    /**
     * Correos pendientes de leer, del más antiguo al más nuevo.
     *
     * @return iterable<MailboxMessage>
     */
    public function fetch(int $limit): iterable;

    /**
     * Saca el correo de la bandeja de entrada para no volver a leerlo.
     */
    public function markProcessed(MailboxMessage $message): void;
}
