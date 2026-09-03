<?php

declare(strict_types=1);

/**
 * The business's own numbers — the ones a bookkeeper changes, not a developer.
 *
 * Two things in this system are worked out rather than typed: what a haul is
 * charged at, and when the invoice for it falls due. Both used to be a figure
 * somebody keyed in per trip, which is why the same run could be billed two
 * different amounts by two different people. The rates that replace that
 * judgement live here so they can be corrected in one place, per install,
 * without touching the code that applies them.
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
    | `PricingService` is the only thing that reads these.
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
    | is between retyping every bracket or deriving the difference. This is the
    | second one:
    |
    |     move       = (today - baseline) / baseline
    |     adjustment = clamp(move * sensitivity, -cap, +cap)
    |     price      = bracket price * (1 + adjustment)
    |
    | `baseline_cents` is the pump price the brackets were priced at. A zone may
    | override it; most installs buy fuel at one price and never will.
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
    */
    'diesel' => [
        'baseline_cents' => (int) env('DIESEL_BASELINE_CENTS', 6_500),
        'sensitivity' => (float) env('DIESEL_SENSITIVITY', 0.35),
        'cap_bp' => (int) env('DIESEL_CAP_BP', 2_500),
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
