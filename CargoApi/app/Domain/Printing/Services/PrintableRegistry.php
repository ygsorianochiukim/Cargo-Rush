<?php

declare(strict_types=1);

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Models\PrintTemplate;
use App\Domain\Printing\Printables\AgingReportPrintable;
use App\Domain\Printing\Printables\AssetReportPrintable;
use App\Domain\Printing\Printables\BalanceSheetPrintable;
use App\Domain\Printing\Printables\IncomeStatementPrintable;
use App\Domain\Printing\Printables\InvoicePrintable;
use App\Domain\Printing\Printables\PayrollRegisterPrintable;
use App\Domain\Printing\Printables\PayslipPrintable;
use App\Domain\Printing\Printables\StatementOfAccountPrintable;
use App\Domain\Printing\Printables\TripTicketChecklistPrintable;
use App\Domain\Printing\Printables\TripTicketPrintable;
use App\Domain\Printing\Support\Layout;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PrintableRegistry
{
    /** @var array<int, class-string<Printable>> */
    public const PRINTABLES = [
        InvoicePrintable::class,
        StatementOfAccountPrintable::class,
        AgingReportPrintable::class,
        IncomeStatementPrintable::class,
        BalanceSheetPrintable::class,
        PayrollRegisterPrintable::class,
        PayslipPrintable::class,
        AssetReportPrintable::class,
        TripTicketPrintable::class,
        TripTicketChecklistPrintable::class,
    ];

    /** @return array<int, Printable> */
    public function all(): array
    {
        return array_map(static fn (string $class) => app($class), self::PRINTABLES);
    }

    public function find(string $key): Printable
    {
        foreach ($this->all() as $printable) {
            if ($printable->key() === $key) {
                return $printable;
            }
        }

        throw new NotFoundHttpException('There is no printable called '.$key.'.');
    }

    /** @return array<string, mixed> */
    public function layout(Printable $printable): array
    {
        $saved = PrintTemplate::query()->where('key', $printable->key())->value('layout');

        return is_array($saved) ? Layout::clean($saved, $printable->defaults()) : $printable->defaults();
    }

    public function customised(Printable $printable): bool
    {
        return PrintTemplate::query()->where('key', $printable->key())->exists();
    }

    /** @param array<string, mixed> $layout */
    public function save(Printable $printable, array $layout): void
    {
        PrintTemplate::query()->updateOrCreate(
            ['key' => $printable->key()],
            ['layout' => Layout::clean($layout, $printable->defaults())],
        );
    }

    public function reset(Printable $printable): void
    {
        PrintTemplate::query()->where('key', $printable->key())->delete();
    }
}
