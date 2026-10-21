<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Printing\Models\PrintTemplate;
use App\Domain\Printing\Services\PrintableRegistry;
use App\Domain\Printing\Support\Layout;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => 'administrator']);
    $this->dispatcher = User::factory()->create(['role' => 'dispatcher']);

    $this->editor = fn (string $key) => $this->actingAs($this->admin)
        ->getJson("/api/v1/company/printables/{$key}")
        ->assertOk()
        ->json('data');
});

describe('the picker', function (): void {
    it('lists every printable with its group, none customised yet', function (): void {
        $list = $this->actingAs($this->admin)->getJson('/api/v1/company/printables')->assertOk()->json('data');

        expect(count($list))->toBe(count(PrintableRegistry::PRINTABLES))
            ->and(collect($list)->pluck('key'))->toContain('invoice')
            ->and(collect($list)->pluck('customised')->unique()->all())->toBe([false]);
    });

    it('gives every printable a default layout that survives its own cleaning', function (): void {
        foreach (app(PrintableRegistry::class)->all() as $printable) {
            $defaults = $printable->defaults();

            expect(Layout::clean($defaults, $defaults)['elements'])
                ->toHaveCount(count($defaults['elements']), $printable->key().' loses elements when cleaned');

            $ids = collect($defaults['elements'])->pluck('id');
            expect($ids->duplicates()->all())->toBe([], $printable->key().' repeats an element id');

            $tables = collect($defaults['elements'])->where('type', 'table')->pluck('table')->unique();
            expect(array_diff($tables->all(), array_keys($printable->tables())))->toBe([], $printable->key().' places an unknown table');

            $sample = $printable->sample();
            expect($sample['pages'])->not->toBeEmpty($printable->key().' has no sample page');
        }
    });

    it('opens one printable with its fields, tables, layout and sample', function (): void {
        $page = ($this->editor)('invoice');

        expect($page['fields'])->toHaveKey('customer.name')
            ->and($page['tables'])->toHaveKey('lines')
            ->and($page['layout']['elements'])->not->toBeEmpty()
            ->and($page['sample']['pages'][0]['values'])->toHaveKey('document.number');
    });

    it('is only for people who manage the company', function (): void {
        $this->actingAs($this->dispatcher)->getJson('/api/v1/company/printables')->assertForbidden();
    });
});

describe('editing a layout', function (): void {
    it('moves, rewrites and adds items, and keeps them for this company only', function (): void {
        $layout = ($this->editor)('invoice')['layout'];

        $layout['elements'] = collect($layout['elements'])->map(function (array $e) {
            if ($e['id'] === 'number') {
                $e['x'] = 5;
                $e['y'] = 40;
                $e['text'] = 'No. {{document.number}}';
            }

            return $e;
        })->push(Layout::text('note', 'Note', 'bottom', 0, 40, 100, 'Thank you!') + ['custom' => true])->all();
        $layout['top_height'] = 90;

        $saved = $this->actingAs($this->admin)->putJson('/api/v1/company/printables/invoice', $layout)
            ->assertOk()->json('data');

        $number = collect($saved['layout']['elements'])->firstWhere('id', 'number');

        expect($saved['customised'])->toBeTrue()
            ->and($number['x'])->toEqual(5)
            ->and($number['text'])->toBe('No. {{document.number}}')
            ->and(collect($saved['layout']['elements'])->firstWhere('id', 'note')['text'])->toBe('Thank you!')
            ->and($saved['layout']['top_height'])->toEqual(90)
            ->and(PrintTemplate::count())->toBe(1);
    });

    it('clamps positions onto the page and drops what it cannot read', function (): void {
        $this->actingAs($this->admin)->putJson('/api/v1/company/printables/invoice', [
            'elements' => [
                ['type' => 'text', 'id' => 'a', 'x' => -50, 'y' => 9999, 'w' => 60, 'text' => 'x', 'color' => 'red'],
                ['type' => 'nonsense', 'id' => 'b'],
            ],
        ])->assertUnprocessable();

        $saved = $this->actingAs($this->admin)->putJson('/api/v1/company/printables/invoice', [
            'elements' => [['type' => 'text', 'id' => 'a', 'x' => -50, 'y' => 9999, 'w' => 60, 'text' => 'x', 'color' => 'red']],
        ])->assertOk()->json('data.layout.elements.0');

        expect($saved['x'])->toEqual(0)->and($saved['y'])->toEqual(400)->and($saved['color'])->toBe(Layout::INK);
    });

    it('goes back to the default on reset', function (): void {
        $layout = ($this->editor)('invoice')['layout'];
        $layout['elements'] = array_slice($layout['elements'], 0, 2);
        $this->actingAs($this->admin)->putJson('/api/v1/company/printables/invoice', $layout)->assertOk();

        $reset = $this->actingAs($this->admin)->deleteJson('/api/v1/company/printables/invoice')->assertOk()->json('data');

        expect($reset['customised'])->toBeFalse()
            ->and(count($reset['layout']['elements']))->toBe(count($reset['defaults']['elements']));
    });

    it('previews an unsaved layout as a PDF', function (): void {
        $layout = ($this->editor)('invoice')['layout'];

        $response = $this->actingAs($this->admin)->post('/api/v1/company/printables/invoice/preview.pdf', $layout)->assertOk();

        expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
    });

    it('stores an image for a layout to place', function (): void {
        Storage::fake(config('cargo.company.disk'));

        $image = $this->actingAs($this->admin)->post('/api/v1/company/printables/images', [
            'image' => UploadedFile::fake()->image('stamp.png', 400, 200),
        ])->assertOk()->json('data');

        expect($image['source'])->toStartWith('upload:')
            ->and($image['ratio'])->toEqual(2);
    });
});

describe('filling text', function (): void {
    it('drops a line whose fields are all empty, and hides flag fields', function (): void {
        $text = "TIN {{company.tin}}\nPaid{{?paid}}\n{{name}} rocks";

        expect(Layout::fill($text, ['company.tin' => '', 'paid' => 'yes', 'name' => 'Cargo']))->toBe("Paid\nCargo rocks")
            ->and(Layout::fill($text, ['company.tin' => '123', 'paid' => '', 'name' => '']))->toBe('TIN 123');
    });
});
