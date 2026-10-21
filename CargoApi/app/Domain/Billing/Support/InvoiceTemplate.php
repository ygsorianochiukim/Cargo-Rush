<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

final class InvoiceTemplate
{
    public const ACCENT = '#15589c';

    public const TITLES = [
        'sales' => 'Sales Invoice',
        'delivery' => 'Delivery Invoice',
        'bill' => 'Bill',
    ];

    public const LABELS = [
        'billed_to' => 'Billed to',
        'issued' => 'Issued',
        'due' => 'Due',
        'terms' => 'Terms',
        'currency' => 'Currency',
        'lines_heading' => 'For the following delivery',
        'col_description' => 'Description',
        'col_reference' => 'Reference',
        'col_delivered' => 'Delivered',
        'col_weight' => 'Weight',
        'col_amount' => 'Amount',
        'amount_in_words' => 'Amount in words',
        'net_of_vat' => 'Net of VAT',
        'vat' => 'VAT',
        'invoice_total' => 'Invoice total',
        'amount_payable' => 'Amount payable',
        'received' => 'Received',
        'balance_due' => 'Balance due',
        'payments_heading' => 'Payments received',
        'issued_by' => 'Issued by',
        'received_by' => 'Received by',
        'received_by_hint' => 'Name, signature and date',
    ];

    public const SHOW = [
        'logo' => true,
        'address' => true,
        'contact' => true,
        'tin' => true,
        'status' => true,
        'terms' => true,
        'currency' => true,
        'reference' => true,
        'delivered' => true,
        'weight' => true,
        'unit_driver' => true,
        'amount_in_words' => true,
        'payments' => true,
        'signatures' => true,
        'footer' => true,
    ];

    public const MAX_FIELDS = 8;

    public const MAX_BLOCKS = 6;

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function resolve(?array $stored): array
    {
        $stored ??= [];

        return [
            'accent' => self::accent($stored['accent'] ?? null),
            'titles' => self::merge(self::TITLES, $stored['titles'] ?? []),
            'labels' => self::merge(self::LABELS, $stored['labels'] ?? []),
            'show' => self::flags($stored['show'] ?? []),
            'fields' => array_values(array_filter(
                (array) ($stored['fields'] ?? []),
                static fn ($f) => is_array($f) && trim((string) ($f['label'] ?? '')) !== '',
            )),
            'blocks' => array_values(array_filter(
                (array) ($stored['blocks'] ?? []),
                static fn ($b) => is_array($b) && (trim((string) ($b['title'] ?? '')) !== '' || trim((string) ($b['body'] ?? '')) !== ''),
            )),
            'footer' => self::text($stored['footer'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::resolve([]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $only = static fn (array $defaults, mixed $given): array => array_filter(
            array_map(static fn ($v) => self::text($v), array_intersect_key((array) $given, $defaults)),
            static fn ($v) => $v !== null,
        );

        return [
            'accent' => self::accent($input['accent'] ?? null),
            'titles' => $only(self::TITLES, $input['titles'] ?? []),
            'labels' => $only(self::LABELS, $input['labels'] ?? []),
            'show' => self::flags($input['show'] ?? []),
            'fields' => array_map(static fn (array $f) => [
                'label' => (string) self::text($f['label'] ?? ''),
                'value' => (string) (self::text($f['value'] ?? '') ?? ''),
            ], array_values(array_filter((array) ($input['fields'] ?? []), static fn ($f) => is_array($f) && self::text($f['label'] ?? '') !== null))),
            'blocks' => array_map(static fn (array $b) => [
                'title' => (string) (self::text($b['title'] ?? '') ?? ''),
                'body' => rtrim((string) ($b['body'] ?? '')),
            ], array_values(array_filter((array) ($input['blocks'] ?? []), static fn ($b) => is_array($b) && (self::text($b['title'] ?? '') !== null || self::text($b['body'] ?? '') !== null)))),
            'footer' => self::text($input['footer'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private static function merge(array $defaults, mixed $given): array
    {
        $merged = $defaults;

        foreach ((array) $given as $key => $value) {
            if (array_key_exists($key, $defaults) && self::text($value) !== null) {
                $merged[$key] = (string) self::text($value);
            }
        }

        return $merged;
    }

    /** @return array<string, bool> */
    private static function flags(mixed $given): array
    {
        $flags = self::SHOW;

        foreach ((array) $given as $key => $value) {
            if (array_key_exists($key, $flags)) {
                $flags[$key] = (bool) $value;
            }
        }

        return $flags;
    }

    private static function accent(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : self::ACCENT;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
