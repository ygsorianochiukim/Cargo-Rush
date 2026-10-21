<?php

declare(strict_types=1);

namespace App\Domain\Printing\Controllers;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Services\PrintableRegistry;
use App\Domain\Printing\Services\PrintRenderer;
use App\Domain\Printing\Support\Layout;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class PrintableController extends ApiController
{
    public function __construct(
        private readonly PrintableRegistry $registry,
        private readonly PrintRenderer $renderer,
        private readonly LogoStore $images,
        private readonly Tenant $tenant,
    ) {}

    public function index(): JsonResponse
    {
        return $this->payload(array_map(fn (Printable $p) => [
            'key' => $p->key(),
            'name' => $p->name(),
            'group' => $p->group(),
            'description' => $p->description(),
            'orientation' => $p->orientation(),
            'customised' => $this->registry->customised($p),
        ], $this->registry->all()));
    }

    public function show(string $printable): JsonResponse
    {
        return $this->payload($this->page($this->registry->find($printable)));
    }

    public function update(Request $request, string $printable): JsonResponse
    {
        $definition = $this->registry->find($printable);

        $this->registry->save($definition, $this->validated($request));

        return $this->payload($this->page($definition));
    }

    public function reset(string $printable): JsonResponse
    {
        $definition = $this->registry->find($printable);

        $this->registry->reset($definition);

        return $this->payload($this->page($definition));
    }

    public function preview(Request $request, string $printable): Response
    {
        $definition = $this->registry->find($printable);
        $layout = Layout::clean($this->validated($request), $definition->defaults());

        return $this->renderer->document($definition, $definition->sample(), $layout)
            ->stream($definition->key().'-preview.pdf');
    }

    public function storeImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp,bmp', 'max:'.(int) config('cargo.company.logo_max_kb')],
        ], [
            'image.required' => 'Choose an image file.',
            'image.image' => 'That has to be an image — a PNG, JPEG, GIF or WebP.',
            'image.mimes' => 'That format is not supported. Use a PNG, JPEG, GIF or WebP.',
            'image.max' => 'That file is too large. Anything up to a few megabytes is fine.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $path = $this->images->storeFitted($file, 1200, 1200);
        [$width, $height] = getimagesizefromstring((string) $this->images->contents($path)) ?: [1, 1];

        return $this->payload([
            'source' => 'upload:'.$path,
            'url' => $this->images->url($path),
            'ratio' => $height > 0 ? round($width / $height, 4) : 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->validate([
            'margin' => ['nullable', 'numeric', 'between:5,30'],
            'top_height' => ['nullable', 'numeric', 'between:0,280'],
            'bottom_height' => ['nullable', 'numeric', 'between:0,280'],
            'elements' => ['required', 'array', 'max:'.Layout::MAX_ELEMENTS],
            'elements.*.type' => ['required', 'in:'.implode(',', Layout::TYPES)],
            'elements.*.text' => ['nullable', 'string', 'max:2000'],
        ], [
            'elements.max' => 'A printable can hold up to '.Layout::MAX_ELEMENTS.' items.',
        ]);

        return $request->only(['margin', 'top_height', 'bottom_height', 'elements']);
    }

    /** @return array<string, mixed> */
    private function page(Printable $definition): array
    {
        $layout = $this->registry->layout($definition);
        $sources = collect($layout['elements'])->pluck('source')->filter(fn ($s) => is_string($s) && str_starts_with($s, 'upload:'));

        return [
            'key' => $definition->key(),
            'name' => $definition->name(),
            'orientation' => $definition->orientation(),
            'customised' => $this->registry->customised($definition),
            'fields' => $definition->fields(),
            'tables' => $definition->tables(),
            'layout' => $layout,
            'defaults' => $definition->defaults(),
            'sample' => $definition->sample(),
            'images' => [
                'logo' => $this->images->url($this->tenant->company()?->logo_path),
                ...$sources->mapWithKeys(fn (string $s) => [$s => $this->images->url(substr($s, 7))])->all(),
            ],
        ];
    }
}
