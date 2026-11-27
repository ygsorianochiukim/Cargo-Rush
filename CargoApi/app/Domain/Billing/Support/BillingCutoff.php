<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Enums\InvoiceDirection;
use Carbon\CarbonImmutable;

class BillingCutoff
{
    /** @return array{start: string, end: string, billing_from: string, billing_to: string, payment_target: string}|null */
    public static function forInvoice(Invoice $invoice): ?array
    {
        if ($invoice->direction !== InvoiceDirection::Receivable || $invoice->trip === null) {
            return null;
        }

        $date = $invoice->trip->deliveryLog?->delivered_at ?? $invoice->trip->scheduled_at;
        if ($date === null) {
            return null;
        }

        $day = CarbonImmutable::instance($date)->setTimezone('Asia/Manila')->startOfDay();
        $month = $day->startOfMonth();

        [$start, $end] = match (true) {
            $day->day <= 5 => [$month->subMonth()->day(26), $month->day(5)],
            $day->day <= 15 => [$month->day(6), $month->day(15)],
            $day->day <= 25 => [$month->day(16), $month->day(25)],
            default => [$month->day(26), $month->addMonth()->day(5)],
        };

        $billingTo = $end->addDays(3);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'billing_from' => $end->addDays(2)->toDateString(),
            'billing_to' => $billingTo->toDateString(),
            'payment_target' => $billingTo->addDays(10)->toDateString(),
        ];
    }
}
