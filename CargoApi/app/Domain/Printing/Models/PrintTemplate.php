<?php

declare(strict_types=1);

namespace App\Domain\Printing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['key', 'layout'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
