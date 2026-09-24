<?php

declare(strict_types=1);

/**
 * The install's own numbers — the **defaults**, not the last word.
 *
 * Almost everything here is a column on the company as well, edited by an
 * administrator under Access Control → Rates and resolved by `RateBook`: the
 * tariff, the payment terms, the tax rates and the partner commission. A null
 * column falls through to the figure here, which is what every company has
 * until somebody opens that card — so these values still decide what a fresh
 * install charges, and stop deciding the moment a firm disagrees.
 *
 * That matters because two hauliers share an install. A single
 * `TARIFF_PER_KM_CENTS` cannot describe a firm quoting ₱35 and a firm quoting
 * ₱45, and correcting either used to mean a deployment.
 *
 * What is deliberately *only* here: the payroll contributions, which are the
 * government's and identical for every firm on the platform, and the currency.
 *
 * Two things in this system are worked out rather than typed: what a haul is
 * charged at, and when the invoice for it falls due. Both used to be a figure
 * somebody keyed in per trip, which is why the same run could be billed two
 * different amounts by two different people. The rates that replace that
 * judgement are in one place rather than scattered through the code that
 * applies them — here for the install, and on the company for a firm that has
 * said otherwise.
 *
 * Money is integer centavos throughout (DESIGN.md section 7.1).
 */
return [

    /*
    |----------------------------------------------------------------------
    | Tariff — what a delivery is charged
    |----------------------------------------------------------------------
    |
    | price = base + (per_km * km) + (per_kg * kg), floored at `minimum`.
    |
    | Distance comes off the trip, which fills it in from the two map pins
    | (straight-line, so it is a floor) or takes the road distance a
    | dispatcher entered. A trip nobody has pinned has no distance, and the
    | quote is then base plus weight alone — honest, and still not zero.
    |
    | `RateBook` is the only thing that reads these, and `PricingService` the
    | only thing that reads it. A firm that has set its own four figures on the
    | settings card never reaches this block.
    |
    */
    'tariff' => [
        'base_cents' => (int) env('TARIFF_BASE_CENTS', 150_000),
        'per_km_cents' => (int) env('TARIFF_PER_KM_CENTS', 3_500),
        'per_kg_cents' => (int) env('TARIFF_PER_KG_CENTS', 200),
        'minimum_cents' => (int) env('TARIFF_MINIMUM_CENTS', 150_000),
        'currency' => env('TARIFF_CURRENCY', 'PHP'),
    ],

    /*
    |----------------------------------------------------------------------
    | Diesel — how pump price moves a quote
    |----------------------------------------------------------------------
    |
    | A rate card is drawn at some assumed fuel price. When the pump moves, the
    | whole card is wrong by roughly the fuel share of the run, and the choice
    | is between retyping every band or deriving the difference.
    |
    | There are two ways to derive it, and which one applies is decided per
    | rate-card line rather than here. See `FuelIndex`.
    |
    | **The step**, which is what a subsidy table states, and which needs no
    | setting in this file: a line carries `diesel_step_cents`, the pesos it
    | adds for every ₱1/L above the baseline, and the surcharge is that times
    | the whole pesos of movement. A card typed from a printed table reproduces
    | the table to the peso.
    |
    |     price = base + step * floor((today - baseline) / 100)
    |
    | **The percentage**, for a line with no step — a firm with one card and no
    | table behind it. The settings below are this one's:
    |
    |     move       = (today - baseline) / baseline
    |     adjustment = clamp(move * sensitivity, -cap, +cap)
    |     price      = line price * (1 + adjustment)
    |
    | `baseline_cents` is the pump price the card was priced at. On a banded
    | card it is the **top of the band the printed figures already cover** —
    | the workbook these bands were typed from holds from ₱30 to ₱43 a litre,
    | so ₱43.00 is the baseline and diesel at ₱38 adds nothing. A zone may
    | override it, and a banded one normally does; the value here is the
    | fallback for a card with no band of its own.
    |
    | `sensitivity` is the fuel share of a run — how much of the price actually
    | is diesel. At 0.35, a 10% pump rise moves the quote 3.5%, not 10%. Passing
    | the whole move through would overcharge, because salary, tyres and the
    | office did not get more expensive.
    |
    | `cap_bp` is the guard rail, in basis points. Whatever the pump does, a
    | quote does not move more than this from the card without somebody
    | deciding to redraw it — a bad `baseline_cents` should produce a visibly
    | capped figure, not a bill nobody can explain.
    |
    | Neither applies to a stepped line. A step is a figure the office read off
    | a table and typed in, and clamping it would silently quote something the
    | table does not say.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Road distance — what picks a trip's zone
    |--------------------------------------------------------------------------
    |
    | A zone is a band of kilometres, so the distance a trip carries decides
    | which row of the rate card prices it. That distance used to be the
    | straight line between the two pins, which is the one figure a truck never
    | drives: CDO to Iligan is 49 km as the crow flies and about 90 by road, so
    | a run that belongs in band C was quoted in B. Across Northern Mindanao the
    | road runs 1.15 to 1.8 times the straight line, which is too wide a spread
    | for any single multiplier to fix.
    |
    | So a pinned trip is measured on the road network, by OpenRouteService's
    | heavy-goods profile — the roads a truck is allowed on, not a car's
    | shortcuts. `ORS_API_KEY` is a free key from openrouteservice.org.
    |
    | `detour_factor` is the fallback, used only when the service cannot be
    | asked — no key configured, a timeout, a route it cannot find. The straight
    | line times this is a better guess than the straight line alone, and the
    | trip records that it was an estimate so the desk can see it and correct it.
    |
    | Answers are cached by the pair of pins, rounded to about eleven metres, so
    | the same depot-to-warehouse run costs one call however often it is booked.
    |
    */
    'routing' => [
        'ors_key' => env('ORS_API_KEY'),
        'ors_url' => env('ORS_URL', 'https://api.openrouteservice.org'),
        'profile' => env('ORS_PROFILE', 'driving-hgv'),
        'timeout' => (int) env('ORS_TIMEOUT', 6),
        'detour_factor' => (float) env('ROUTING_DETOUR_FACTOR', 1.4),
        'cache_days' => (int) env('ROUTING_CACHE_DAYS', 90),
    ],

    'diesel' => [
        'baseline_cents' => (int) env('DIESEL_BASELINE_CENTS', 6_500),
        /**
         * The bottom of the baseline band, for display only.
         *
         * A subsidy table's printed prices cover a *range* of pump prices —
         * the workbook's say "From 30 / To 43 per litre diesel" — and only the
         * top of that range is arithmetic, because it is where the surcharge
         * starts counting from. The floor is what the office needs on screen to
         * recognise the card as the one it typed, and nothing reads it to
         * compute a price.
         */
        'band_floor_cents' => (int) env('DIESEL_BAND_FLOOR_CENTS', 3_000),
        'sensitivity' => (float) env('DIESEL_SENSITIVITY', 0.35),
        'cap_bp' => (int) env('DIESEL_CAP_BP', 2_500),
    ],

    /*
    |----------------------------------------------------------------------
    | Tax
    |----------------------------------------------------------------------
    |
    | What a Philippine freight invoice actually carries, and the reason an
    | invoice total was never the number anybody paid:
    |
    |     net                    the haul, priced from the tariff
    |   + VAT      (12%)         charged to the customer, remitted by us
    |   = gross                  what the invoice says
    |   - withholding (2%)       kept back by the customer, remitted by them
    |   = due                    what actually lands in the bank
    |
    | **Both are defaults, not law.** Rates change by statute, a company may
    | not be VAT-registered, and whether a customer withholds depends on
    | whether they are a withholding agent — so the company and the customer
    | each override these, and the invoice freezes whatever applied on the day
    | it was raised. See `TaxService`.
    |
    | Basis points, like the diesel adjustment, so 12% is 1200 and there is no
    | float anywhere near a peso.
    |
    */
    'tax' => [
        /** VAT on sales. 1200 = 12%, the standard PH rate. */
        'vat_rate_bp' => (int) env('TAX_VAT_RATE_BP', 1200),

        /**
         * Expanded withholding tax on payments to contractors.
         *
         * 200 = 2%, which is the rate for hauling and freight services. It is
         * withheld from the **gross** — VAT included — because that is how the
         * BIR computes it, and getting that wrong understates the deduction on
         * every invoice.
         */
        'withholding_rate_bp' => (int) env('TAX_WITHHOLDING_RATE_BP', 200),

        /**
         * Are the tariff and the rate card quoted VAT-inclusive?
         *
         * False out of the box: a quote is the net haul and VAT is added on
         * top, which is how a rate card is normally written. Set it true where
         * the desk quotes customers a single all-in figure — then the price is
         * treated as gross and the VAT inside it is worked backwards, so the
         * customer is billed exactly what they were quoted.
         *
         * This changes what every future invoice says. It does not touch a
         * document already issued.
         */
        'prices_include_vat' => (bool) env('TAX_PRICES_INCLUDE_VAT', false),
    ],

    /*
    |----------------------------------------------------------------------
    | The pre-trip check
    |----------------------------------------------------------------------
    |
    | DESIGN.md section 5.2 puts a checklist on the handset before a run: tyres,
    | oil, gears, brakes, lights, coolant, documents. `InspectionService` owns
    | the list itself and which of those are critical — that is a contract
    | between the screen and every result already stored, not a setting.
    |
    | What is a setting is whether the check *stops* a departure.
    |
    */
    /*
    |----------------------------------------------------------------------
    | Payroll
    |----------------------------------------------------------------------
    |
    | The statutory deductions, and a warning worth reading before trusting
    | them: **these rates change, and they change by circular rather than by
    | law.** SSS, PhilHealth and Pag-IBIG have all moved in the last few years,
    | and the BIR's withholding table moved with the TRAIN schedule in 2023.
    |
    | So they live here, in configuration, with the values that were current
    | when this was written — and every one of them should be checked against
    | the agency's own circular before a first live run. A payroll module that
    | hid its rates in code would be wrong within a year and impossible to
    | correct without a deployment.
    |
    | The percentages are basis points (450 = 4.5%), like every other rate in
    | this system, so there is no float near a peso.
    |
    */
    'payroll' => [
        /**
         * Days between a cutoff and the money going out.
         *
         * Two. A period closing on the 5th is released on the 7th: the gap is
         * where the office compiles the period's charges and gets the budget
         * released, with ten days of billing behind it.
         *
         * The install default, for a firm that has not set its own —
         * `companies.payroll_release_lag_days` is the one actually in force,
         * because two hauliers on one install will not agree on it.
         */
        'release_lag_days' => (int) env('PAYROLL_RELEASE_LAG_DAYS', 2),

        /**
         * How many pay runs a month, which decides how a monthly contribution
         * is split across them.
         *
         * Two is the Philippine norm — the 15th and the end of the month — and
         * the contributions below are monthly figures halved onto each run. An
         * office paying monthly sets this to 1 and the full contribution lands
         * on the single run.
         */
        'runs_per_month' => (int) env('PAYROLL_RUNS_PER_MONTH', 2),

        /**
         * The install-wide default cutoff days, for a company that has not set
         * its own.
         *
         * One or two day-of-month numbers, ascending, each the **last day
         * worked** in a period. `[15, 31]` gives the 1st–15th and the
         * 16th–end; `31` alone is a monthly payroll. A day past the end of a
         * short month clamps, so `31` means "the end of the month".
         *
         * Null derives it from `runs_per_month` above, which is what this
         * setting used to be on its own. Both are only a **default** now: the
         * days a firm actually closes on are `companies.payroll_cutoff_days`,
         * because they are the firm's policy and not the government's, and an
         * environment variable cannot answer two hauliers differently. See
         * `PayrollCalendar`.
         */
        'cutoff_days' => env('PAYROLL_CUTOFF_DAYS') === null
            ? null
            : array_map('intval', array_filter(explode(',', (string) env('PAYROLL_CUTOFF_DAYS')), 'strlen')),

        /*
         * Which cutoff the monthly contributions come off is NOT here.
         *
         * It is `companies.payroll_deduct_on`, because it is a policy rather
         * than a rate: the SSS percentage below is the government's and is the
         * same for every firm on this platform, while whether a firm loads a
         * month of contributions onto the first payslip or the second differs
         * between two companies in the same yard and has to be changeable
         * without a deployment. See `DeductionSchedule`.
         */

        /**
         * SSS, the employee's share.
         *
         * The real schedule is a bracketed table of monthly salary credits, not
         * a flat percentage — this is the percentage-with-a-cap simplification
         * every small office starts with, and it is close for salaries in the
         * middle of the range and wrong at the ends. A fleet running its own
         * payroll properly should replace this with the table; the deduction is
         * editable on the line either way.
         */
        'sss' => [
            'employee_rate_bp' => (int) env('PAYROLL_SSS_RATE_BP', 450),
            /** The monthly salary credit ceiling the rate applies up to. */
            'ceiling_cents' => (int) env('PAYROLL_SSS_CEILING_CENTS', 3500000),
        ],

        /**
         * PhilHealth: 5% of the monthly basic, split evenly between employer
         * and employee — so 2.5% comes off the payslip, between a floor and a
         * ceiling on the salary it is computed from.
         */
        'philhealth' => [
            'employee_rate_bp' => (int) env('PAYROLL_PHILHEALTH_RATE_BP', 250),
            'floor_cents' => (int) env('PAYROLL_PHILHEALTH_FLOOR_CENTS', 1000000),
            'ceiling_cents' => (int) env('PAYROLL_PHILHEALTH_CEILING_CENTS', 10000000),
        ],

        /**
         * Pag-IBIG: 2% of the monthly basic, capped — the cap is what most
         * payslips actually show, since it binds at a low salary.
         */
        'pagibig' => [
            'employee_rate_bp' => (int) env('PAYROLL_PAGIBIG_RATE_BP', 200),
            'cap_cents' => (int) env('PAYROLL_PAGIBIG_CAP_CENTS', 20000),
        ],

        /**
         * Withholding tax, as the BIR's **semi-monthly** graduated table.
         *
         * `over` is the taxable pay for the period above which the bracket
         * applies, `base` is the fixed tax at that point, and `rate_bp` is what
         * the excess is taxed at. Taxable pay is the gross less the statutory
         * contributions, which is the order the BIR computes it in — deducting
         * tax before the contributions would overstate it on every payslip.
         *
         * These are the 2023-onward TRAIN figures. Check them.
         */
        'withholding' => [
            /**
             * The salary range that pays no income tax at all.
             *
             * ₱250,000 a year is exempt under TRAIN, which is ₱20,833.33 a
             * month — so somebody at or below this earns nothing taxable and
             * should never see a withholding line, whatever a single fortnight
             * happens to look like.
             *
             * Checked against the **monthly basic**, deliberately, and not
             * against the period's gross. A person on ₱20,000 a month who gets
             * a ₱3,000 allowance in one fortnight is not suddenly a taxpayer
             * for that fortnight: their salary decides whether they are taxed,
             * and the bracket table below only decides how much once they are.
             * Applying the table alone would tax that allowance and make
             * payroll look arbitrary to the person receiving it.
             *
             * Set to 0 to disable the check and let the bracket table decide on
             * its own.
             */
            'exempt_monthly_at_or_below_cents' => (int) env('PAYROLL_TAX_EXEMPT_MONTHLY_CENTS', 2083333),

            'brackets' => [
                ['over' => 0, 'base' => 0, 'rate_bp' => 0],
                ['over' => 1041700, 'base' => 0, 'rate_bp' => 1500],
                ['over' => 1666700, 'base' => 93750, 'rate_bp' => 2000],
                ['over' => 3333300, 'base' => 427160, 'rate_bp' => 2500],
                ['over' => 5416700, 'base' => 947920, 'rate_bp' => 3000],
                ['over' => 10416700, 'base' => 2447920, 'rate_bp' => 3200],
                ['over' => 34166700, 'base' => 10047920, 'rate_bp' => 3500],
            ],
        ],

        /**
         * The accounts a paid run posts to, by code from the seeded chart.
         *
         * Salaries to expense, each agency's share to its own payable, and the
         * net to cash — which is what makes payroll part of the books rather
         * than a spreadsheet beside them. An install that renumbered its chart
         * sets these; a run whose accounts are missing is reported rather than
         * posted, because a half-posted payroll is worse than an unposted one.
         */
        'accounts' => [
            'salaries_expense' => env('PAYROLL_ACCOUNT_SALARIES', '5200'),
            'accrued_wages' => env('PAYROLL_ACCOUNT_ACCRUED', '2100'),
            'statutory_payable' => env('PAYROLL_ACCOUNT_STATUTORY', '2200'),
            'withholding_payable' => env('PAYROLL_ACCOUNT_WITHHOLDING', '2160'),
            'cash' => env('PAYROLL_ACCOUNT_CASH', '1020'),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | The books
    |----------------------------------------------------------------------
    */
    'accounting' => [
        /**
         * Which expense group is the cost of actually hauling.
         *
         * The income statement measures gross profit against it — revenue less
         * the cost of providing the service, which for a fleet is the one
         * figure that says whether the hauling itself pays before the office is
         * paid for. It is an `accounts.group` label, and the seeded chart uses
         * this one.
         *
         * An install that renames the group gets **no** gross profit rather
         * than a wrong one. That is the right failure: a margin computed
         * against the wrong half of the expenses is worse than no margin.
         */
        'cost_of_services_group' => env('ACCOUNTING_COST_GROUP', 'Cost of services'),
    ],

    'inspection' => [
        /**
         * Must a unit pass its pre-trip check before the run can start?
         *
         * True, and it is the honest default: a checklist with no consequence
         * is a form, and the fleet that installed this asked for a check rather
         * than a form. A driver who skips it is told to run it and where.
         *
         * The switch exists for the two cases where an outright block is the
         * wrong answer — an install piloting the handset with half its drivers
         * still on paper, and a yard whose checks are recorded in a system this
         * one does not talk to yet. Turning it off does not stop the check
         * being recorded or shown; it stops it being a gate.
         */
        'required_before_start' => (bool) env('INSPECTION_REQUIRED_BEFORE_START', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Billing terms
    |----------------------------------------------------------------------
    |
    | How long a delivered run's receivable has to run before it is overdue.
    | `cargo:invoices-overdue` reads the date, not this value, so changing it
    | only affects invoices raised from here on.
    |
    */
    'billing' => [
        'terms_days' => (int) env('BILLING_TERMS_DAYS', 30),
    ],

    /*
    |----------------------------------------------------------------------
    | Partner truckers
    |----------------------------------------------------------------------
    |
    | What the haulier keeps of what an owner-operator's run bills, in basis
    | points — 1200 is twelve per cent.
    |
    | This is the install-wide fallback and very little should read it. The
    | rate a firm actually works to is `companies.trucker_commission_bp`, set
    | on the settings card, because it is a commercial term between that
    | haulier and the people hauling for it and two companies on this platform
    | will not agree on it. This answers only for a company row that predates
    | the column.
    |
    | There was a per-partner override, `truckers.commission_bp`, and it is
    | gone. It could only be set from a number field on one partner's detail
    | screen — beside an approve button and a wallet — and a cut the office
    | cannot state in one figure is a cut nobody can check.
    |
    | Whichever applies, it is frozen onto the trip at delivery — see
    | `trips.commission_bp`. Changing either number changes what future runs
    | are split at and touches nothing already earned.
    |
    */
    'truckers' => [
        'commission_bp' => (int) env('TRUCKER_COMMISSION_BP', 1200),

        /**
         * The fleet a trucker registers with — a company `code`, or null.
         *
         * Nobody is asked this on the sign-up form. A trucker registers with
         * the platform's own fleet wherever in the country they are, and that
         * firm vets them; the choosing happens on the *customer's* side, per
         * load, between that fleet and whichever vetted truckers are near them.
         *
         * Null means the oldest company on the install, which is the one
         * registration created and the same fallback the seeders use for "this
         * install's company". Set it only where an install runs more than one
         * fleet and the first is not the one taking partners.
         */
        'registers_with' => env('TRUCKER_REGISTERS_WITH'),

        /**
         * How far a customer is shown truckers from, in kilometres.
         *
         * Tighter than the carrier radius, and deliberately: a haulier two
         * provinces away is an ordinary answer for a full truckload because it
         * has a yard, a roster and units it can send. One man with one truck is
         * not that — if he is 150 km away he is not available this afternoon,
         * and listing him is offering the customer something that will not
         * arrive.
         *
         * The fleet itself is never filtered out by distance: it is the answer
         * for every load, near or far, and is always on the list.
         */
        'customer_radius_km' => (float) env('TRUCKER_CUSTOMER_RADIUS_KM', 60),

        /**
         * How stale a partner's reported position may be and still be used to
         * sort a job board, in minutes.
         *
         * Two hours. A pin is reported by the handset rather than derived from
         * a run, so it goes stale the moment somebody closes the app — and a
         * board sorted by where a man was on Tuesday sends a load to the wrong
         * province. Past this the partner still sees every job; they are just
         * not told which is nearest, because nobody knows.
         */
        'position_fresh_minutes' => (int) env('TRUCKER_POSITION_FRESH_MINUTES', 120),

        /** How far a partner is shown work from, in kilometres. */
        'job_radius_km' => (float) env('TRUCKER_JOB_RADIUS_KM', 150),
    ],

    /*
    |----------------------------------------------------------------------
    | Customer portal logins
    |----------------------------------------------------------------------
    |
    | The password a customer account is created with when the office adds the
    | firm in Customer Management. A customer has to be able to sign in and
    | book their own work from the moment they are on the books, and the desk
    | has nothing to hand them if the account is made without one.
    |
    | It is a starting password and the same for every customer, so it is not a
    | secret: the create response prints it once so whoever added the firm can
    | pass it on, and the customer is expected to change it. Set it per install
    | rather than leaving the value published in a repository.
    |
    */
    'portal' => [
        'default_password' => (string) env('CUSTOMER_DEFAULT_PASSWORD', 'cargorush123'),
    ],

    /*
    |----------------------------------------------------------------------
    | Proof of delivery
    |----------------------------------------------------------------------
    |
    | Where the photograph taken at the door is kept. The `public` disk needs
    | `php artisan storage:link` once per install, or the stored URL resolves
    | to nothing.
    |
    */
    'pod' => [
        'disk' => env('POD_DISK', 'public'),
        'directory' => env('POD_DIRECTORY', 'pod'),
        /** Kilobytes. A phone photo is ~2–4 MB; this leaves room without inviting video. */
        'max_kb' => (int) env('POD_MAX_KB', 8192),
    ],

    /*
    |----------------------------------------------------------------------
    | The company's logo
    |----------------------------------------------------------------------
    |
    | Every upload is normalised to a square PNG of `logo_px` on a side and
    | stored at that size — the client's original is never kept. Three reasons,
    | and the last is the one that matters:
    |
    |   The sidebar renders it at a fixed 32px. A 2400px original would be four
    |   megabytes shipped to draw a thumbnail, on every page load, for every
    |   person in the company.
    |
    |   One size and one format means no client has to guess what it is about to
    |   render, and a JPEG with a white box behind a transparent-looking mark
    |   cannot slip through.
    |
    |   64 is 32 at twice the density. A logo stored at exactly its display size
    |   is soft on every laptop made in the last decade; storing 2× and drawing
    |   at 1× is what makes it crisp. Raise this to 128 if the mark ever needs
    |   to appear larger than 64px anywhere.
    |
    | `max_kb` bounds the *upload*, not the result — the stored file is a 64px
    | PNG and will be a few kilobytes whatever arrives. It is there so a
    | mis-picked 40MB scan is refused before it is decoded rather than after.
    |
    | The `public` disk needs `php artisan storage:link` once per install, or
    | the stored URL resolves to nothing.
    |
    */
    'company' => [
        'disk' => env('COMPANY_DISK', 'public'),
        'directory' => env('COMPANY_DIRECTORY', 'companies'),
        'logo_px' => (int) env('COMPANY_LOGO_PX', 64),
        'logo_max_kb' => (int) env('COMPANY_LOGO_MAX_KB', 4096),
    ],

    /*
    |----------------------------------------------------------------------
    | People — staff photographs and CVs
    |----------------------------------------------------------------------
    |
    | Its own disk setting rather than sharing the proof-of-delivery one,
    | because these are personnel files. An install that later moves employee
    | records onto private storage — which is where they belong once there is
    | anywhere to put them — should not have to move every delivery photograph
    | with them.
    |
    | The `public` disk needs `php artisan storage:link` once per install, or
    | the stored URL resolves to nothing.
    |
    */
    'hr' => [
        'disk' => env('HR_DISK', 'public'),
        'directory' => env('HR_DIRECTORY', 'people'),
        /** Kilobytes. An ID photograph, not a portrait session. */
        'photo_max_kb' => (int) env('HR_PHOTO_MAX_KB', 4096),
        /** A CV is a PDF or a scan; a few megabytes covers both. */
        'resume_max_kb' => (int) env('HR_RESUME_MAX_KB', 8192),
        /**
         * The password a staff account is created with from the roster.
         *
         * The same trade as the customer portal's, made for the same reason:
         * somebody adding a new hire has to be able to hand them credentials
         * that afternoon. It is a starting password, not a secret — the create
         * response prints it once, and the account is expected to change it.
         */
        'default_password' => (string) env('STAFF_DEFAULT_PASSWORD', 'cargorush123'),
    ],
];
