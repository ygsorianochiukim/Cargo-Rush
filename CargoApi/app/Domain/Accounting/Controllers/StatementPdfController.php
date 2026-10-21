<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\StatementOfAccountService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StatementPdfController extends ApiController
{
    public function income(Request $request, FinancialStatementService $statements): Response
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');

        $statement = $statements->incomeStatement($from, $to);
        $to = $statement['range']['to'] ?? now()->toDateString();

        return $this->render('income', ['statement' => $statement], sprintf(
            'income-statement-%s-to-%s.pdf',
            $statement['range']['from'] ?? 'start',
            $to,
        ));
    }

    public function balanceSheet(Request $request, FinancialStatementService $statements): Response
    {
        $sheet = $statements->balanceSheet($this->date($request, 'as_of'));

        return $this->render('sheet', ['sheet' => $sheet], "balance-sheet-{$sheet['as_of']}.pdf");
    }

    public function aging(Request $request, BillingService $billing): Response
    {
        $direction = $this->direction($request);

        return $this->render('aging', [
            'report' => $billing->aging($direction),
            'direction' => $direction,
        ], "aging-{$direction->value}-".now()->toDateString().'.pdf');
    }

    public function account(Request $request, Customer $customer, StatementOfAccountService $accounts): Response
    {
        $statement = $accounts->forCustomer(
            $customer,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
            $this->direction($request),
        );

        return $this->render('account', ['statement' => $statement], sprintf(
            'statement-of-account-%s-%s.pdf',
            Str::slug((string) $customer->name),
            $statement['range']['to'] ?? now()->toDateString(),
        ));
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView('pdf.statements', [
            'view' => $view,
            'company' => app(Tenant::class)->company()?->name ?? 'Cargo Rush',
            'logo' => app(LogoStore::class)->dataUri(app(Tenant::class)->company()?->logo_path),
            'peso' => static fn (?int $cents): string => ($cents < 0 ? '-' : '').'PHP '.number_format(abs((int) $cents) / 100, 2),
            'date' => static fn (?string $value): string => $value === null ? '—' : Carbon::parse($value)->format('M j, Y'),
            ...$data,
        ])->setPaper('a4')->download($filename);
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
