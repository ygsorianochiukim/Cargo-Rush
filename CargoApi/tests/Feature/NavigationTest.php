<?php

declare(strict_types=1);

use App\Domain\Identity\Models\NavItem;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Collection;

/**
 * The shape of the sidebar, which is a property of the numbers rather than of
 * the CSS.
 *
 * The API sorts every nav row by `order`, and the client walks that one list
 * starting a new heading whenever the group name changes. **A group is
 * therefore a contiguous run of orders**, not a label the client gathers by —
 * and the failure mode when two groups interleave is not a wrong sort, it is a
 * heading printed twice with the intruder wedged between the halves:
 *
 *     Operations | Assets | Finance | Business | Finance | Business | HR | Support
 *
 * That is what the sidebar did for several releases, because `customers` and
 * `journal` both sat at `order` 90. Nobody reads a seeder when a sidebar looks
 * wrong; it reads like a styling bug, so it stayed.
 *
 * These tests are the reason it cannot come back. They are about the seeded
 * data rather than about any endpoint's response, which is why they assert
 * against the rows and not through HTTP wherever they can.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);
});

/** The rows as the sidebar walks them: sorted, web only. */
function sidebarRows(): Collection
{
    return NavItem::query()
        ->where('web', true)
        ->orderBy('order')
        ->orderBy('label')
        ->get();
}

/** The headings the client would print, in order, repeats included. */
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

        // The whole point. `array_unique` collapses a repeat, so a heading
        // printed twice makes these two arrays different lengths.
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

        // Five is the longest here and there is no rule that says five. What
        // there is: a ten-item Finance group that mixed the workbook reports,
        // the books and money owed, and could not be read at a glance. If a
        // group grows past seven it has probably become two groups.
        foreach ($sizes as $group => $size) {
            expect($size)->toBeLessThanOrEqual(7, "{$group} has {$size} modules");
        }
    });
});

describe('the numbering', function (): void {
    it('gives every module an order of its own', function (): void {
        $orders = NavItem::query()->pluck('order');

        // A tie is resolved by label, which is deterministic and meaningless:
        // it put Other Expenses above Payables because O sorts before P. It is
        // also how two groups end up interleaved, since the tie can fall
        // between rows in different groups.
        expect($orders->count())->toBe($orders->unique()->count());
    });

    it('keeps each group inside its own hundred', function (): void {
        // The bands are what make an insert safe: a new module takes the next
        // ten in its group's hundred and cannot collide with a neighbour's.
        $bands = sidebarRows()
            ->groupBy('group')
            ->map(static fn ($rows) => $rows->map(static fn (NavItem $row): int => intdiv($row->order, 100))->unique());

        foreach ($bands as $group => $hundreds) {
            expect($hundreds)->toHaveCount(1, "{$group} straddles more than one band");
        }

        // And no two groups share one, which is the same guarantee read the
        // other way round.
        expect($bands->flatten()->duplicates())->toBeEmpty();
    });
});

describe('what the rows point at', function (): void {
    it('drops a module this seeder no longer defines', function (): void {
        // The Salary Structure case. Its screen was removed and its row was
        // not, because the seeder only ever wrote rows — so the sidebar carried
        // a link to a route that did not exist. Anything in the table that this
        // file has stopped saying is now taken out.
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
        // The prune is a `whereNotIn`, and the failure worth guarding against
        // is the one where it empties the table.
        expect(NavItem::query()->count())->toBeGreaterThan(20)
            ->and(NavItem::query()->where('key', 'access')->exists())->toBeTrue();
    });

    it('settles the company and its rates under Administration, not HR', function (): void {
        $access = NavItem::query()->where('key', 'access')->firstOrFail();

        // What that screen holds is the company record, the yard pin, the
        // payroll cutoff, the rates card, the roles and the positions. Filing
        // it under the roster said it was an HR job; its permission never did.
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

        // Renumbering the sidebar moved these rows from the twenties to the
        // nine hundreds. It changes nothing on the handset — `cargoApp` walks
        // its own hardcoded tab list and takes only the label, icon and badge
        // from here — but the tabs still have to be *served*.
        expect($keys)->toContain('cargo', 'tracking', 'inspect');
    });
});
