<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One day of trip income and expenses for one truck — a row of the workbook.
 *
 * Total expenses and net income are NOT columns. They are derived here from
 * the five expense columns, exactly as DESIGN.md section 5.1 requires, so the
 * stored row can never disagree with the figure a page prints.
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'truck_id', 'trip_id', 'customer_id', 'date', 'trip_income_cents', 'fuel_cents',
        'driver_salary_cents', 'helper_salary_cents', 'maintenance_cents',
        'allowance_cents', 'route', 'remarks', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'trip_income_cents' => 'integer',
            'fuel_cents' => 'integer',
            'driver_salary_cents' => 'integer',
            'helper_salary_cents' => 'integer',
            'maintenance_cents' => 'integer',
            'allowance_cents' => 'integer',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    /**
     * The trip whose delivery opened this row, when one did.
     *
     * Null for a row the office entered by hand, and it names only the first
     * trip of the day for that truck — the row itself covers the whole day,
     * however many runs went into it.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Whose work the day's takings were, when it was one customer's.
     *
     * Null is ordinary: the row is per truck per day, and a day can be the
     * company's own freight or a mix of several customers. Where a delivered
     * trip opened the row it carries that trip's customer, which is what puts
     * the money on their history without anybody typing it.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** fuel + driver + helper + maintenance + allowance. */
    public function totalExpensesCents(): int
    {
        return $this->fuel_cents
            + $this->driver_salary_cents
            + $this->helper_salary_cents
            + $this->maintenance_cents
            + $this->allowance_cents;
    }

    /** trip income - total expenses. Negative is a real, first-class loss. */
    public function netIncomeCents(): int
    {
        return $this->trip_income_cents - $this->totalExpensesCents();
    }
}
