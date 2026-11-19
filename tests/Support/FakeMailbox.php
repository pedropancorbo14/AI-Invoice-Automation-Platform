<?php

namespace Tests\Support;

use App\Support\Inbox\Mailbox;
use App\Support\Inbox\MailboxMessage;

class FakeMailbox implements Mailbox
{
    /** @var array<string, string> */
    public array $inbox = [];

    /** @var list<string> */
    public array $processed = [];

    public function add(string $raw): self
    {
        $this->inbox[(string) (count($this->inbox) + count($this->processed) + 1)] = $raw;

        return $this;
    }

    public function fetch(int $limit): iterable
    {
        foreach (array_slice($this->inbox, 0, $limit, true) as $id => $raw) {
            yield new MailboxMessage((string) $id, $raw);
        }
    }

    public function markProcessed(MailboxMessage $message): void
    {
        unset($this->inbox[$message->id]);
        $this->processed[] = $message->id;
    }
}
