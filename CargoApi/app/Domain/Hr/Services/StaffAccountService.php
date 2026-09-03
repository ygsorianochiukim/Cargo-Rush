<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\NavItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use App\Domain\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The login behind an employee, the role it carries, and the modules it sees.
 *
 * The office end of `cargo:user`, and the staff mirror of
 * `CustomerAccountService`: somebody added to the roster gets an account
 * without a developer running an artisan command for every new hire.
 *
 * The part worth reading twice is `assignModules()`. Nav rows are links, and
 * access is decided by the permission on each endpoint. Module assignment can
 * therefore only ever *narrow* what a role already allows — granting a nav row
 * whose permission the role does not hold would produce a sidebar item that
 * 403s on click, which is the appearance of access without any, and the worst
 * of both outcomes for whoever is holding the app.
 */
class StaffAccountService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * Create the login for an employee.
     *
     * Returns the credentials to hand over, once. Refuses rather than takes
     * over an address that already belongs to somebody — attaching a second
     * employee to an existing account is worse than an employee with no login,
     * because every audit trail in the system would then name the wrong person.
     *
     * @return array{email: string, password: string}
     */
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

            // The operational record gets the login too, where there is one.
            // A driver whose `drivers.user_id` is null cannot be sent work by
            // the handset, and having registered them as an employee with a
            // driver account is exactly when that link should exist.
            if ($employee->driver_id !== null && $employee->driver?->user_id === null) {
                $employee->driver->forceFill(['user_id' => $user->id])->save();
            }

            return ['email' => $email, 'password' => $password];
        });
    }

    /**
     * Change what an account is allowed to do.
     *
     * Module assignments are cleared on a role change, deliberately. They are
     * expressed as a subset of what the old role could see, and a dispatcher
     * promoted to accountant would otherwise keep a sidebar cut to a set of
     * modules the new role has nothing to do with — and it would look like the
     * promotion had not worked.
     */
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
     * Choose which of the modules this account's role allows it should see.
     *
     * An empty list clears the assignment, which restores the default: the
     * whole of what the role allows. That is the state every existing account
     * is in, and it has to stay reachable — otherwise switching one employee to
     * a custom menu would be a one-way door.
     *
     * @param  string[]  $navKeys
     * @return array{assigned: string[], rejected: string[]}
     */
    public function assignModules(User $user, array $navKeys): array
    {
        $permitted = $this->permittedKeys($user);

        $requested = array_values(array_unique(array_filter($navKeys)));
        $assigned = array_values(array_intersect($requested, $permitted));
        // Named back to the caller rather than silently dropped. A module that
        // the role cannot open is a real answer to "why can they not see it",
        // and the UI says so instead of the checkbox quietly refusing to stick.
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

    /**
     * What this account can currently see, and what it could.
     *
     * @return array<string, mixed>
     */
    public function moduleState(User $user): array
    {
        $permitted = $this->permittedKeys($user);
        $assigned = $this->assignedKeys($user);

        return [
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            // Every module the role allows, whether or not it is switched on.
            'available' => NavItem::query()
                ->whereIn('key', $permitted)
                ->orderBy('order')
                ->get(['key', 'label', 'group', 'icon'])
                ->all(),
            'assigned' => $assigned,
            // No rows means the default, which is everything the role allows.
            // A client needs to tell that apart from "assigned nothing", or it
            // will render every box unticked for an account that sees the lot.
            'customised' => $assigned !== [],
        ];
    }

    /**
     * The nav keys this account's role permits.
     *
     * @return string[]
     */
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
