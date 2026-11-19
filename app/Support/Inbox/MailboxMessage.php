<?php

namespace App\Support\Inbox;

final readonly class MailboxMessage
{
    public function __construct(
        public string $id,
        public string $raw,
        public mixed $handle = null,
    ) {}
}
