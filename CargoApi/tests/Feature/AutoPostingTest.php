<?php

declare(strict_types=1);

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records posting themselves to the journal, and keeping those postings true.
 *
 * The reconciliation — that the statements then say what the Finance screens
 * say — is `AutoPostingReconciliationTest`. These pin the mechanism under it:
 * one posting per record, nothing on a re-sync, void-and-repost on a change,
 * a withdrawal when the record stops counting, and silence where there are no
 * books to post to.
 */
beforeEach(function (): void {
    app(CompanyProvisioner::class)->provision($this->company);

    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'MAR1390', 'position' => 1]);

    $this->day = fn (array $figures = [], string $date = '2026-07-15') => LedgerEntry::create([
        'truck_id' => $this->truck->getKey(),
        'date' => $date,
        'route' => 'CDO – Iligan',
        'trip_income_cents' => 1_000_000,
        'fuel_cents' => 200_000,
        'driver_salary_cents' => 50_000,
        ...$figures,
    ]);

    $this->entriesFor = fn ($record) => JournalEntry::query()
        ->with('lines.account')
        ->where('source', JournalEntry::AUTO)
        ->where('source_type', $record->getMorphClass())
        ->where('source_id', $record->getKey())
        ->orderBy('source_revision')
        ->get();

    /** What an account stands at from posted entries, debit-positive. */
    $this->balance = fn (string $code) => (int) JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.status', JournalEntry::POSTED)
        ->where('journal_lines.account_id', Account::where('code', $code)->value('id'))
        ->sum(DB::raw('journal_lines.debit_cents - journal_lines.credit_cents'));
});

it('posts a day on the sheet, balanced, on the day\'s date', function (): void {
    $row = ($this->day)();

    $entries = ($this->entriesFor)($row);

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry->status)->toBe(JournalEntry::POSTED)
        ->and($entry->entry_date->toDateString())->toBe('2026-07-15')
        ->and($entry->category)->toBe(JournalCategory::Operations)
        ->and($entry->source_rule)->toBe('sheet')
        ->and($entry->source_revision)->toBe(1)
        ->and($entry->isBalanced())->toBeTrue()
        ->and(($this->balance)('4010'))->toBe(-1_000_000)
        ->and(($this->balance)('1140'))->toBe(1_000_000)
        ->and(($this->balance)('5010'))->toBe(200_000)
        ->and(($this->balance)('5020'))->toBe(50_000)
        ->and(($this->balance)('2110'))->toBe(-50_000)
        ->and(($this->balance)('1020'))->toBe(-200_000);
});

it('changes nothing when the same record is synced again', function (): void {
    $row = ($this->day)();

    $result = app(AutoPostingService::class)->sync($row);

    expect($result)->toMatchArray(['posted' => 0, 'voided' => 0, 'unchanged' => 1])
        ->and(($this->entriesFor)($row))->toHaveCount(1);

    // A save that changes nothing the books care about is no change either.
    $row->update(['remarks' => 'Tyre looked soft']);

    expect(($this->entriesFor)($row))->toHaveCount(1);
});

it('voids and reposts when the record is corrected', function (): void {
    $row = ($this->day)();

    $row->update(['fuel_cents' => 260_000]);

    [$old, $new] = ($this->entriesFor)($row)->all();

    expect($old->status)->toBe(JournalEntry::VOID)
        ->and($old->void_reason)->toStartWith('Superseded by a change to the daily sheet')
        ->and($new->status)->toBe(JournalEntry::POSTED)
        ->and($new->source_revision)->toBe(2)
        ->and(($this->balance)('5010'))->toBe(260_000);
});

it('follows an increment, which is how a delivery and a fill post onto the sheet', function (): void {
    $row = ($this->day)();

    $row->increment('trip_income_cents', 500_000);

    expect(($this->balance)('4010'))->toBe(-1_500_000)
        ->and(($this->entriesFor)($row)->where('status', JournalEntry::POSTED))->toHaveCount(1);
});

it('withdraws the posting when the record is deleted or stops counting', function (): void {
    $category = ExpenseCategory::where('key', 'toll-parking')->firstOrFail();

    $expense = Expense::create([
        'category_id' => $category->getKey(),
        'date' => '2026-07-20',
        'amount_cents' => 45_000,
        'status' => StatusValue::Active->value,
    ]);

    expect(($this->balance)('5070'))->toBe(45_000);

    $expense->delete();

    $entry = ($this->entriesFor)($expense)->sole();

    expect($entry->status)->toBe(JournalEntry::VOID)
        ->and($entry->void_reason)->toContain('no longer counts')
        ->and(($this->balance)('5070'))->toBe(0);

    // A pending line is owed, not spent: it posts nothing.
    $pending = Expense::create([
        'category_id' => $category->getKey(),
        'date' => '2026-07-20',
        'amount_cents' => 10_000,
        'status' => StatusValue::Pending->value,
    ]);

    expect(($this->entriesFor)($pending))->toHaveCount(0);
});

it('posts an expense to its category\'s account, and falls back to 5900', function (): void {
    $office = ExpenseCategory::where('key', 'office')->firstOrFail();
    $mine = ExpenseCategory::create(['key' => 'sundries', 'name' => 'Sundries']);

    expect($office->accountCode())->toBe('5210')
        ->and($mine->accountCode())->toBe('5900');

    Expense::create(['category_id' => $office->getKey(), 'date' => '2026-07-20', 'amount_cents' => 30_000, 'status' => 'active']);
    Expense::create(['category_id' => $mine->getKey(), 'date' => '2026-07-20', 'amount_cents' => 7_000, 'status' => 'active']);

    expect(($this->balance)('5210'))->toBe(30_000)
        ->and(($this->balance)('5900'))->toBe(7_000);

    // Pointing a category at another account moves its lines there.
    $mine->update(['account_code' => '5230']);

    expect(($this->balance)('5900'))->toBe(0)
        ->and(($this->balance)('5230'))->toBe(7_000);
});

it('takes the withholding off receivables at issue, so 1100 is what Billing says is owed', function (): void {
    $customer = Customer::create(['name' => 'Pryce Gases', 'contact' => '0917 000 0000']);

    $invoice = Invoice::create([
        'customer_id' => $customer->getKey(),
        'issued_at' => '2026-07-20',
        'due_at' => '2026-08-20',
        'amount_cents' => 1_120_000,
        'net_amount_cents' => 1_000_000,
        'vat_cents' => 120_000,
        'withholding_cents' => 20_000,
        'direction' => InvoiceDirection::Receivable->value,
        'status' => StatusValue::Pending->value,
    ]);

    expect(($this->balance)('1100'))->toBe($invoice->balanceCents())
        ->and(($this->balance)('1260'))->toBe(20_000)
        ->and(($this->balance)('2150'))->toBe(-120_000)
        // Raised by hand: its net is other income, on the day it was issued.
        ->and(($this->balance)('4090'))->toBe(-1_000_000);

    $invoice->update(['status' => StatusValue::Cancelled->value]);

    expect(($this->balance)('1100'))->toBe(0)
        ->and(($this->balance)('4090'))->toBe(0);
});

it('never touches an entry somebody wrote by hand', function (): void {
    $manual = app(JournalService::class)->create(JournalEntryData::fromArray([
        'entry_date' => '2026-07-15',
        'category' => JournalCategory::Operations->value,
        'memo' => 'Typed by the accountant',
        'status' => JournalEntry::POSTED,
        'lines' => [
            ['account_id' => Account::where('code', '1140')->value('id'), 'side' => 'debit', 'amount_cents' => 1_000],
            ['account_id' => Account::where('code', '4010')->value('id'), 'side' => 'credit', 'amount_cents' => 1_000],
        ],
    ]));

    $row = ($this->day)();
    $row->update(['fuel_cents' => 1]);
    $row->delete();

    expect($manual->refresh()->status)->toBe(JournalEntry::POSTED)
        ->and($manual->source)->toBe(JournalEntry::MANUAL);
});

it('does not ring the office\'s bell for each posting', function (): void {
    $before = NotificationItem::query()->count();

    $row = ($this->day)();
    $row->update(['fuel_cents' => 300_000]);

    expect(NotificationItem::query()->count())->toBe($before);
});

it('stays quiet for a company with no chart of accounts', function (): void {
    $bare = $this->makeCompany('No Books Yet');

    $this->asCompany($bare, function (): void {
        $truck = Truck::create(['label' => 'Truck 1', 'position' => 1]);

        LedgerEntry::create(['truck_id' => $truck->getKey(), 'date' => '2026-07-15', 'trip_income_cents' => 500_000]);

        expect(JournalEntry::query()->count())->toBe(0);
    });
});

it('skips a record, with a warning, when the chart is missing an account it needs', function (): void {
    Log::spy();

    Account::where('code', '5010')->firstOrFail()->delete();

    $row = ($this->day)();

    expect(($this->entriesFor)($row))->toHaveCount(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'missing an account'));
});

it('can be switched off', function (): void {
    config(['cargo.accounting.auto_post.enabled' => false]);

    $row = ($this->day)();

    expect(($this->entriesFor)($row))->toHaveCount(0);
});

it('leaves alone what is dated before the day it was told to start', function (): void {
    config(['cargo.accounting.auto_post.from' => '2026-08-01']);

    $july = ($this->day)([], '2026-07-15');
    $august = ($this->day)([], '2026-08-03');

    expect(($this->entriesFor)($july))->toHaveCount(0)
        ->and(($this->entriesFor)($august))->toHaveCount(1);
});

describe('the backfill', function (): void {
    beforeEach(function (): void {
        // History from before auto-posting existed.
        config(['cargo.accounting.auto_post.enabled' => false]);

        $this->rows = [($this->day)([], '2026-06-30'), ($this->day)([], '2026-07-15')];

        config(['cargo.accounting.auto_post.enabled' => true]);
    });

    it('posts the history once, however often it runs', function (): void {
        $this->artisan('cargo:accounting-backfill')->assertSuccessful();

        $after = JournalEntry::query()->count();

        $this->artisan('cargo:accounting-backfill')->assertSuccessful();

        expect($after)->toBe(2)
            ->and(JournalEntry::query()->count())->toBe($after)
            ->and(($this->balance)('4010'))->toBe(-2_000_000);
    });

    it('writes nothing on a dry run', function (): void {
        $this->artisan('cargo:accounting-backfill', ['--dry-run' => true])
            ->expectsOutputToContain('Would post 2')
            ->assertSuccessful();

        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('starts where it is told to, so the accountant\'s own months are not doubled', function (): void {
        $this->artisan('cargo:accounting-backfill', ['--from' => '2026-07-01'])->assertSuccessful();

        expect(($this->entriesFor)($this->rows[0]))->toHaveCount(0)
            ->and(($this->entriesFor)($this->rows[1]))->toHaveCount(1);
    });
});
