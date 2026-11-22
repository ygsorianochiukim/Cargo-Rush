<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MarketingProfile extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['brand'];

    protected $casts = ['brand' => 'array'];
}
