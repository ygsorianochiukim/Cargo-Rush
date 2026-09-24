<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers the office argues about, moved out of the environment file.
 *
 * Until now the tariff, the payment terms and the tax rates were
 * `config/cargo.php` — which is to say they were environment variables, one
 * set of them for every haulier on the install, and changing one was a
 * deployment. That was defensible while they were "the statute's figures" and
 * stopped being defensible the moment two companies shared an install: a firm
 * quoting ₱35/km and a firm quoting ₱45/km cannot both be described by one
 * `TARIFF_PER_KM_CENTS`, and neither of them can correct a rate on a Tuesday
 * afternoon.
 *
 * So each becomes a column here, **nullable**, and null keeps meaning exactly
 * what it meant before: use the install default from `config/cargo.php`. A
 * company that never opens the settings screen is unchanged in every respect,
 * which is why none of these is backfilled. `RateBook` is the one place that
 * resolves a column against its fallback; nothing else should read either.
 *
 * ## The partner commission goes the other way
 *
 * `truckers.commission_bp` — a rate negotiated with one owner-operator — is
 * **dropped**. It was only ever settable from one number field on the partner's
 * detail screen, and that field is gone: a commission is a commercial term the
 * office sets once for everybody it hauls with, not something to be re-typed
 * per partner on a screen also holding an approve button and a wallet. What
 * applies now is `companies.trucker_commission_bp`, which already defaults to
 * 1200 — twelve per cent — and which the settings card edits.
 *
 * Nothing already earned moves. Every settled run froze its own rate onto
 * `trips.commission_bp` at delivery, which is the whole reason that column
 * exists, so history reads the same after this migration as before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            /**
             * The firm's own tariff — the fallback quote, when no rate-card
             * line covers a run.
             *
             *     price = base + (per_km * km) + (per_kg * kg), floored at minimum
             *
             * Big integers because they are centavos and every other money
             * column in this system is one. Null on all four means the install
             * tariff, and they are deliberately four separate nulls rather than
             * one flag: a firm that only wants to correct its per-kilometre
             * figure should not have to restate the other three.
             */
            $table->unsignedBigInteger('tariff_base_cents')->nullable()->after('trucker_commission_bp');
            $table->unsignedBigInteger('tariff_per_km_cents')->nullable()->after('tariff_base_cents');
            $table->unsignedBigInteger('tariff_per_kg_cents')->nullable()->after('tariff_per_km_cents');
            $table->unsignedBigInteger('tariff_minimum_cents')->nullable()->after('tariff_per_kg_cents');

            /**
             * How long a delivered run's invoice has to run before it is late.
             *
             * Thirty days is the trade's default and not every firm's terms.
             * Read only when an invoice is *raised* — the overdue sweep reads
             * the date on the document, so moving this never reclassifies a
             * receivable that already exists.
             */
            $table->unsignedSmallInteger('billing_terms_days')->nullable()->after('tariff_minimum_cents');

            /**
             * The expanded withholding rate this firm's customers keep back,
             * where the customer has not stated its own.
             *
             * `customers.withholding_rate_bp` still wins: whether a particular
             * shipper withholds, and at what, is a fact about that shipper.
             * This is the figure to assume for the ones nobody has recorded one
             * against, and 2% — the hauling rate — is what it was before the
             * column existed.
             */
            $table->unsignedSmallInteger('withholding_rate_bp')->nullable()->after('vat_rate_bp');

            /**
             * Is the tariff quoted VAT-inclusive?
             *
             * The one setting here that changes the *meaning* of every other
             * price on the screen, which is why it is a tri-state: null defers
             * to the install, and a firm that sets it has said something
             * deliberate. False means a quote is the net haul with VAT added on
             * top; true means the desk quotes one all-in figure and the VAT
             * inside it is worked backwards.
             *
             * Future invoices only. A document already issued froze its rates.
             */
            $table->boolean('prices_include_vat')->nullable()->after('withholding_rate_bp');
        });

        Schema::table('truckers', function (Blueprint $table): void {
            $table->dropColumn('commission_bp');
        });
    }

    public function down(): void
    {
        Schema::table('truckers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('commission_bp')->nullable();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'tariff_base_cents',
                'tariff_per_km_cents',
                'tariff_per_kg_cents',
                'tariff_minimum_cents',
                'billing_terms_days',
                'withholding_rate_bp',
                'prices_include_vat',
            ]);
        });
    }
};
