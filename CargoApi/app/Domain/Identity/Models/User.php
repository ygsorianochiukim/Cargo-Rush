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
use App\Domain\Trucker\Models\TruckerDriver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

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
            'chooses_carrier' => 'boolean',
        ];
    }

    protected function setRoleAttribute(mixed $value): void
    {
        $this->attributes['role'] = $value instanceof \BackedEnum
            ? $value->value
            : (string) $value;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    public function roleRecord(): BelongsTo
    {
        return $this->belongsTo(RoleRecord::class, 'role', 'key');
    }

    public function systemRole(): ?Role
    {
        return Role::tryFrom((string) $this->role);
    }

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

    public function trucker(): HasOne
    {
        return $this->hasOne(Trucker::class);
    }

    public function truckerDriver(): HasOne
    {
        return $this->hasOne(TruckerDriver::class);
    }

    public function choosesCarrier(): bool
    {
        return (bool) $this->chooses_carrier;
    }

    public function awaitingCarrier(): bool
    {
        return $this->company_id === null
            && $this->choosesCarrier()
            && $this->role === Role::Customer->value;
    }

    protected function mayHaveNoCompany(): bool
    {
        return $this->awaitingCarrier();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return string[] */
    public function permissions(): array
    {
        $record = $this->roleRecord;

        if ($record !== null) {
            return $record->permissionKeys();
        }

        return $this->systemRole()?->permissions() ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        $held = $this->permissions();

        return in_array('*', $held, true) || in_array($permission, $held, true);
    }
}
