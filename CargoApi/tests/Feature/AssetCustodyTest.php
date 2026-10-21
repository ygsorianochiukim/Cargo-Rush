<?php

declare(strict_types=1);

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetTurnover;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => 'administrator']);

    $this->hire = fn (string $first, string $last, string $no) => Employee::create([
        'employee_no' => $no,
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Dispatcher',
        'hired_on' => '2026-01-05',
        'contact' => '0917 555 0101',
    ]);

    $this->marco = ($this->hire)('Marco', 'Reyes', 'EMP-0001');
    $this->liza = ($this->hire)('Liza', 'Santos', 'EMP-0002');

    $this->register = fn (array $payload = []) => $this->actingAs($this->admin)->postJson('/api/v1/assets', [
        'name' => 'Lenovo ThinkPad E14',
        'tag' => 'IT-0001',
        'purchased_on' => '2026-02-10',
        'cost_cents' => 4_250_000,
        ...$payload,
    ]);

    $this->turnover = fn (string $id, array $payload) => $this->actingAs($this->admin)
        ->postJson("/api/v1/assets/{$id}/turnover", ['turned_over_on' => '2026-10-01', ...$payload]);
});

describe('registering an asset', function (): void {
    it('keeps the purchase details and starts it on the shelf', function (): void {
        $category = $this->actingAs($this->admin)
            ->postJson('/api/v1/asset-categories', ['name' => 'LAPTOPS'])
            ->assertCreated()->json('data');
        $supplier = Supplier::factory()->create(['name' => 'Octagon Davao']);

        $asset = ($this->register)(['category_id' => $category['id'], 'supplier_id' => $supplier->id])
            ->assertCreated()->json('data');

        expect($asset['status'])->toBe('available')
            ->and($asset['purchased_on'])->toBe('2026-02-10')
            ->and($asset['cost_cents'])->toBe(4_250_000)
            ->and($asset['category_name'])->toBe('LAPTOPS')
            ->and($asset['supplier_name'])->toBe('Octagon Davao')
            ->and($asset['custodian_id'])->toBeNull()
            ->and(AssetTurnover::count())->toBe(0);
    });

    it('issues it straight to a custodian and logs the hand-over', function (): void {
        $asset = ($this->register)(['custodian_id' => $this->marco->id])->assertCreated()->json('data');

        expect($asset['status'])->toBe('assigned')
            ->and($asset['custodian_name'])->toBe('Marco Reyes')
            ->and($asset['assigned_on'])->toBe('2026-02-10');

        $log = AssetTurnover::sole();
        expect($log->from_employee_id)->toBeNull()
            ->and($log->to_employee_id)->toBe($this->marco->id);
    });

    it('numbers an untagged asset from its purchase date, or today without one', function (): void {
        $this->travelTo('2026-10-21');

        $first = ($this->register)(['tag' => null])->assertCreated()->json('data');
        $second = ($this->register)(['tag' => null, 'name' => 'Office chair', 'purchased_on' => null])->json('data');
        ($this->register)(['tag' => 'RAD-01', 'name' => 'Radio'])->assertCreated();
        $third = ($this->register)(['tag' => null, 'name' => 'Floor jack'])->json('data');

        expect($first['tag'])->toBe('Asset-INV-021026-0001')
            ->and($second['tag'])->toBe('Asset-INV-102126-0002')
            ->and($third['tag'])->toBe('Asset-INV-021026-0003');
    });

    it('never reuses the number of a deleted asset, and keeps the tag when an edit blanks it', function (): void {
        $first = ($this->register)(['tag' => null])->json('data');
        $this->actingAs($this->admin)->deleteJson("/api/v1/assets/{$first['id']}")->assertNoContent();

        $next = ($this->register)(['tag' => null])->json('data');
        expect($next['tag'])->toEndWith('-0002');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/assets/{$next['id']}", ['tag' => null, 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.tag', $next['tag']);
    });

    it('tags the assets registered before numbering existed', function (): void {
        $old = Asset::create(['name' => 'Old desk', 'purchased_on' => '2025-01-15']);
        Asset::create(['name' => 'Tagged', 'tag' => 'Asset-INV-010126-0004']);

        (require database_path('migrations/2026_10_21_000500_tag_untagged_assets.php'))->up();

        expect($old->refresh()->tag)->toBe('Asset-INV-011525-0005');
    });

    it('refuses a second asset with the same tag', function (): void {
        ($this->register)()->assertCreated();
        ($this->register)()->assertUnprocessable()->assertJsonValidationErrors('tag');
    });
});

describe('turnover', function (): void {
    it('moves an asset between custodians and keeps every step', function (): void {
        $asset = ($this->register)(['custodian_id' => $this->marco->id])->json('data');

        ($this->turnover)($asset['id'], ['to_employee_id' => $this->liza->id, 'condition' => 'fair'])
            ->assertOk()
            ->assertJsonPath('data.custodian_name', 'Liza Santos')
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.condition', 'fair');

        $returned = ($this->turnover)($asset['id'], [
            'to_employee_id' => null,
            'turned_over_on' => '2026-10-15',
            'status' => 'maintenance',
            'note' => 'Hinge cracked.',
        ])->assertOk()->json('data');

        expect($returned['custodian_id'])->toBeNull()
            ->and($returned['status'])->toBe('maintenance')
            ->and($returned['assigned_on'])->toBeNull();

        $history = $this->actingAs($this->admin)
            ->getJson("/api/v1/assets/{$asset['id']}/turnovers")
            ->assertOk()->json('data');

        expect($history)->toHaveCount(3)
            ->and($history[0]['from_name'])->toBe('Liza Santos')
            ->and($history[0]['to_name'])->toBeNull()
            ->and($history[0]['note'])->toBe('Hinge cracked.')
            ->and($history[1]['from_name'])->toBe('Marco Reyes')
            ->and($history[1]['to_name'])->toBe('Liza Santos');
    });

    it('refuses a hand-over to the person already holding it', function (): void {
        $asset = ($this->register)(['custodian_id' => $this->marco->id])->json('data');

        ($this->turnover)($asset['id'], ['to_employee_id' => $this->marco->id])
            ->assertUnprocessable()->assertJsonValidationErrors('to_employee_id');
    });

    it('refuses to take back an asset nobody holds', function (): void {
        $asset = ($this->register)()->json('data');

        ($this->turnover)($asset['id'], ['to_employee_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('to_employee_id');
    });

    it('refuses a turnover dated before the last hand-over', function (): void {
        $asset = ($this->register)(['custodian_id' => $this->marco->id, 'assigned_on' => '2026-09-01'])->json('data');

        ($this->turnover)($asset['id'], ['to_employee_id' => $this->liza->id, 'turned_over_on' => '2026-08-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('turned_over_on');
    });

    it('will not mark a held asset available by editing it', function (): void {
        $asset = ($this->register)(['custodian_id' => $this->marco->id])->json('data');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/assets/{$asset['id']}", ['status' => 'available'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        expect(Asset::find($asset['id'])->custodian_id)->toBe($this->marco->id);
    });
});

describe('the register', function (): void {
    it('filters by who holds what', function (): void {
        ($this->register)(['custodian_id' => $this->marco->id]);
        ($this->register)(['name' => 'Two-way radio', 'tag' => 'RAD-01', 'custodian_id' => $this->liza->id]);
        ($this->register)(['name' => 'Floor jack', 'tag' => 'TL-01']);

        $marco = $this->actingAs($this->admin)
            ->getJson("/api/v1/assets?custodian_id={$this->marco->id}")->json('data');
        $shelf = $this->actingAs($this->admin)->getJson('/api/v1/assets?status=available')->json('data');

        expect(array_column($marco, 'tag'))->toBe(['IT-0001'])
            ->and(array_column($shelf, 'tag'))->toBe(['TL-01']);
    });

    it('starts a new company with categories to file assets under', function (): void {
        app(CompanyProvisioner::class)->provision($this->company);
        app(CompanyProvisioner::class)->provision($this->company);

        $names = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?active=1')->json('data');

        expect(array_column($names, 'name'))->toEqualCanonicalizing(AssetCategorySeeder::NAMES);
    });

    it('lists the people who can hold an asset', function (): void {
        $people = $this->actingAs($this->admin)->getJson('/api/v1/assets/custodians')->assertOk()->json('data');

        expect(array_column($people, 'label'))->toBe(['Marco Reyes', 'Liza Santos']);
    });

    it('reports stock by category, by custodian, by year and by age', function (): void {
        $this->travelTo('2026-10-21');

        ($this->register)(['custodian_id' => $this->marco->id]);
        ($this->register)([
            'name' => 'Two-way radio', 'tag' => 'RAD-01', 'purchased_on' => '2020-03-01',
            'cost_cents' => 600_000, 'custodian_id' => $this->marco->id, 'condition' => 'damaged',
        ]);
        ($this->register)(['name' => 'Floor jack', 'tag' => 'TL-01', 'purchased_on' => null, 'cost_cents' => 150_000]);

        $report = $this->actingAs($this->admin)->getJson('/api/v1/assets/report')->assertOk()->json('data');

        expect($report['totals'])->toMatchArray([
            'count' => 3, 'cost_cents' => 5_000_000, 'assigned' => 2, 'available' => 1,
        ]);
        expect($report['by_custodian'])->toHaveCount(1)
            ->and($report['by_custodian'][0])->toMatchArray([
                'name' => 'Marco Reyes', 'count' => 2, 'cost_cents' => 4_850_000,
                'damaged' => 1, 'held_since' => '2020-03-01',
            ]);
        expect(array_column($report['by_year'], 'year'))->toBe([2026, 2020]);
        expect(collect($report['by_age'])->pluck('count', 'key')->all())->toMatchArray([
            'under-1' => 1, 'over-5' => 1, 'unknown' => 1,
        ]);
        expect($report['by_category'][0]['name'])->toBe('Uncategorised');
    });

    it('downloads the report as a PDF file', function (): void {
        ($this->register)(['custodian_id' => $this->marco->id]);

        $response = $this->actingAs($this->admin)->get('/api/v1/assets/report.pdf')->assertOk();

        expect($response->headers->get('content-type'))->toBe('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain('asset-report-')
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
    });

    it('logs every re-assignment across the register, filtered by date and person', function (): void {
        $laptop = ($this->register)(['custodian_id' => $this->marco->id, 'assigned_on' => '2026-03-01'])->json('data');
        ($this->register)(['name' => 'Floor jack', 'tag' => 'TL-01']);

        ($this->turnover)($laptop['id'], ['to_employee_id' => $this->liza->id, 'turned_over_on' => '2026-09-10']);

        $all = $this->actingAs($this->admin)->getJson('/api/v1/assets/turnovers')->assertOk()->json('data');
        $september = $this->actingAs($this->admin)
            ->getJson('/api/v1/assets/turnovers?from=2026-09-01&to=2026-09-30')->json('data');
        $liza = $this->actingAs($this->admin)
            ->getJson("/api/v1/assets/turnovers?employee_id={$this->liza->id}")->json('data');

        expect($all)->toHaveCount(2)
            ->and($september)->toHaveCount(1)
            ->and($september[0]['asset_tag'])->toBe('IT-0001')
            ->and($september[0]['from_name'])->toBe('Marco Reyes')
            ->and($liza)->toHaveCount(1);
    });

    it('puts the screens in their own Custodian group', function (): void {
        $nav = collect($this->actingAs($this->admin)->getJson('/api/v1/navigation')->json('data'))
            ->where('group', 'Custodian')
            ->pluck('label')
            ->all();

        expect(array_values($nav))->toBe([
            'Asset Inventory', 'Asset List', 'Asset Monitoring', 'Asset Re-assignment', 'Asset Report',
        ]);
    });

    it('lets the HR officer manage it and keeps the dispatcher out', function (): void {
        $hr = User::factory()->create(['role' => 'hr-officer']);
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($hr)->postJson('/api/v1/assets', ['name' => 'Office chair'])->assertCreated();
        $this->actingAs($dispatcher)->getJson('/api/v1/assets')->assertForbidden();
    });
});
