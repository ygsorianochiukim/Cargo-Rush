<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repositories;

use App\Domain\Identity\Models\NavItem;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NavigationRepository extends Repository
{
    protected function model(): string
    {
        return NavItem::class;
    }

    /** Sorted by `order` then `label`, exactly as DESIGN.md section 7.3 says. */
    public function query(): Builder
    {
        return NavItem::query()->orderBy('order')->orderBy('label');
    }

    /**
     * The nav for one client, already filtered by permission.
     *
     * @param  string[]  $permissions  what the caller holds; `*` is everything
     * @param  string[]  $only  module assignment: when non-empty, the nav is
     *                          narrowed to these keys *within* what the
     *                          permissions already allow. Never widened — a
     *                          link to an endpoint the role cannot open is the
     *                          appearance of access without any.
     */
    public function forClient(string $client, array $permissions, array $only = []): Collection
    {
        $column = $client === 'mobile' ? 'mobile' : 'web';

        return $this->query()
            ->where($column, true)
            ->get()
            ->filter(fn (NavItem $item): bool => $item->permission === null
                || in_array('*', $permissions, true)
                || in_array($item->permission, $permissions, true))
            // Applied after the permission filter, and that order is the whole
            // guarantee: intersecting a narrower list with an already-filtered
            // one can only ever remove rows.
            ->filter(fn (NavItem $item): bool => $only === [] || in_array($item->key, $only, true))
            ->values();
    }

    /**
     * The modules explicitly assigned to an account, or an empty list.
     *
     * Empty means the default — everything the role allows — which is the state
     * every account is in until somebody customises one.
     *
     * @return string[]
     */
    public function assignedKeys(int $userId): array
    {
        return DB::table('user_modules')->where('user_id', $userId)->pluck('nav_key')->all();
    }
}
