<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Database\Factories\NavItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NavItem extends Model
{
    /** @use HasFactory<NavItemFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'label', 'icon', 'route', 'order',
        'mobile', 'web', 'group', 'permission', 'badge_source',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'mobile' => 'boolean',
            'web' => 'boolean',
        ];
    }
}
