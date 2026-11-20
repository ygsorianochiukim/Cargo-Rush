<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DiscordExpenseReceipt extends Model
{
    use BelongsToCompany, HasUlids;

    public const FIELDS = [
        'date', 'amount', 'currency', 'category', 'vat', 'payee', 'reference',
        'supplier_id', 'trip_id', 'payment_source_id', 'pay_schedule', 'note',
    ];

    protected $fillable = [
        'discord_user_id', 'channel_id', 'attachment_id', 'filename', 'mime', 'original',
        'status', 'revision', 'fields', 'history', 'expense_id', 'archived_at', 'fingerprint', 'posted_fingerprint',
    ];

    protected $hidden = ['original'];

    protected function casts(): array
    {
        return [
            'original' => 'encrypted',
            'revision' => 'integer',
            'fields' => 'array',
            'history' => 'array',
            'archived_at' => 'datetime',
        ];
    }
}
