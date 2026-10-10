<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CreateUserCommand extends Command
{
    protected $signature = 'cargo:user
        {--company= : Company name, code or id}
        {--name= : Full name}
        {--email= : Email address}
        {--role= : administrator, dispatcher, accountant, driver or customer}
        {--licence= : Licence number (drivers only)}
        {--licence-expiry= : Licence expiry, YYYY-MM-DD (drivers only)}
        {--customer= : Customer name or id (customers only)}';

    protected $description = 'Create a Cargo Rush account';

    public function __construct(private readonly Tenant $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $company = $this->companyFor();

        if ($company === null) {
            return self::FAILURE;
        }

        return $this->tenant->use($company, fn (): int => $this->createAccount($company));
    }

    private function createAccount(Company $company): int
    {
        $name = $this->option('name') ?: text('Full name', required: true);
        $email = $this->option('email') ?: text('Email address', required: true);

        $role = $this->option('role') ?: select(
            'Role',
            array_combine(
                array_column(Role::cases(), 'value'),
                array_map(static fn (Role $r): string => $r->label(), Role::cases()),
            ),
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:160', 'unique:users,email'],
                'role' => ['required', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $secret = password('Password', required: true);

        $confirmation = password('Confirm password', required: true);

        if ($secret !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $strength = Validator::make(
            ['password' => $secret],
            ['password' => ['required', Password::min(8)->letters()->numbers()]],
        );

        if ($strength->fails()) {
            foreach ($strength->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $roleEnum = Role::from($role);

        $customerId = null;

        if ($roleEnum === Role::Customer) {
            $customerId = $this->customerFor();

            if ($customerId === null) {
                return self::FAILURE;
            }
        }

        $user = DB::transaction(function () use ($name, $email, $secret, $roleEnum, $customerId): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($secret),
                'role' => $roleEnum->value,
                'customer_id' => $customerId,
            ]);

            if ($roleEnum === Role::Driver) {
                $this->driverFor($user);
            }

            return $user;
        });

        $this->info("Created {$user->name} <{$user->email}> as {$roleEnum->label()} at {$company->name}.");

        return self::SUCCESS;
    }

    private function companyFor(): ?Company
    {
        $companies = Company::query()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->error('No companies on this install. Register one first at POST /api/v1/register.');

            return null;
        }

        $named = $this->option('company');

        if ($named !== null) {
            $match = $companies->first(
                static fn (Company $c): bool => $c->id === $named || $c->code === $named || $c->name === $named,
            );

            if ($match === null) {
                $this->error("No company matching \"{$named}\". Known: ".$companies->pluck('code')->join(', '));

                return null;
            }

            return $match;
        }

        if ($companies->count() === 1) {
            return $companies->first();
        }

        $choice = select(
            'Company',
            $companies->mapWithKeys(
                static fn (Company $c): array => [$c->id => "{$c->name} ({$c->code})"],
            )->all(),
        );

        return $companies->firstWhere('id', $choice);
    }

    private function driverFor(User $user): void
    {
        $licence = $this->option('licence')
            ?: text('Licence number', required: true);

        $expiry = $this->option('licence-expiry')
            ?: text('Licence expiry (YYYY-MM-DD)', required: true);

        Driver::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'licence_no' => $licence,
            'licence_expiry' => $expiry,
            'status' => StatusValue::Available->value,
        ]);
    }

    private function customerFor(): ?string
    {
        $named = $this->option('customer');
        $onFile = Customer::orderBy('name')->pluck('name', 'id')->all();

        if ($named !== null) {
            $match = Customer::where('id', $named)->orWhere('name', $named)->first();

            if ($match === null && $onFile !== []) {
                $this->error("No customer called \"{$named}\". Add them in Customer Management first.");

                return null;
            }

            return $match?->id ?? $this->createCustomer($named);
        }

        if ($onFile === []) {
            return $this->createCustomer(text('Customer name (none on file yet)', required: true));
        }

        $addNew = '__new__';
        $choice = select('Customer', [...$onFile, $addNew => '+ Add a new customer']);

        return $choice === $addNew
            ? $this->createCustomer(text('Customer name', required: true))
            : (string) $choice;
    }

    private function createCustomer(string $name): string
    {
        return Customer::create([
            'name' => $name,
            'contact' => text("Contact for {$name}", required: true),
            'status' => StatusValue::Active->value,
        ])->id;
    }
}
