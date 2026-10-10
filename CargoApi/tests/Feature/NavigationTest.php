<?php

declare(strict_types=1);

use App\Domain\Identity\Models\NavItem;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);
});

function sidebarRows(): Collection
{
    return NavItem::query()
        ->where('web', true)
        ->orderBy('order')
        ->orderBy('label')
        ->get();
}

function sidebarHeadings(): array
{
    $headings = [];

    foreach (sidebarRows() as $row) {
        if ($headings === [] || end($headings) !== $row->group) {
            $headings[] = $row->group;
        }
    }

    return $headings;
}

describe('the groups', function (): void {
    it('prints every heading exactly once', function (): void {
        $headings = sidebarHeadings();

        expect($headings)->toBe(array_values(array_unique($headings)));
    });

    it('reads in the order the work is done', function (): void {
        expect(sidebarHeadings())->toBe([
            'Operations',
            'Fleet',
            'Sales & Billing',
            'Reports',
            'Accounting',
            'People',
            'Support',
            'Administration',
        ]);
    });

    it('keeps every group short enough to scan', function (): void {
        $sizes = sidebarRows()->groupBy('group')->map->count();

        foreach ($sizes as $group => $size) {
            expect($size)->toBeLessThanOrEqual(7, "{$group} has {$size} modules");
        }
    });
});

describe('the numbering', function (): void {
    it('gives every module an order of its own', function (): void {
        $orders = NavItem::query()->pluck('order');

        expect($orders->count())->toBe($orders->unique()->count());
    });

    it('keeps each group inside its own hundred', function (): void {
        $bands = sidebarRows()
            ->groupBy('group')
            ->map(static fn ($rows) => $rows->map(static fn (NavItem $row): int => intdiv($row->order, 100))->unique());

        foreach ($bands as $group => $hundreds) {
            expect($hundreds)->toHaveCount(1, "{$group} straddles more than one band");
        }

        expect($bands->flatten()->duplicates())->toBeEmpty();
    });
});

describe('what the rows point at', function (): void {
    it('drops a module this seeder no longer defines', function (): void {
        NavItem::create([
            'key' => 'salary-structure',
            'label' => 'Salary Structure',
            'icon' => 'wallet',
            'route' => '/salary-structure',
            'order' => 641,
            'mobile' => false,
            'web' => true,
            'group' => 'People',
        ]);

        $this->seed(NavigationSeeder::class);

        expect(NavItem::query()->where('key', 'salary-structure')->exists())->toBeFalse();
    });

    it('leaves the modules it does define alone', function (): void {
        expect(NavItem::query()->count())->toBeGreaterThan(20)
            ->and(NavItem::query()->where('key', 'access')->exists())->toBeTrue();
    });

    it('settles the company and its rates under Administration, not HR', function (): void {
        $access = NavItem::query()->where('key', 'access')->firstOrFail();

        expect($access->group)->toBe('Administration')
            ->and($access->permission)->toBe('access.view');
    });
});

describe('the handset tabs', function (): void {
    it('keeps all three sets out of the sidebar', function (): void {
        $groups = sidebarRows()->pluck('group')->unique();

        expect($groups)->not->toContain('Driver', 'Customer', 'Trucker');
    });

    it('still serves the driver their own tabs', function (): void {
        $driver = User::factory()->create(['role' => Role::Driver->value]);

        $keys = collect(
            $this->actingAs($driver)->getJson('/api/v1/navigation?client=mobile')->assertOk()->json('data')
        )->pluck('key');

        expect($keys)->toContain('cargo', 'tracking', 'inspect');
    });
});
