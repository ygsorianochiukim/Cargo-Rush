<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\Role as RoleRecord;
use App\Domain\Identity\Notifications\ResetPasswordLink;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trucker\Models\Trucker;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Whoever is holding one of the two clients.
 *
 * A back-office user is just this record; a driver is this record plus a
 * `drivers` row, because the operational history belongs to the driver, not
 * to the login.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role', 'customer_id', 'chooses_carrier', 'avatar_url',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Which of the two kinds of customer account this is — see the
            // migration that added the column. False for every account an
            // office created, which is every account that predates it.
            'chooses_carrier' => 'boolean',
            // `role` is deliberately NOT cast to the enum any more. It is a key
            // into the `roles` table, and an install that adds a Treasury
            // Officer would throw on every read if the cast were still here.
        ];
    }

    /**
     * Accept the enum as well as a string.
     *
     * `$user->role = Role::Driver` reads well and is what the seeders and most
     * of the tests do. Without this the enum object would reach the column and
     * fail on write, for no gain — the two spellings mean the same thing.
     */
    protected function setRoleAttribute(mixed $value): void
    {
        $this->attributes['role'] = $value instanceof \BackedEnum
            ? $value->value
            : (string) $value;
    }

    /**
     * Send the reset link to the SPA, not to a web route.
     *
     * The framework's default builds its URL from `route('password.reset')`,
     * and this application has no web pages to hang such a route on. Overriding
     * here rather than rebinding the notification globally keeps the decision
     * next to the model that owns the address it is sent to.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    /**
     * The editable record behind this account's role.
     *
     * Matched on the key rather than an id, so no account had to be migrated
     * when roles became rows — and an account whose role row has been deleted
     * still says what it was, rather than becoming null.
     */
    public function roleRecord(): BelongsTo
    {
        return $this->belongsTo(RoleRecord::class, 'role', 'key');
    }

    /** One of the five built-in roles, or null for one the office added. */
    public function systemRole(): ?Role
    {
        return Role::tryFrom((string) $this->role);
    }

    /** What to call this role on screen. */
    public function roleLabel(): string
    {
        return $this->roleRecord?->name
            ?? $this->systemRole()?->label()
            ?? Str::headline((string) $this->role);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    /**
     * The partner record behind this login, for an owner-operator.
     *
     * The third of the three, and the same pattern as the other two: the
     * account is one record and the business history is another. A trucker's
     * runs, their trucks and their wallet belong to the `truckers` row, so a
     * partner who is issued a new login keeps everything they have earned.
     */
    public function trucker(): HasOne
    {
        return $this->hasOne(Trucker::class);
    }

    /**
     * The customer this login acts for.
     *
     * The mirror of `driver()`: the account is one record and the business
     * history is another. A customer's deliveries and invoices belong to the
     * firm, not to whoever signed in, so two people at the same firm see the
     * same list — and an account whose customer is null is a customer login
     * with nothing to show, which is why every portal endpoint says so
     * plainly rather than returning an empty page.
     */
    /**
     * May this account shop around?
     *
     * True only for a shipper who signed themselves up on the platform. An
     * account the office created is that haulier's customer and is shown no
     * other haulier — see the migration for why that is the right default and
     * not a limitation.
     */
    public function choosesCarrier(): bool
    {
        return (bool) $this->chooses_carrier;
    }

    /**
     * Has this shipper signed up but not yet chosen anybody to carry a load?
     *
     * The one account in the system that belongs to no company, and it is a
     * waiting room rather than a place to live: registering asks for a name, a
     * number and a password and nothing else, so between signing up and filing
     * a first request there is genuinely no haulier this login is a customer
     * of. `ShipperAccounts::open()` ends it — the first request picks a carrier,
     * that carrier's books get an ordinary `customers` row, and the company and
     * the customer are written back onto this row.
     *
     * What it is *not* is an account with the run of the platform. The company
     * in force for a request like this is `Tenant::NOBODY`, so every scoped read
     * answers empty and every scoped write is refused. The two things it can do
     * are read the carrier directory and file the request that ends this state.
     *
     * A company-less login that is *not* a self-registered shipper is a broken
     * row rather than a waiting one — an account somebody detached, or one whose
     * company was deleted — and is turned away at the door, as it always was.
     */
    public function awaitingCarrier(): bool
    {
        return $this->company_id === null
            && $this->choosesCarrier()
            && $this->role === Role::Customer->value;
    }

    /**
     * The one exception to "every row belongs to a company".
     *
     * Narrow on purpose, and checked against the row being written rather than
     * against the account doing the writing: only a self-registered customer
     * login may be created without one. Every other user — a driver, a
     * dispatcher, an administrator, a customer the office added — still throws,
     * because there is no honest reason for one of those to exist outside the
     * company it works for.
     */
    protected function mayHaveNoCompany(): bool
    {
        return $this->awaitingCarrier();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The permission list `GET /api/v1/me` returns, the navigation query
     * filters on, and the route middleware checks.
     *
     * Read from the `roles` table so the office can change what a role reaches
     * without a deployment. The enum is the fallback and stays meaningful:
     * it is what a fresh install seeds from, and it is what answers if the
     * roles table has not been seeded yet — an account that suddenly held no
     * permissions at all would lock somebody out of their own system.
     *
     * @return string[]
     */
    public function permissions(): array
    {
        $record = $this->roleRecord;

        if ($record !== null) {
            return $record->permissionKeys();
        }

        return $this->systemRole()?->permissions() ?? [];
    }

    /**
     * Does this account hold a permission?
     *
     * Deliberately not an override of `can()`: the framework's signature is
     * wider than this check, and narrowing it breaks every gate and policy
     * that goes through the same method. `DomainServiceProvider` wires this
     * into the Gate instead, so `$user->can('trips.view')` works too.
     */
    public function hasPermission(string $permission): bool
    {
        $held = $this->permissions();

        return in_array('*', $held, true) || in_array($permission, $held, true);
    }
}
