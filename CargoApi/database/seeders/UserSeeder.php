<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /** @var array<int, array{string, string, Role}> */
    private const ACCOUNTS = [
        ['admin@cargorush.ph', 'Juan Dela Cruz', Role::Administrator],
        ['accounts@cargorush.ph', 'Elena Bautista', Role::Accountant],
        ['marco@cargorush.ph', 'Marco Reyes', Role::Driver],
    ];

    public function __construct(private readonly Tenant $tenant) {}

    public function run(): void
    {
        $company = Company::query()->oldest()->first();

        if ($company === null) {
            $this->command?->warn('No company to seed accounts into. Register one at POST /api/v1/register.');

            return;
        }

        $password = (string) env('SEED_PASSWORD', 'password');

        $this->tenant->use($company, function () use ($password): void {
            foreach (self::ACCOUNTS as [$email, $name, $role]) {
                $user = User::updateOrCreate(['email' => $email], [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => $role->value,
                ]);

                if ($role === Role::Driver) {
                    $this->driverFor($user);
                }
            }
        });

        $this->command?->warn(sprintf(
            'Seeded accounts into %s and use the SEED_PASSWORD value. Change these passwords before going live.',
            $company->name,
        ));
    }

    private function driverFor(User $user): void
    {
        Driver::updateOrCreate(['user_id' => $user->id], [
            'name' => $user->name,
            'licence_no' => 'TO-BE-SET',
            'licence_expiry' => now()->addYear()->toDateString(),
            'status' => StatusValue::Available->value,
        ]);
    }
}
