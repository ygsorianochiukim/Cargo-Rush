<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody the fleet buys from.
 *
 * The mirror of `Customer`, and a separate table for the same reason the two
 * directions of an invoice are one table and two `direction` values would not
 * do here: a customer has trips, a portal login, a VAT treatment and a rating,
 * and a supplier has none of those. What they share is a name and a phone
 * number, which is not enough to make them one thing.
 *
 * ## Why this exists at all
 *
 * `expenses.payee` and `invoices.payee` were free text. The same garage came
 * out as "Davao Lubes", "Davao lubes & parts" and "DAVAO LUBES" over a year,
 * and "what do we spend there" could not be asked — not answered badly, asked.
 * A record with an id fixes the spelling problem and makes the question
 * possible.
 *
 * The `payee` columns stay beside it. They hold what was already typed, and a
 * one-off — a tyre bought in Tagum on a Sunday from somebody the office will
 * never see again — does not deserve a record of its own.
 *
 * ## The three places money reaches one
 *
 * An **expense** (a sack of rags, the crew's lunch), a **maintenance job**
 * (what the service cost) and a **payable invoice** (a bill on terms). All
 * three point here, which is what makes a supplier's total mean anything.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'contact', 'address', 'supplies', 'note', 'status'];

    protected function casts(): array
    {
        return ['status' => StatusValue::class];
    }

    /** Spend filed as an expense — consumables, meals, tolls. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** Services done on the fleet's units. */
    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }

    /**
     * Bills raised against the fleet.
     *
     * Constrained to payables on the relation rather than left to each caller:
     * a receivable naming a supplier would be this firm selling *them*
     * something, which is a customer relationship wearing the wrong id.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('direction', 'payable');
    }

    /** The ones the office still buys from, for a picker. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
