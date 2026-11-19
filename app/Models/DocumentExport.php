<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $exported_at
 */
class DocumentExport extends Model
{
    use BelongsToAccount;

    public const PENDING = 'pending';

    public const EXPORTED = 'exported';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = ['account_id', 'document_id', 'target', 'status', 'external_id', 'error', 'attempts', 'exported_at'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime', 'attempts' => 'integer'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
