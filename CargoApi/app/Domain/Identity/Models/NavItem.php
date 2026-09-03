<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Database\Factories\NavItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the sidebar or the tab bar.
 *
 * The nav is data, not code: both clients render whatever this table returns
 * (DESIGN.md section 7.3), so adding a module is a row here plus a route in
 * the client, and never an edit to a hardcoded list in two places.
 */
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
