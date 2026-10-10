<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\NavItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffAccountService
{
    public function __construct(private readonly UserRepository $users) {}

    /** @return array{email: string, password: string} */
    public function createFor(Employee $employee, string $email, string $role, ?string $password = null): array
    {
        abort_if(
            $employee->user_id !== null,
            422,
            'This employee already has an account.',
        );

        abort_if(
            $this->users->findByEmail($email) !== null,
            422,
            'That address already belongs to another account.',
        );

        $password ??= (string) config('cargo.hr.default_password');

        return DB::transaction(function () use ($employee, $email, $role, $password): array {
            $user = User::create([
                'name' => $employee->fullName(),
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
            ]);

            $employee->forceFill(['user_id' => $user->id])->save();

            if ($employee->driver_id !== null && $employee->driver?->user_id === null) {
                $employee->driver->forceFill(['user_id' => $user->id])->save();
            }

            return ['email' => $email, 'password' => $password];
        });
    }

    public function assignRole(User $user, string $role): User
    {
        if ($user->role === $role) {
            return $user;
        }

        return DB::transaction(function () use ($user, $role): User {
            $user->forceFill(['role' => $role])->save();

            DB::table('user_modules')->where('user_id', $user->id)->delete();

            return $user->refresh();
        });
    }

    /**
     * @param  string[]  $navKeys
     * @return array{assigned: string[], rejected: string[]}
     */
    public function assignModules(User $user, array $navKeys): array
    {
        $permitted = $this->permittedKeys($user);

        $requested = array_values(array_unique(array_filter($navKeys)));
        $assigned = array_values(array_intersect($requested, $permitted));
        $rejected = array_values(array_diff($requested, $permitted));

        DB::transaction(function () use ($user, $assigned): void {
            DB::table('user_modules')->where('user_id', $user->id)->delete();

            if ($assigned === []) {
                return;
            }

            DB::table('user_modules')->insert(array_map(
                static fn (string $key): array => [
                    'user_id' => $user->id,
                    'nav_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $assigned,
            ));
        });

        return ['assigned' => $assigned, 'rejected' => $rejected];
    }

    /** @return array<string, mixed> */
    public function moduleState(User $user): array
    {
        $permitted = $this->permittedKeys($user);
        $assigned = $this->assignedKeys($user);

        return [
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'available' => NavItem::query()
                ->whereIn('key', $permitted)
                ->orderBy('order')
                ->get(['key', 'label', 'group', 'icon'])
                ->all(),
            'assigned' => $assigned,
            'customised' => $assigned !== [],
        ];
    }

    /** @return string[] */
    private function permittedKeys(User $user): array
    {
        $permissions = $user->permissions();

        return NavItem::query()
            ->where('web', true)
            ->get()
            ->filter(fn (NavItem $item): bool => $item->permission === null
                || in_array('*', $permissions, true)
                || in_array($item->permission, $permissions, true))
            ->pluck('key')
            ->all();
    }

    /** @return string[] */
    private function assignedKeys(User $user): array
    {
        return DB::table('user_modules')
            ->where('user_id', $user->id)
            ->pluck('nav_key')
            ->all();
    }
}
