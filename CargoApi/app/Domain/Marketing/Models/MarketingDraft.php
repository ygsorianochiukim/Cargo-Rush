<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MarketingDraft extends Model
{
    use BelongsToCompany, HasUlids;

    public const FORMATS = ['photo', 'carousel', 'reel', 'text'];

    protected $fillable = ['topic', 'content', 'trend'];

    protected $visible = ['id', 'topic', 'content', 'trend', 'updated_at', 'has_image'];

    protected $appends = ['has_image'];

    protected $casts = ['content' => 'array', 'trend' => 'array'];

    protected function hasImage(): Attribute
    {
        return Attribute::get(fn (): bool => $this->image_path !== null);
    }
}
