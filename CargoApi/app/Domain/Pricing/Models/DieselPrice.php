<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What diesel cost on a given day. One row per day. */
class DieselPrice extends Model
{
    use HasUlids;

    protected $fillable = [
        'effective_on', 'price_per_litre_cents', 'currency', 'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'price_per_litre_cents' => 'integer',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
