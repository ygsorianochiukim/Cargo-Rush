@if ($e['type'] === 'text')
    @php($content = $fill($e['text'], $values))
    @if (trim($content) !== '')
        <div style="{{ $style($e, $contentWidth) }}{{ $textStyle($e) }}">{!! nl2br(e($content)) !!}</div>
    @endif
@elseif ($e['type'] === 'image')
    @if ($images[$e['source']] ?? null)
        <div style="{{ $style($e, $contentWidth) }}height: {{ $e['h'] ?? 15 }}mm;">
            <img src="{{ $images[$e['source']] }}" style="max-width: {{ $e['w'] ?? 15 }}mm; max-height: {{ $e['h'] ?? 15 }}mm;" alt="">
        </div>
    @endif
@elseif ($e['type'] === 'line')
    <div style="{{ $style($e, $contentWidth) }}height: 0; border-top: {{ $e['thickness'] }}mm solid {{ $e['color'] }};"></div>
@elseif ($e['type'] === 'box')
    <div style="{{ $style($e, $contentWidth) }}height: {{ $e['h'] ?? 10 }}mm; border: {{ $e['thickness'] }}mm solid {{ $e['color'] }};{{ $e['fill'] ? 'background:'.$e['fill'].';' : '' }}"></div>
@endif
