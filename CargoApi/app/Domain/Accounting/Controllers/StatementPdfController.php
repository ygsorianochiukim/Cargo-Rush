<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\StatementOfAccountService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Printing\Printables\AgingReportPrintable;
use App\Domain\Printing\Printables\BalanceSheetPrintable;
use App\Domain\Printing\Printables\IncomeStatementPrintable;
use App\Domain\Printing\Printables\StatementOfAccountPrintable;
use App\Domain\Printing\Services\PrintRenderer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StatementPdfController extends ApiController
{
    public function __construct(private readonly PrintRenderer $renderer) {}

    public function income(Request $request, FinancialStatementService $statements, IncomeStatementPrintable $printable): Response
    {
        $statement = $statements->incomeStatement($this->date($request, 'from'), $this->date($request, 'to'));

        return $this->renderer->download($printable, $printable->data($statement), sprintf(
            'income-statement-%s-to-%s.pdf',
            $statement['range']['from'] ?? 'start',
            $statement['range']['to'] ?? now()->toDateString(),
        ));
    }

    public function balanceSheet(Request $request, FinancialStatementService $statements, BalanceSheetPrintable $printable): Response
    {
        $sheet = $statements->balanceSheet($this->date($request, 'as_of'));

        return $this->renderer->download($printable, $printable->data($sheet), "balance-sheet-{$sheet['as_of']}.pdf");
    }

    public function aging(Request $request, BillingService $billing, AgingReportPrintable $printable): Response
    {
        $direction = $this->direction($request);

        return $this->renderer->download(
            $printable,
            $printable->data($billing->aging($direction), $direction),
            "aging-{$direction->value}-".now()->toDateString().'.pdf',
        );
    }

    public function account(Request $request, Customer $customer, StatementOfAccountService $accounts, StatementOfAccountPrintable $printable): Response
    {
        $statement = $accounts->forCustomer(
            $customer,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
            $this->direction($request),
        );

        return $this->renderer->download($printable, $printable->data($statement), sprintf(
            'statement-of-account-%s-%s.pdf',
            Str::slug((string) $customer->name),
            $statement['range']['to'] ?? now()->toDateString(),
        ));
    }

    private function direction(Request $request): InvoiceDirection
    {
        return $request->string('direction')->toString() === InvoiceDirection::Payable->value
            ? InvoiceDirection::Payable
            : InvoiceDirection::Receivable;
    }

    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
