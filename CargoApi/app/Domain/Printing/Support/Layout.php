<?php

declare(strict_types=1);

namespace App\Domain\Printing\Support;

final class Layout
{
    public const ACCENT = '#15589c';

    public const INK = '#1b2430';

    public const MUTED = '#6b7a8c';

    public const RULE = '#dfe4ea';

    public const TYPES = ['text', 'image', 'table', 'line', 'box'];

    public const MAX_ELEMENTS = 150;

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    public static function make(array $elements, float $topHeight, float $bottomHeight, float $margin = 14): array
    {
        return [
            'margin' => $margin,
            'top_height' => $topHeight,
            'bottom_height' => $bottomHeight,
            'elements' => $elements,
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public static function text(string $id, string $label, string $zone, float $x, float $y, float $w, string $text, array $style = []): array
    {
        return [
            'id' => $id,
            'type' => 'text',
            'label' => $label,
            'zone' => $zone,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => null,
            'text' => $text,
            'size' => $style['size'] ?? 9,
            'bold' => $style['bold'] ?? false,
            'italic' => $style['italic'] ?? false,
            'upper' => $style['upper'] ?? false,
            'align' => $style['align'] ?? 'left',
            'color' => $style['color'] ?? self::INK,
            'line_height' => $style['line_height'] ?? 1.35,
            'hidden' => false,
            'custom' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function image(string $id, string $label, string $zone, float $x, float $y, float $w, float $h, string $source = 'logo'): array
    {
        return [
            'id' => $id,
            'type' => 'image',
            'label' => $label,
            'zone' => $zone,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'source' => $source,
            'hidden' => false,
            'custom' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function line(string $id, string $label, string $zone, float $x, float $y, float $w, string $color = self::RULE, float $thickness = 0.3): array
    {
        return [
            'id' => $id,
            'type' => 'line',
            'label' => $label,
            'zone' => $zone,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => null,
            'color' => $color,
            'thickness' => $thickness,
            'hidden' => false,
            'custom' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function box(string $id, string $label, string $zone, float $x, float $y, float $w, float $h, string $color = self::RULE, ?string $fill = null, float $thickness = 0.3): array
    {
        return [
            'id' => $id,
            'type' => 'box',
            'label' => $label,
            'zone' => $zone,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'color' => $color,
            'fill' => $fill,
            'thickness' => $thickness,
            'hidden' => false,
            'custom' => false,
        ];
    }

    /**
     * @param  array<int, array{key: string, label: string, align?: string, width?: float}>  $columns
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public static function table(string $id, string $label, string $table, array $columns, float $order, array $style = []): array
    {
        return [
            'id' => $id,
            'type' => 'table',
            'label' => $label,
            'zone' => 'flow',
            'x' => 0,
            'y' => $order,
            'w' => null,
            'h' => null,
            'table' => $table,
            'title' => $style['title'] ?? '',
            'size' => $style['size'] ?? 8.5,
            'color' => $style['color'] ?? self::ACCENT,
            'striped' => $style['striped'] ?? false,
            'space_before' => $style['space_before'] ?? 4,
            'columns' => array_map(static fn (array $c) => [
                'key' => $c['key'],
                'label' => $c['label'],
                'align' => $c['align'] ?? 'left',
                'width' => $c['width'] ?? null,
                'hidden' => false,
            ], $columns),
            'hidden' => false,
            'custom' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function clean(array $input, array $defaults): array
    {
        $num = static fn (mixed $v, float $min, float $max, float $fallback): float => is_numeric($v)
            ? round(max($min, min($max, (float) $v)), 2)
            : $fallback;

        $color = static fn (mixed $v, string $fallback): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1
            ? strtolower($v)
            : $fallback;

        $str = static fn (mixed $v, int $max): string => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);

        $elements = [];

        foreach (array_slice((array) ($input['elements'] ?? []), 0, self::MAX_ELEMENTS) as $raw) {
            if (! is_array($raw) || ! in_array($raw['type'] ?? null, self::TYPES, true)) {
                continue;
            }

            $type = $raw['type'];
            $zone = $type === 'table' ? 'flow' : (in_array($raw['zone'] ?? null, ['top', 'bottom'], true) ? $raw['zone'] : 'top');

            $element = [
                'id' => preg_replace('/[^a-z0-9_-]/i', '', $str($raw['id'] ?? '', 40)) ?: 'el'.count($elements),
                'type' => $type,
                'label' => $str($raw['label'] ?? '', 60),
                'zone' => $zone,
                'x' => $num($raw['x'] ?? 0, 0, 400, 0),
                'y' => $num($raw['y'] ?? 0, 0, 400, 0),
                'w' => isset($raw['w']) && $raw['w'] !== null ? $num($raw['w'], 1, 400, 50) : null,
                'h' => isset($raw['h']) && $raw['h'] !== null ? $num($raw['h'], 1, 400, 10) : null,
                'hidden' => (bool) ($raw['hidden'] ?? false),
                'custom' => (bool) ($raw['custom'] ?? false),
            ];

            $element += match ($type) {
                'text' => [
                    'text' => $str($raw['text'] ?? '', 2000),
                    'size' => $num($raw['size'] ?? 9, 5, 48, 9),
                    'bold' => (bool) ($raw['bold'] ?? false),
                    'italic' => (bool) ($raw['italic'] ?? false),
                    'upper' => (bool) ($raw['upper'] ?? false),
                    'align' => in_array($raw['align'] ?? null, ['left', 'center', 'right'], true) ? $raw['align'] : 'left',
                    'color' => $color($raw['color'] ?? null, self::INK),
                    'line_height' => $num($raw['line_height'] ?? 1.35, 0.8, 3, 1.35),
                ],
                'image' => [
                    'source' => self::source($raw['source'] ?? 'logo'),
                ],
                'line' => [
                    'color' => $color($raw['color'] ?? null, self::RULE),
                    'thickness' => $num($raw['thickness'] ?? 0.3, 0.1, 3, 0.3),
                ],
                'box' => [
                    'color' => $color($raw['color'] ?? null, self::RULE),
                    'fill' => isset($raw['fill']) && $raw['fill'] !== null ? $color($raw['fill'], '#ffffff') : null,
                    'thickness' => $num($raw['thickness'] ?? 0.3, 0, 3, 0.3),
                ],
                'table' => [
                    'table' => $str($raw['table'] ?? '', 40),
                    'title' => $str($raw['title'] ?? '', 120),
                    'size' => $num($raw['size'] ?? 8.5, 5, 20, 8.5),
                    'color' => $color($raw['color'] ?? null, self::ACCENT),
                    'striped' => (bool) ($raw['striped'] ?? false),
                    'space_before' => $num($raw['space_before'] ?? 4, 0, 100, 4),
                    'columns' => array_map(static fn ($c) => [
                        'key' => $str($c['key'] ?? '', 40),
                        'label' => $str($c['label'] ?? '', 60),
                        'align' => in_array($c['align'] ?? null, ['left', 'center', 'right'], true) ? $c['align'] : 'left',
                        'width' => isset($c['width']) && is_numeric($c['width']) && (float) $c['width'] > 0 ? $num($c['width'], 3, 280, 20) : null,
                        'hidden' => (bool) ($c['hidden'] ?? false),
                    ], array_values(array_filter((array) ($raw['columns'] ?? []), 'is_array'))),
                ],
            };

            $elements[] = $element;
        }

        return [
            'margin' => $num($input['margin'] ?? $defaults['margin'], 5, 30, (float) $defaults['margin']),
            'top_height' => $num($input['top_height'] ?? $defaults['top_height'], 0, 280, (float) $defaults['top_height']),
            'bottom_height' => $num($input['bottom_height'] ?? $defaults['bottom_height'], 0, 280, (float) $defaults['bottom_height']),
            'elements' => $elements,
        ];
    }

    /** @param array<string, string> $values */
    public static function fill(string $text, array $values): string
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            preg_match_all('/\{\{\s*(\??)([a-z0-9_.]+)\s*\}\}/i', $line, $found, PREG_SET_ORDER);

            if ($found !== [] && collect($found)->every(fn ($m) => trim((string) ($values[$m[2]] ?? '')) === '')) {
                continue;
            }

            $lines[] = preg_replace_callback(
                '/\{\{\s*(\??)([a-z0-9_.]+)\s*\}\}/i',
                static fn ($m) => $m[1] === '?' ? '' : (string) ($values[$m[2]] ?? ''),
                $line,
            );
        }

        return implode("\n", $lines);
    }

    private static function source(mixed $value): string
    {
        $value = is_string($value) ? $value : 'logo';

        return $value === 'logo' || preg_match('#^upload:[A-Za-z0-9/_.-]+\.png$#', $value) === 1 ? $value : 'logo';
    }
}
