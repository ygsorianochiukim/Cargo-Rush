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

    public function query(): Builder
    {
        return NavItem::query()->orderBy('order')->orderBy('label');
    }

    /**
     * @param  string[]  $permissions
     * @param  string[]  $only
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
            ->filter(fn (NavItem $item): bool => $only === [] || in_array($item->key, $only, true))
            ->values();
    }

    /** @return string[] */
    public function assignedKeys(int $userId): array
    {
        return DB::table('user_modules')->where('user_id', $userId)->pluck('nav_key')->all();
    }
}
