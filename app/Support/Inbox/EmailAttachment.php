<?php

namespace App\Support\Inbox;

final readonly class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public bool $inline = false,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }
}
