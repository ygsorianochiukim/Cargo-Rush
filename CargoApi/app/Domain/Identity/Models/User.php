<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\Role as RoleRecord;
use App\Domain\Shared\Enums\Role;
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
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'customer_id', 'avatar_url'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
     * The customer this login acts for.
     *
     * The mirror of `driver()`: the account is one record and the business
     * history is another. A customer's deliveries and invoices belong to the
     * firm, not to whoever signed in, so two people at the same firm see the
     * same list — and an account whose customer is null is a customer login
     * with nothing to show, which is why every portal endpoint says so
     * plainly rather than returning an empty page.
     */
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
