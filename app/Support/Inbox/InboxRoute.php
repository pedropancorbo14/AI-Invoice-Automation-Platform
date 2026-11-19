<?php

namespace App\Support\Inbox;

use App\Models\Account;
use App\Models\Company;

final readonly class InboxRoute
{
    public function __construct(
        public Account $account,
        public ?Company $company,
        public string $recipient,
    ) {}
}
