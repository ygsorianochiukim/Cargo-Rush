<?php

declare(strict_types=1);

namespace App\Domain\Finance\Resources;

use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Support\RateBook;
use Illuminate\Http\Request;

/** @mixin Expense */
class ExpenseResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'category_key' => $this->category?->key,
            'category_icon' => $this->category?->icon,
            'truck_id' => $this->truck_id,
            'truck_label' => $this->truck?->label,

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'payment_source_id' => $this->payment_source_id,
            'payment_source_label' => $this->paymentSource?->label(),
            'payment_source_kind' => $this->paymentSource?->kind->value,
            'pay_schedule' => ($this->pay_schedule ?? PaySchedule::OnReceipt)->value,
            'pay_schedule_label' => ($this->pay_schedule ?? PaySchedule::OnReceipt)->label(),
            'trip_id' => $this->trip_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'ledger_entry_id' => $this->ledger_entry_id,
            'date' => $this->date?->toDateString(),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'is_vatable' => (bool) $this->is_vatable,
            'vat_cents' => $this->vatCents(app(RateBook::class)->vatRateBp()),
            'payee' => $this->payee,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
