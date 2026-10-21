<?php

declare(strict_types=1);

namespace App\Domain\Printing\Contracts;

interface Printable
{
    public function key(): string;

    public function name(): string;

    public function group(): string;

    public function description(): string;

    /** @return 'portrait'|'landscape' */
    public function orientation(): string;

    /** @return array<string, mixed> */
    public function defaults(): array;

    /** @return array<string, string> */
    public function fields(): array;

    /** @return array<string, array<int, array{key: string, label: string, align?: string}>> */
    public function tables(): array;

    /**
     * @return array{pages: array<int, array{values: array<string, string>, tables: array<string, array{rows: array<int, array<string, string>>, foot?: array<int, array<string, string>>}>}>}
     */
    public function sample(): array;
}
