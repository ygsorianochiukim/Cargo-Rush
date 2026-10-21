<?php

declare(strict_types=1);

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Layout;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;
use Illuminate\Http\Response;

class PrintRenderer
{
    public function __construct(
        private readonly PrintableRegistry $registry,
        private readonly LogoStore $images,
        private readonly Tenant $tenant,
    ) {}

    /** @param array<string, mixed> $data */
    public function download(Printable $printable, array $data, string $filename): Response
    {
        return $this->document($printable, $data)->download($filename);
    }

    /**
     * @param  array<int, array{0: Printable, 1: array<string, mixed>}>  $parts
     */
    public function downloadParts(array $parts, string $filename): Response
    {
        return $this->parts(array_map(static fn (array $p) => [$p[0], $p[1], null], $parts))->download($filename);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $layout
     */
    public function document(Printable $printable, array $data, ?array $layout = null): Document
    {
        return $this->parts([[$printable, $data, $layout]]);
    }

    /**
     * @param  array<int, array{0: Printable, 1: array<string, mixed>, 2: array<string, mixed>|null}>  $parts
     */
    private function parts(array $parts): Document
    {
        $orientation = $parts[0][0]->orientation();
        $pageWidth = $orientation === 'landscape' ? 297 : 210;
        $images = ['logo' => $this->images->dataUri($this->tenant->company()?->logo_path)];

        $rendered = array_map(function (array $part) use ($pageWidth, &$images): array {
            [$printable, $data, $layout] = $part;
            $layout ??= $this->registry->layout($printable);

            foreach ($layout['elements'] as $element) {
                $source = $element['source'] ?? null;

                if (is_string($source) && str_starts_with($source, 'upload:') && ! array_key_exists($source, $images)) {
                    $images[$source] = $this->images->dataUri(substr($source, 7));
                }
            }

            return [
                'layout' => $layout,
                'pages' => $data['pages'] ?? [],
                'contentWidth' => $pageWidth - 2 * (float) $layout['margin'],
            ];
        }, $parts);

        return Pdf::loadView('pdf.printable', [
            'parts' => $rendered,
            'margin' => (float) $rendered[0]['layout']['margin'],
            'images' => $images,
            'fill' => static fn (string $text, array $values): string => Layout::fill($text, $values),
            'title' => $parts[0][0]->name(),
        ])->setPaper('a4', $orientation);
    }
}
