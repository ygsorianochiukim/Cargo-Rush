<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Driver\Models\Driver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntryHelper extends Model
{
    use HasUlids;

    protected $fillable = ['ledger_entry_id', 'driver_id', 'salary_cents', 'position'];

    protected function casts(): array
    {
        return [
            'salary_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
