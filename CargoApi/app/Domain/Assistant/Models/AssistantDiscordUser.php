<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AssistantDiscordUser extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['discord_id', 'label', 'may_confirm_receipts'];

    protected function casts(): array
    {
        return ['may_confirm_receipts' => 'boolean'];
    }
}
