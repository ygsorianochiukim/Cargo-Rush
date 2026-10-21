@php
    $style = function (array $e, float $contentWidth): string {
        $w = $e['w'] ?? null;
        $css = sprintf('position:absolute;left:%smm;top:%smm;', $e['x'], $e['y']);
        $css .= $w !== null ? sprintf('width:%smm;', min($w, $contentWidth)) : '';

        return $css;
    };

    $textStyle = fn (array $e): string => sprintf(
        'font-size:%spt;line-height:%smm;color:%s;text-align:%s;font-weight:%s;font-style:%s;%s',
        $e['size'], round($e['size'] * ($e['line_height'] ?? 1.35) * 0.3528 / 1.28, 3), $e['color'], $e['align'], $e['bold'] ? 'bold' : 'normal',
        $e['italic'] ? 'italic' : 'normal', $e['upper'] ? 'text-transform:uppercase;letter-spacing:0.06em;' : '',
    );

    $sheets = [];
    foreach ($parts as $part) {
        foreach ($part['pages'] as $page) {
            $sheets[] = ['part' => $part, 'values' => $page['values'] ?? [], 'tables' => $page['tables'] ?? []];
        }
    }
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: {{ $margin }}mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1b2430; margin: 0; }
        .zone { position: relative; }
        .page { page-break-after: always; }
        .page.last { page-break-after: auto; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { font-weight: normal; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7a8c; padding: 1.4mm 1.5mm 1.4mm 0; }
        table.grid td { border-bottom: 0.2mm solid #eef1f4; padding: 1.6mm 1.5mm 1.6mm 0; vertical-align: top; }
        table.grid tr.foot td { border-bottom: 0; border-top: 0.3mm solid #dfe4ea; font-weight: bold; }
        table.grid tr.striped td { background: #f6f8fa; }
        .caption { text-transform: uppercase; letter-spacing: 0.05em; color: #6b7a8c; margin-bottom: 1.5mm; }
    </style>
</head>
<body>
@foreach ($sheets as $index => $sheet)
    @php
        $layout = $sheet['part']['layout'];
        $contentWidth = $sheet['part']['contentWidth'];
        $values = $sheet['values'];
        $elements = collect($layout['elements'])->reject(fn ($e) => $e['hidden'] ?? false);
    @endphp
    <div class="page {{ $index === count($sheets) - 1 ? 'last' : '' }}">
        @if ($layout['top_height'] > 0)
            <div class="zone" style="height: {{ $layout['top_height'] }}mm; width: {{ $contentWidth }}mm;">
                @foreach ($elements->where('zone', 'top') as $e)
                    @include('pdf.partials.printable-element', ['e' => $e])
                @endforeach
            </div>
        @endif

        @foreach ($elements->where('zone', 'flow')->sortBy('y') as $e)
            @php($table = $sheet['tables'][$e['table']] ?? null)
            @if ($table && count($table['rows'] ?? []) + count($table['foot'] ?? []) > 0)
                @php($columns = collect($e['columns'])->reject(fn ($c) => $c['hidden'])->values())
                <div style="margin-top: {{ $e['space_before'] }}mm; font-size: {{ $e['size'] }}pt;">
                    @if (trim($fill($e['title'], $values)) !== '')
                        <div class="caption" style="font-size: {{ max(6, $e['size'] - 1) }}pt;">{{ $fill($e['title'], $values) }}</div>
                    @endif
                    <table class="grid">
                        <thead>
                            <tr>
                                @foreach ($columns as $c)
                                    <th style="text-align: {{ $c['align'] }}; font-size: {{ max(6, $e['size'] - 1.5) }}pt; border-bottom: 0.4mm solid {{ $e['color'] }};">{{ $c['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table['rows'] ?? [] as $i => $row)
                                <tr class="{{ $e['striped'] && $i % 2 === 1 ? 'striped' : '' }}">
                                    @foreach ($columns as $c)
                                        <td style="text-align: {{ $c['align'] }};">{!! nl2br(e($row[$c['key']] ?? '')) !!}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            @foreach ($table['foot'] ?? [] as $row)
                                <tr class="foot">
                                    @foreach ($columns as $c)
                                        <td style="text-align: {{ $c['align'] }};">{{ $row[$c['key']] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endforeach

        @if ($layout['bottom_height'] > 0)
            <div class="zone" style="height: {{ $layout['bottom_height'] }}mm; width: {{ $contentWidth }}mm; page-break-inside: avoid; margin-top: 3mm;">
                @foreach ($elements->where('zone', 'bottom') as $e)
                    @include('pdf.partials.printable-element', ['e' => $e])
                @endforeach
            </div>
        @endif
    </div>
@endforeach
</body>
</html>
