<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TruckCategorySeeder;

/**
 * Gives a newly registered company the configuration it cannot open without.
 *
 * A company with no roles is one where nobody can be given access to anything;
 * with no positions, the HR form has nothing to hire somebody into; with no
 * expense categories, the expenses module cannot record its first peso; with no
 * chart of accounts, the general journal has nothing to post to. None of those
 * are data — they are the shape of the product, and every install needs them
 * before it needs anything else. Registration is the moment to lay them down,
 * because it is the only moment before somebody tries to use the system.
 *
 * Two of the seeders it runs are the **platform's** rather than the company's —
 * the permission vocabulary and the navigation. See `platform()` for why a
 * public sign-up cannot assume somebody already ran `db:seed`, and what a
 * company looks like when it does.
 *
 * The definitions are **not** repeated here. They live in the four seeders
 * that already held them, and this runs those seeders inside the new company's
 * tenant context. That is the whole trick: `RoleSeeder` was written against a
 * single-company install and needs no change to serve a hundred, because every
 * `Role::updateOrCreate` it runs is now scoped and stamped by the model layer.
 * One list of roles, one place to edit it, and a company registered next year
 * gets whatever that list says then.
 *
 * Idempotent, for the same reason the seeders are: all three match on a `key`
 * and leave alone anything the office has since renamed, reordered or switched
 * off. Re-provisioning an existing company tops it up rather than resetting it.
 */
class CompanyProvisioner
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Lay down the starting roles, positions and expense categories.
     *
     * The seeders are constructed directly rather than resolved from the
     * container: they take no dependencies, and one built here has no
     * `$this->command`, so it prints nothing. A registration over HTTP writing
     * seeder output to the response would be noise at best.
     */
    public function provision(Company $company): void
    {
        $this->platform();

        $this->tenant->use($company, function (): void {
            // Roles first — a position carries a default role and cannot point
            // at one that does not exist yet.
            (new RoleSeeder)->run();
            (new PositionSeeder)->run();
            (new ExpenseCategorySeeder)->run();
            // The kinds of unit the firm runs, so a freezer job can be priced
            // on day one rather than after somebody invents the vocabulary.
            (new TruckCategorySeeder)->run();
            // The chart of accounts, for the same reason as the categories: a
            // company with no chart cannot write a single journal entry, so an
            // empty `accounts` table is a module that cannot be opened rather
            // than one waiting for its first record.
            (new ChartOfAccountsSeeder)->run();
        });
    }

    /**
     * The platform's own configuration: the permission vocabulary, and the
     * navigation.
     *
     * Neither belongs to a company, and `DatabaseSeeder` lays both down on a
     * fresh install — so on a properly seeded database these two calls are a
     * few dozen upserts that change nothing.
     *
     * They are here because registration is **public and self-service**, and
     * cannot assume anybody ran `db:seed` first. On a database that was only
     * migrated, a company registered without them comes out unusable in a way
     * that looks like a broken registration rather than a missing seed:
     *
     *   `RoleSeeder` ticks each role against rows in `permissions`. With that
     *   table empty every role is created holding nothing — so a customer
     *   login gets a 403 from its own portal, and only the administrator works,
     *   by way of the `*` fallback on the enum.
     *
     *   `nav_items` drives the sidebar on the web and the tab bar on the
     *   handset. Empty, the shell renders no menu at all: you register, land on
     *   the dashboard, and find nothing to click.
     *
     * Both seeders are `updateOrCreate` on a key and touch nothing an office
     * has since renamed or re-ticked, so running them per registration is safe
     * as well as cheap — and registration is throttled and rare.
     */
    private function platform(): void
    {
        // Across companies: these two tables have no `company_id`, and a tenant
        // in force would be irrelevant to them either way.
        $this->tenant->across(function (): void {
            // Permissions before navigation: the nav is filtered by them, and
            // roles are ticked against them.
            (new PermissionSeeder)->run();
            (new NavigationSeeder)->run();
        });
    }
}
