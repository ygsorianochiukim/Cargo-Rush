# Cargo Rush — Design System

**Fleet Management System.** This document is the single source of truth for visual and structural
design across all three apps in this repo:

| App | Path | Stack | Role |
| --- | --- | --- | --- |
| `CargoUI` | `CargoUI/` | Angular 20 + Tailwind CSS 4 | Admin web dashboard (reference implementation of this doc) |
| `cargoApp` | `cargoApp/` | Expo SDK 57 + React Native 0.86 + expo-router | Mobile app (driver / field) |
| `CargoApi` | `CargoApi/` | Laravel 12 + Sanctum | REST API that feeds both clients |

Rule of thumb: **the web shell defines the structure, mobile translates it, the API supplies it.**
Nothing in the shell (nav items, user identity, role label, page titles) is hardcoded in a client if
the API can own it.

---

## 1. Brand palette

These are the only brand colors. They are sampled directly from the approved mockup — do not
re-derive, tint, or "improve" them.

| Token | Hex | Use |
| --- | --- | --- |
| `--cr-blue` | `#15589C` | Primary. Buttons, links, active nav, focus rings, logo mark. |
| `--cr-red` | `#A11807` | Accent / destructive. Logo mark, delete, critical alerts, overdue badges. |
| `--cr-tint` | `#DFF0FF` | Content canvas. The large working surface behind every page. |
| `--cr-surface` | `#FFFFFF` | Sidebar, cards, modals, table rows, inputs. |
| `--cr-shell` | `#1F1F1F` | Modal and drawer scrims (at 50%), and the ink for body text. |

### Derived neutrals

| Token | Hex | Use |
| --- | --- | --- |
| `--cr-ink` | `#1F1F1F` | Primary text (same value as shell — one dark, used two ways). |
| `--cr-ink-muted` | `#6B7280` | Secondary text, role labels, table meta, placeholders. |
| `--cr-line` | `#E5E7EB` | Hairline dividers (under logo, above user chip, table rules). |
| `--cr-blue-hover` | `#12497F` | Blue pressed / hover. |
| `--cr-red-hover` | `#851406` | Red pressed / hover. |
| `--cr-success` | `#12805C` | Delivered, active, in-service. |
| `--cr-warning` | `#B26A00` | Pending, maintenance due. |

### Status to color mapping (shared vocabulary)

The API returns a status **string**; clients map it. Never let the API return a hex color.

| Status value | Color token | Web pill background | Meaning |
| --- | --- | --- | --- |
| `active`, `delivered`, `paid` | `--cr-success` | `#E6F4EF` | Healthy |
| `in_transit`, `assigned`, `scheduled`, `available` | `--cr-blue` | `--cr-tint` | In progress or ready |
| `pending`, `maintenance` | `--cr-warning` | `#FDF3E3` | Needs attention |
| `cancelled`, `overdue`, `inactive` | `--cr-red` | `#FBE9E7` | Problem |

**Two of these carry more meaning than a colour, and both are load-bearing.**

`pending` means **nobody has decided about this yet** — a delivery a customer
has requested and the office has not confirmed. It used to mean "due and waiting
for the driver", which left the request state with no word of its own. What it
used to mean is now `assigned`: a run with a driver, a helper, a unit and a
time, which is the only state a driver can start from.

`paid` means **money that has arrived**. Settling an invoice used to write
`delivered` — the word for a closed-out haul — so the two could not be told
apart, and no page could add up what had been collected without also counting
every delivered trip.

### Do / Don't

- **Do** use `--cr-tint` as the canvas and `--cr-surface` for anything sitting on it. That contrast
  pair *is* the Cargo Rush look.
- **Do** keep red rare. It is a co-brand color in the logo and a warning color everywhere else.
- **Don't** put `--cr-tint` text on `--cr-surface` (fails contrast).
- **Don't** introduce gradients, glassmorphism, or a second blue.

**Charting many categories.** A composition chart with more than four parts (the net-income
doughnut, for example) still uses one hue: step `--cr-blue` by opacity — 1, .78, .6, .45, .33,
.24 — darkest slice first, and let the direct labels carry identity. A negative value keeps
`--cr-red` at full opacity so a loss can never read as a contribution. This adds no new hex
values to the palette.

---

## 2. Typography

| Role | Family | Size / Weight | Notes |
| --- | --- | --- | --- |
| Logo wordmark | **Race Sport**, 400 | Sized to fit its container | Live text, uppercase. `CARGO` in `--cr-blue`, `RUSH` in `--cr-red`. |
| Logo tagline | Sans, 500 | 11px | "Fleet Management System" |
| Page title | Sans, 400 | 24px, letter-spacing `0.02em` | Always uppercase: `DASHBOARD` |
| Section heading | Sans, 600 | 16px | |
| Body | Sans, 400 | 14px, line-height 1.5 | |
| Table header | Sans, 600 | 12px, uppercase, `0.04em` | `--cr-ink-muted` |
| Meta / role label | Sans, 500 | 10px, uppercase, `0.06em` | `--cr-ink-muted` — e.g. `ADMINISTRATOR` |
| Numeric (KPIs, IDs, weights) | Tabular sans or mono | 28px for KPI | Tabular figures so columns align. |

Base family: **Inter** (web) / system sans (mobile). One family, weights 400 / 500 / 600 only.

**Page titles are uppercase.** Nothing else is uppercase except table headers and meta labels.

### The brand face

The company name is set in **Race Sport** — one weight, no italic, drawn for display sizes.
It is a wordmark face, not a UI face, so it is scoped to exactly one selector per client and
may not appear anywhere else. Inter does all the reading work.

| Client | File | How it is reached |
| --- | --- | --- |
| Web | `CargoUI/public/brand/fonts/RaceSport.ttf` | `@font-face` in `styles.css`, token `--font-brand`, used only by `.cr-wordmark` |
| Mobile | `cargoApp/assets/fonts/RaceSport.ttf` | `useFonts` in `app/_layout.tsx`, token `BrandFont`, used only by `components/ui/wordmark.tsx` |

The name is **live text, not part of the mark PNG**. That keeps it crisp at any size, lets it
respect OS font scaling, and makes it readable to a screen reader. The arrows stay an image,
because they are a drawn shape rather than type.

One component per client renders the lockup — `shared/wordmark.ts` (web) and
`components/ui/wordmark.tsx` (mobile). Both take `variant` (`full` / `mark`), and size the mark
from the cap height so the lockup can never be stretched out of ratio.

**Size it by the room, not by a number.** `CARGORUSH` is **8.16 em** wide in this face, measured
from the font's own `hmtx` table. At 22px that is 179px of type, and the sidebar's brand row has
208px for the mark *and* the name — which is how the wordmark came to hang out of the panel. The
web component therefore takes a `maxWidth` and works the size out itself; pass `size` only where
the container is not the constraint.

> **Licensing.** The bundled Race Sport is the personal-use release. A commercial licence
> must be bought from the foundry before this ships. Nothing else in the design depends on
> it: both wordmark components fall back to a heavy sans and keep their layout.

### Brand assets

The master mark is `Assets/CargoRush-logo.png` (1513×968, transparent). It is the **only** source;
every shipped asset is generated from it, never redrawn.

| Asset | Where | Built from |
| --- | --- | --- |
| `logo-mark.png` (1x/2x/3x) | `cargoApp/assets/images/brand/` | master, trimmed and scaled |
| `logo-full.png` (1x/2x/3x) | `cargoApp/assets/images/brand/` | mark + wordmark + tagline lockup |
| `logo-mark.png`, `logo-full.png`, `favicon.png` | `CargoUI/public/brand/` | same lockup at 4x |
| `icon.png`, `splash-icon.png`, `favicon.png`, `android-icon-*.png` | `cargoApp/assets/images/` | mark centred on the app-icon canvas |

Aspect ratios are fixed: **mark 1.572**, **lockup 3.833**. Size a logo by height and let the width
follow; never set both.

The icon set is generated the same way — one 24×24 path definition per icon produces the mobile
template PNGs (`cargoApp/assets/images/icons/`) and the web SVG paths
(`CargoUI/src/app/shared/icon-paths.ts`), so both clients draw identical shapes.

---

## 3. Spacing, radius, elevation

Everything is a multiple of **4**.

```
xs 4   sm 8   md 16   lg 24   xl 32   2xl 48
```

| Token | Value | Use |
| --- | --- | --- |
| `--cr-radius-panel` | `16px` | Sidebar, content canvas, modals |
| `--cr-radius-card` | `12px` | Cards, KPI tiles |
| `--cr-radius-control` | `8px` | Buttons, inputs, pills |
| `--cr-radius-full` | `9999px` | Avatars, status dots |
| `--cr-shadow-panel` | `0 2px 12px rgba(0,0,0,.10)` | Sidebar / canvas lift off the shell |
| `--cr-shadow-card` | `0 1px 3px rgba(0,0,0,.08)` | Cards on the canvas |

Gutter between shell edge and panels: **16px**. Gap between sidebar and canvas: **16px**.
Padding inside the canvas: **24px**. Padding inside the sidebar: **16px**.

---

## 4. Layout — the shell

This is the structure every web screen inherits. It never re-renders on navigation; only the canvas
body swaps.

The frame and the canvas are the **same** `--cr-tint`. The sidebar is the only panel that lifts off
it, so the page reads as one calm surface with white cards on it — there is no dark chrome.

```
................................................................  <- --cr-tint (#DFF0FF), whole page
:  +--------------+                                            :
:  |   LOGO       |    DASHBOARD              [actions]        :  <- canvas header, no panel
:  |--------------|                                            :
:  |              |    +----------------------------------+    :
:  |  NAV         |    |  card (--cr-surface, radius 12)  |    :
:  |  (scrolls)   |    +----------------------------------+    :
:  |              |                                            :
:  |--------------|    +----------------------------------+    :
:  | [av] NAME    |    |  card                            |    :
:  |      ROLE    |    +----------------------------------+    :
:  +--------------+                                            :
:   --cr-surface         16px gap                              :
................................................................
```

### Sidebar (`--cr-surface`, radius 16, shadow-panel)

- Fixed width **240px** (collapsed: **72px**, icons only, tooltips on hover).
- Three stacked regions, in order, full height:
  1. **Brand** — logo asset, 16px padding, then a `--cr-line` hairline.
  2. **Nav** — vertical list, flex-grows, scrolls internally if it overflows.
  3. **User chip** — pinned to the bottom, `--cr-line` hairline above, 16px padding.
- **Nav item:** 40px tall, 8px radius, 12px horizontal padding, 20px icon + 12px gap + 14px label.
  - Default: transparent background, `--cr-ink` text.
  - Hover: `--cr-tint` background.
  - Active: `--cr-tint` background, `--cr-blue` text and icon, 3px `--cr-blue` bar on the left edge.
- **User chip:** 28px square avatar (radius-full), name at 12px/600, role at 10px uppercase
  `--cr-ink-muted`. The whole chip is a button that opens the account menu.

### Content canvas (inherits `--cr-tint`, no panel of its own)

- Fills the remaining width and scrolls internally — **the page itself never scrolls**.
- No background, radius or shadow: it is the same tint as the frame. Structure comes from the
  white cards sitting on it, not from a container around them.
- Header row: page title left, actions right (at most 1 primary + 1 secondary button).
- Body: cards and tables on `--cr-surface` with `--cr-radius-card`.

### Breakpoints

| Range | Behavior |
| --- | --- |
| `>=1280px` | Sidebar 240px, expanded |
| `1024–1279px` | Sidebar collapsed to 72px (icons only) |
| `768–1023px` | Sidebar becomes an overlay drawer; hamburger in the canvas header |
| `<768px` | Hand off to the mobile pattern (section 6) |

---

## 5. Module map (information architecture)

The two clients are **different products against one API**, not the same app at two sizes:

- **`CargoUI` is the back-office.** An operations controller sits at a desk and runs the whole
  business from it — twelve modules.
- **`cargoApp` is the driver's app.** A driver holds it in the cab and works one trip at a time —
  six modules. It is a deliberate subset, not a shrunken dashboard.

Nothing outside these maps ships without the map being updated first.

### 5.1 Web — `CargoUI` (12 modules)

Sidebar order and grouping. `key` is what `GET /api/v1/navigation` returns.

| Group | Module (`key`) | Route | Contents |
| --- | --- | --- | --- |
| **Operations** | Dashboard (`dashboard`) | `/dashboard` | Tracking monitoring · tracking schedules · delivery history · consolidated information · receivables — pending against successful payment — and income earned |
| | GPS Dashboard (`gps`) | `/gps` | Location monitoring · details display · ETA display |
| | Trip Management (`trips`) | `/trips` | Delivery requests awaiting confirmation · trip details · route management (place name plus optional map pin at each end) · cargo management · driver assignment · helpers information · dropoff and pickup location · schedule management · tariff price |
| | Dispatch Monitoring (`dispatch`) | `/dispatch` | Dispatch records · time and location |
| | Delivery Logs (`delivery-logs`) | `/delivery-logs` | Delivery and driver/helper record · delivery details · dispatch records · proof-of-delivery logs (system-assigned reference, photograph, signed name) · delivery report (pending / active / complete) |
| **Assets** | Vehicle Management (`vehicles`) | `/vehicles` | Vehicle details (registration, capacity, status, others) · maintenance |
| | Drivers Management (`drivers`) | `/drivers` | LTMS records for violations · personal records · licence · driver status |
| | Fuel Expense Monitoring (`fuel`) | `/fuel` | Daily fuel budget · budget requests · odometer monitoring · receipt/charge · consumption history · consumption projection |
| **Finance** | Trip Monitoring (`monitoring`) | `/monitoring` | One daily row per truck: trip income, fuel, driver salary, helper salary, maintenance, allowance, route, remarks |
| | Profitability (`profitability`) | `/profitability` | 10-day window: income, expenses and net income per unit, best performing truck, expense split |
| | Quarterly Summary (`summary`) | `/summary` | The same roll-up over a quarter, income against expenses per unit |
| **Business** | Customer Management (`customers`) | `/customers` | Customer records · transaction history · feedback · the firm's portal login, created with the record |
| | Billing & Invoice (`billing`) | `/billing` | Consolidated trip billing and invoice reports · receivables raised automatically on delivery · payment records · payables · payment history logs |
| **Support** | Incident Management (`incidents`) | `/incidents` | Incident records (time and place, history) |
| | Notification Management (`notifications`) | `/notifications` | Incident notification |

#### The Finance group comes from the workbook

`Assets/v3 Cargorush Master Dashboard 2026.xlsx` is the spec for these three
modules, and it is the **only** source for how the money is calculated:

```
total_expenses = fuel + driver_salary + helper_salary + maintenance + allowance
net_income     = trip_income - total_expenses
net_share      = net_income / total_net_income        (the "% OF NET INCOME" column)
```

| Workbook sheet | Becomes |
| --- | --- |
| One sheet per truck (`MAR1390`, `CBS8862`, …) | Trip Monitoring, one tab per unit |
| `DashBoard` — 10-day window, per-unit table, best truck, charts | Profitability |
| `Summary` — quarter slicer, per-unit table | Quarterly Summary |
| `Table11` quarter boundaries | `FinanceService::quarters()` |

Rules that follow from it:

- **The formulas live in `Domain/Finance/Services/FinanceService` and nowhere
  else.** A page never adds up expenses itself; it reads the roll-up the API
  computed. Profitability and Quarterly Summary hit the same method with
  different ranges, so the two cannot print different arithmetic.
- **Total expenses and net income are derived, never entered.** Entry forms show
  them updating live so the person recording can sanity-check as they type.
- **Trip income is derived too, and that is a change.** The workbook's rule was
  that income is entered, and for a transcribed sheet it was right: those rows
  record days already priced and already agreed. It could not survive a customer
  booking their own delivery — there is nobody to type a figure at that point,
  and quoting one afterwards means billing a price the customer was never shown.
  So a trip is **quoted from a tariff when it is booked**
  (`Domain/Billing/Services/PricingService`, rates in `config/cargo.php`):

  ```
  price = base + (per_km * km) + (per_kg * kg)     floored at the minimum
  ```

  Delivering credits that figure to the day's row and raises the customer's
  receivable for the same amount, so the sheet, the invoice and what the
  customer was quoted are one number and cannot disagree. A price sent
  explicitly is a negotiated rate and is left alone.

  The **expenses stay entered**. A trip knows what it was charged; it has no
  idea what the fuel cost.
- **Money is integer centavos** (section 7.1). The workbook's ₱30,721.00 is
  `3_072_100`. Formatting to pesos happens in the view only.
- **A truck with no plate is still a truck.** Units 7 and 8 exist with
  `plate: null` and must render as "Unassigned", not be filtered away.
- **Losses are first-class.** Three of six units are underwater in the seed
  period; net income is red with a minus sign, and the net-income chart is
  diverging so a loss reads as a bar left of the axis, not a short bar.
- `CargoApi/tests/Feature/FinanceRollupTest.php` pins the transcription in
  `LedgerSeeder` to the figures the workbook itself prints. Re-key a row and
  the aggregate it feeds fails there.
- The client keeps the two formulas in `services/finance/finance.math.ts` for
  one reason only: the entry form derives its totals **as somebody types**,
  and a round trip per keystroke is not that. `finance.math.spec.ts` pins
  those to the same definition, so the preview cannot differ from what the
  API will store.

### 5.2 Mobile — `cargoApp` (two products, five tabs each)

**`cargoApp` is one app holding two products.** A driver signing in gets the cab
screens; a customer signing in gets the portal. Which set appears follows from
`role` on `GET /me` and from nothing else, because the two are not subsets of
one another: a customer has no `drivers` row, so every driver screen would 404
for them, and a driver has no `customers` row, so every portal screen would 404
for a driver.

#### The driver — 6 modules, 5 tabs

A driver cannot reach twelve tabs one-handed, so the six modules collapse into five tabs. The tab
bar cap in section 6 is the constraint; **Inspect** and **More** are hubs, not new modules.

| Tab | Module(s) | Route | Contents |
| --- | --- | --- | --- |
| Dashboard | Dashboard | `/` | Driver availability · notification for new delivery · confirmed delivery list · current trip · upcoming trips · current location |
| Cargo | Cargo Details | `/cargo` | Cargo information · location · ETA · dispatch and arrival · trip information · pickup and delivery information |
| Tracking | GPS Tracking | `/tracking` | Average speed · location point A to B |
| Inspect | On-Boarding Trips Inspection **and** Unit Maintenance and Inspection | `/inspect` | Pre-trip vehicle inspection (tires, oil, gears, …) with AI-assisted good-to-go check; assigned maintenance and unit inspection |
| More | Delivery Logs **and** the driver's profile | `/more` | Trip history · proof of delivery · account and licence details |

The driver also records the day's trip income and expenses from the Dashboard — the workbook's
"record the daily trip income and expenses in each truck" step, done in the cab. It writes the same
row the back office reads in Trip Monitoring, with total expenses and net income derived live.

#### The customer — 4 modules, 5 tabs

| Tab | Module(s) | Route | Contents |
| --- | --- | --- | --- |
| Home | Customer Dashboard | `/` | What is awaiting confirmation · what is booked and on the road · pending payment against payments made · the most recent deliveries |
| Request | Delivery Request | `/request` | Pickup and drop-off, each typed or pinned on a map (search, the phone's own position, or a tap) and named from the pin · what is being moved · weight · preferred collection · the quoted price, shown on filing |
| Deliveries | My Deliveries | `/orders` | Every request this account has filed, with what each status means in plain English · crew and unit once confirmed |
| Invoices | My Invoices | `/invoices` | Receivables only · what is owed against what is paid · the trip each document is for |
| More | Profile | `/more` | Account and contact details · sign out |

Every customer screen reads a `portal/*` endpoint scoped to the caller's
`customers` row, never a filtered view of the whole board — the same
arrangement that scopes the driver endpoints to a `drivers` row, and for the
same reason: one forgotten `where` on a shared endpoint exposes every firm's
work.

**The customer requests; the office confirms.** A request carries the load and
the two ends and nothing else, and lands as `pending`. Naming a driver, a
helper, a unit and a time (`POST trips/{trip}/confirm`) is what makes it
`assigned`, and `assigned` is the only state a driver can start from.

### 5.3 What the two share

| Concern | Rule |
| --- | --- |
| Status vocabulary | One list (section 1). A status means the same thing in both apps. |
| Envelope and keys | One contract (section 7). No mobile-only response shapes. |
| Tokens | One palette and one 4pt scale, expressed twice (section 10). |
| Icons | One icon set, same path data, PNG on mobile and SVG on web. |
| Trip identity | A trip's `reference` (e.g. `CR-24817`) is the only ID a human ever reads. |

### 5.4 Deliberately mobile-only

Two capabilities exist only in the driver app because they need the device:

- **On-boarding (pre-trip) inspection** — camera and checklist at the vehicle, including the
  AI-assisted good-to-go call.
- **GPS tracking** — the handset is the position source that feeds the web GPS Dashboard.

The web app *reads* both; it never captures them.

---

## 5A. Code structure

One shape, three languages. A module is a folder in each layer, and the layers are the same
everywhere: **Model → Service → Page**, with a single **Layout** that every page renders inside.

```
Model     ──▶  Module/HR sample  ──▶  HumanResourceModel
Services  ──▶  Module/HR sample  ──▶  HumanResourceServices
Pages     ──▶  Module/HR sample  ──▶  HumanResourcePage
Layout    ──▶  Layout
```

The point is that a module is findable by name in every layer. "Where does Trip Management live?"
has the same answer in all three apps: a `trip` folder under each of `models`, `services` and
`pages`.

### 5A.1 Web — `CargoUI/src/app`

```
models/<module>/<module>.model.ts     shapes only, mirroring the API contract
services/<module>/<module>.service.ts the HTTP calls for that module
pages/<module>/<module>.page.ts       the screen, one lazy chunk per route
layout/layout.ts                      the shell: sidebar, canvas header, modal hosts
shared/                               UI primitives from section 8 (Card, Table, Modal, …)
```

- A **model** is types. It imports nothing but other models and never reaches for `HttpClient`.
- A **service** is the only thing that calls the API, and it does so through
  `services/shared/api.service.ts` — which owns the base URL, the credentials mode and the
  envelope unwrapping. A service that wanted its own conventions would have to go around that
  class, which is the point.
- A **page** injects its module service as `<module>Api` and holds no fetch logic of its own.
- The **layout** is the only component outside `pages/`, because it is the only thing that
  survives navigation.

### 5A.2 Mobile — `cargoApp/src`

The same four layers, plus the router's own directory:

```
models/<module>/<module>.model.ts
services/<module>/<module>.service.ts
pages/<module>/<module>.page.tsx
layout/layout.tsx                     the bottom tab bar
app/                                  expo-router route files — one-line re-exports
```

`app/` exists because expo-router requires it, and it stays **thin on purpose**: each file
re-exports a page. Renaming a tab is a change in `app/`; changing what a tab does is a change in
`pages/`. The two never have to move together.

### 5A.3 Backend — `CargoApi/app/Domain`

Per module, rather than per file type:

```
Domain/<Module>/
  DTO/          what crosses a boundary
  Models/       Eloquent
  Repositories/ every query the module runs
  Services/     the business rules
  Controllers/  thin, resourceful
  Requests/     validation for writes
  Resources/    the JSON shape
```

`Domain/Shared/` holds what all of them stand on: the status enums, the `Data` base, the
`Repository` base, `ApiController` (which owns the section 7.1 envelope), and `ApiResource`
(which owns ISO timestamps).

The dependency direction is one-way and worth stating plainly:

**Controller → Service → Repository → Model**

- A controller never builds a query and never holds a rule. It validates through a Request, hands
  the resulting DTO to a Service, and wraps what comes back.
- A service never sees a `Request` and never touches a query builder. It composes repositories.
- A repository is the only place a query is written. Nothing outside that layer touches the
  builder.
- A **DTO** is the only thing that crosses those boundaries. Passing an array instead would make a
  renamed form field a silent no-op instead of a type error.

Because models live under `Domain/<Module>/Models` rather than `app/Models`, the two conventions
Laravel would otherwise infer — route-model binding and factory discovery — are declared once in
`Domain/Shared/Providers/DomainServiceProvider`.

### 5A.4 Where a rule is allowed to live

| Rule | Home | Why not elsewhere |
| --- | --- | --- |
| The two workbook formulas | `Domain/Finance/Services/FinanceService` | Profitability and Quarterly Summary are one roll-up over two ranges; two copies would drift |
| A trip's reference | `Trip::booted()` | Assigning it in a service leaves a window where a row exists with no reference |
| `good_to_go` on an inspection | `InspectionService::isGoodToGo()` | A driver in a hurry must not be able to post a pass over a failed brake check |
| Overdue | derived, then reconciled on a schedule | It is a fact about the clock; a stored status goes stale the moment nothing walks the table |
| Status → colour | `shared/status.ts` (web) / `constants/status.ts` (mobile) | The API returns a string; a hex value never crosses the wire |

The client keeps a copy of the two formulas in `services/finance/finance.math.ts` for exactly one
reason: the entry form shows total expenses and net income updating **as somebody types**, and a
round trip per keystroke is not that. Those functions are pinned to the same figures the API is,
so the preview a person sanity-checks a row against cannot differ from what gets stored.

---

## 6. Mobile translation (`cargoApp`)

Mobile keeps the **same information architecture and the same tokens** — only the chrome changes.
The mapping is mechanical, so do not invent mobile-only structure.

| Web shell part | Mobile equivalent |
| --- | --- |
| Tint frame | Not used as a frame. Mobile is edge-to-edge: the screen body is `--cr-tint`, the header is `--cr-surface`. |
| Sidebar nav | Bottom tab bar — **max 5 tabs**; overflow goes to a "More" screen |
| Sidebar brand / logo | Logo in the home screen header only, not on every tab |
| Sidebar user chip | A **Profile** tab (last tab), showing the same name and role |
| Canvas (`--cr-tint`) | Screen background is `--cr-tint`, full bleed, no radius |
| Cards on canvas | Same cards: `--cr-surface`, radius 12, full width minus 16px margins |
| Canvas header title | Native stack header, title **not** uppercase, `--cr-surface` background |
| Canvas header actions | One header-right button, or a bottom action bar |

### Mobile rules

- Minimum touch target **44x44**. List rows are **56px** minimum, **64px** with secondary text.
- Screen horizontal padding: **16px**. Vertical gap between cards: **12px**.
- Tab bar: `--cr-surface` background, active icon and label `--cr-blue`, inactive `--cr-ink-muted`.
- Status pills use the exact same status-to-color table from section 1.
- Register tokens in `cargoApp/src/constants/theme.ts` alongside the existing `Colors` export.
  Dark mode is **not in scope for v1** — ship light only, but keep the existing dark scaffolding.
- Do not use `expo-glass-effect` blur on brand surfaces; it muddies `--cr-tint`.

---

## 7. Backend contract (`CargoApi`)

The API's job is to make the shell data-driven. Three contracts matter.

### 7.1 Envelope

Every JSON response uses the same shape. Clients never branch on endpoint-specific shapes.

```jsonc
// single resource
{ "data": { }, "meta": { } }

// list
{ "data": [ ], "meta": { "page": 1, "per_page": 25, "total": 132 } }

// error
{ "message": "Human readable.", "errors": { "field": ["reason"] } }
```

- HTTP status carries the outcome: `200` `201` `204` `401` `403` `404` `422` `500`.
- Timestamps: ISO-8601 UTC (`2026-08-22T07:45:39Z`). Clients localize; the API never formats dates.
- Money: integer minor units plus a `currency` string. Never floats.
- Weights and distances: base SI integers (`weight_kg`, `distance_m`), unformatted.
- Keys: `snake_case`. Enums: lowercase `snake_case` (matching the status table in section 1).
- Every resource exposes `id` (UUID or int, consistent per table) plus `created_at` / `updated_at`.

### 7.2 Identity endpoint — drives the user chip

`GET /api/v1/me` (auth:sanctum)

```jsonc
{
  "data": {
    "id": 1,
    "name": "Juan Dela Cruz",
    "role": "administrator",
    "role_label": "Administrator",
    "avatar_url": null,
    "permissions": ["shipments.view", "fleet.manage"]
  }
}
```

`role` is the machine enum, `role_label` is the display string (the client uppercases it),
`avatar_url: null` means the client renders initials.

### 7.3 Navigation endpoint — drives the sidebar and tab bar

`GET /api/v1/navigation` returns the nav the current user is allowed to see, already filtered by
permission. **Clients render whatever comes back**; they do not keep a hardcoded nav list.

```jsonc
{
  "data": [
    { "key": "dashboard", "label": "Dashboard", "icon": "gauge", "route": "/dashboard", "order": 10, "mobile": true },
    { "key": "shipments", "label": "Shipments", "icon": "box",   "route": "/shipments", "order": 20, "mobile": true, "badge": 4 },
    { "key": "fleet",     "label": "Fleet",     "icon": "truck", "route": "/fleet",     "order": 30, "mobile": true },
    { "key": "drivers",   "label": "Drivers",   "icon": "id",    "route": "/drivers",   "order": 40, "mobile": false },
    { "key": "reports",   "label": "Reports",   "icon": "chart", "route": "/reports",   "order": 50, "mobile": false }
  ]
}
```

- `icon` is a **name from the shared icon set**, never a URL or emoji. Each client keeps one
  `icon name -> component` map.
- `mobile: false` items are web-only; mobile drops them or lists them under "More".
- `badge` is an optional integer count; absent or `null` means no badge.
- Sort by `order`, then `label`.

### 7.4 Laravel conventions

- One **API Resource** per shape a client reads (`Domain/<Module>/Resources/`). The envelope is
  produced by `Domain/Shared/Http/Controllers/ApiController`, not by any controller.
- **Form Requests** for all writes; validation failures return `422` in the error shape above.
  One request class per module serves both store and update — `creating()` marks the difference,
  so the field list, its messages and its payload builder exist once.
- Controllers stay thin and resourceful (`index show store update destroy`). Anything that is not
  those five verbs gets a named route and a service method: `trips/{trip}/dispatch`,
  `billing/{invoice}/settle`, `drivers/{driver}/availability`. A verb that writes more than one
  table is never a status `PATCH`.
- Routes are versioned and grouped in `routes/api.php`:
  `Route::prefix('v1')->middleware('auth:sanctum')->group(...)`. `login` sits outside that group.
- Enums live in `Domain/Shared/Enums` and match section 1's vocabulary exactly.
- Migrations: plural snake_case tables, ULID primary keys on domain tables (a client holds an id
  as an opaque string), `foreignUlid()->constrained()`, and soft deletes on anything a user can
  "delete" from the UI.

**Derived figures are never columns.** Total expenses, net income, a customer's outstanding
balance, fuel spend and the month projection are all computed. Posting one is a `422`, not a
silently-honoured override, so a stored total can never disagree with its own parts.

**A trip's price is the one derived figure that *is* stored, on purpose.** It is
quoted from the tariff at booking and then frozen, because it is a promise made
to a customer at a moment in time — recomputing it on read would mean an
invoice quietly changing after it was sent, the day a rate is corrected. The
ledger row and the receivable both copy it rather than re-deriving it, so all
three are the same number. Sending one is a negotiated rate, not an override of
a total, and is honoured.

**A DTO knows what it was not told.** `Data::persistable()` returns only the keys the caller
actually sent. On a create that lets column defaults stand; on a `PATCH` it means sending
`helper_id: null` really does clear the helper, while leaving it out leaves it alone. Without
that distinction one of those two cases is always broken.

### 7.5 Auth, both ways

`POST /api/v1/login` takes an optional `device_name`:

| Caller | Sends | Gets back | Then |
| --- | --- | --- | --- |
| `cargoApp` | `device_name` | `meta.token` | Bearer token on every call |
| `CargoUI` | no `device_name` | session cookie | `withCredentials` + `X-XSRF-TOKEN` on every call |

`data` is the same `MeResource` either way — the shape of `data` must not change with the auth
style, which is why the token rides in `meta`.

The SPA flow needs three things configured together, and it fails confusingly if any one is
missing: `SANCTUM_STATEFUL_DOMAINS` must list the web app's host, `config/cors.php` must name that
origin (a wildcard is not allowed on a credentialed request) with `supports_credentials`, and the
client must call `/sanctum/csrf-cookie` before its first write.

On the web, `authGuard` resolves `GET /me` before any module route and sends an unauthenticated
visitor to `/login` with `?next=` set. A 401 mid-session does the same through an interceptor —
the session lives in an httpOnly cookie the client cannot inspect, so using it is the only honest
way to know whether it is still good.

### 7.6 Endpoints

| Group | Route | Notes |
| --- | --- | --- |
| Identity | `POST login`, `POST logout`, `GET me`, `GET navigation` | `?client=mobile` switches the nav to the handset tabs, filtered by permission so a driver and a customer get different ones |
| Dashboard | `GET dashboard/{kpis,fleet,deliveries,activity,receivables}` | Five calls, so a slow aggregate cannot hold up the tiles that were ready |
| Trips | `apiResource trips` + `{trip}/confirm`, `{trip}/dispatch`, `{trip}/complete` | `confirm` names the crew, the unit and the time; `assigned` follows from them |
| Driver-scoped | `GET trips/{current,pending,upcoming,cargo}` | No id in the path — scoped to the token |
| Customer-scoped | `GET portal/{summary,requests,invoices}`, `POST portal/requests`, `GET portal/requests/{trip}` | No customer id in any path — scoped to the token, exactly as the driver routes are |
| GPS | `GET gps`, `POST gps/pings`, `GET gps/trips/{trip}/tracking` | Handset writes, back office reads |
| Dispatch | `GET dispatch`, `POST dispatch/{dispatch}/arrive` | Records are born with a trip |
| Delivery | `GET delivery-logs`, `GET delivery-logs/report`, `POST {delivery}/proof` | Proof is multipart; the `POD-` reference is assigned, never sent |
| Vehicles | `apiResource vehicles` + `{vehicle}/status`, `{vehicle}/maintenance` | |
| Drivers | `apiResource drivers` + `{driver}/availability` | |
| Fuel | `apiResource fuel` + `GET fuel/budget` | Spend and projection are summed, not stored |
| Finance | `GET finance/{trucks,routes,profitability,summary}`, `ledger` CRUD | Profitability and summary are one roll-up, two ranges |
| Customers | `apiResource customers` + `{customer}/history` | Creating a customer creates its portal login (`role=customer`, the configured starting password); the create response says that password once and no read repeats it |
| Billing | `apiResource billing` + `{invoice}/settle`, `GET billing/totals` | Settling writes `paid`; a delivery raises its own receivable |
| Support | `apiResource incidents`, `GET notifications` + read / read-all | |
| Inspection | `GET inspections/checklist`, `GET/POST inspections`, `.../maintenance` | Mobile-only capture |
---

## 8. Components (shared spec)

Build these once per client. Same names, same states, both platforms.

| Component | Spec |
| --- | --- |
| `Button/primary` | `--cr-blue` background, white text, 40px tall (mobile 48), radius 8, 16px padding |
| `Button/secondary` | `--cr-surface` background, `--cr-blue` text, 1px `--cr-blue` border |
| `Button/ghost` | Transparent, `--cr-ink` text, hover `--cr-tint` |
| `Button/danger` | `--cr-red` background, white text |
| `Card` | `--cr-surface`, radius 12, shadow-card, 16px padding |
| `KpiTile` | Card plus 10px uppercase muted label, 28px tabular value, optional delta in success/red |
| `StatusPill` | radius-full, 10px/600 uppercase, colors per section 1, 8px x 2px padding |
| `Table` | `--cr-surface`, 12px uppercase muted headers, 48px rows, `--cr-line` rules, hover `--cr-tint` |
| `Input` | 40px tall, radius 8, 1px `--cr-line` border, focus 2px `--cr-blue` ring |
| `Modal` | `--cr-surface`, radius 16, shell-colored scrim at 50%. See 8.1 — never hand-rolled. |
| `LocationField` | Text input for the place name plus a `Map` button. Pinning fills the name; renaming keeps the pin. Both clients. |
| `MapPicker` | Leaflet on OpenStreetMap tiles, deferred so it is not in the initial bundle. Search or click to pin; the marker drags. Web: Leaflet directly. Mobile: the same page in a `WebView` (an `iframe` on the web build), because the native map libraries need a development build, a billing account, or both. Naming a pin is Nominatim on both, so a place is called the same thing whichever client pinned it. |
| `EmptyState` | Centered muted icon, 16px/600 title, 14px muted body, one primary action |
| `Toast` | Bottom-right (web) / top (mobile), `--cr-surface`, 4px left bar in the status color |

### 8.1 Dialogs — one modal, used everywhere

There is **one** modal implementation per client. No feature ships its own scrim, its own Escape
handler, or its own focus trap.

| Client | Component | Shape |
| --- | --- | --- |
| Web | `shared/modal.ts` (`<app-modal>`) | Centred panel, radius 16, `sm` 420 / `md` 560 / `lg` 820 |
| Mobile | `components/ui/sheet.tsx` (`<Sheet>`) | Bottom sheet, top corners radius 16, full width |

Both give you the same parts: scrim, header (optional icon + title + subtitle + close), scrolling
body, and a footer for actions. Both close on scrim tap and on Escape / back, unless `locked`
(web) is set while a request is in flight.

The web modal also handles what is easy to forget: focus moves into the panel on open and returns
to the trigger on close, Tab cycles inside the dialog, and the panel is `role="dialog"`
`aria-modal="true"` labelled by its title.

**Two ways to open one:**

- **Declarative** — the page owns the state: `<app-modal [(open)]="editing" title="…">`.
- **Centralised** — a service owns one shared instance, mounted once in the shell. Use this when
  more than one place opens the same dialog:

| Service | Mounted in shell as | Use |
| --- | --- | --- |
| `TripDialog` | `<app-trip-form />` | `tripDialog.create()` / `tripDialog.edit(trip)`; subscribe to `saved` / `deleted` to refresh a list |
| `Confirm` | `<app-confirm-host />` | `await confirm.ask({ title, body, danger: true })` returns a boolean |

**CRUD rules**

- One form component serves create **and** edit. Passing the record switches it to edit; the labels,
  validation and payload builder are written once.
- Every destructive action confirms first, through `Confirm` (web) or a `Sheet` (mobile). The
  confirm button carries the verb — "Delete trip", not "OK".
- A form in flight disables its own actions and sets `locked`, so a double submit is impossible.
- Fields use `<app-field>` for the label / hint / error triplet, so spacing and error announcement
  are identical everywhere.

Every list view must define all four states: **loading (skeleton) / empty / error / populated.**
Skeletons are `--cr-line` blocks — no spinners for full-page loads.

---

## 9. Accessibility

- Text contrast at least **4.5:1**. Verified pairs: `--cr-ink` on `--cr-tint`, `--cr-ink` on
  `--cr-surface`, white on `--cr-blue`, white on `--cr-red`.
- Never encode meaning in color alone — status pills always carry a text label.
- Visible focus everywhere: 2px `--cr-blue` ring with 2px offset.
- Web: landmark roles (`<nav>` for the sidebar, `<main>` for the canvas), skip-to-content link, full
  keyboard navigation.
- Mobile: `accessibilityLabel` on every icon-only control; respect OS font scaling up to 200%
  (layouts wrap, never clip).

---

## 10. Token reference

### Web — `CargoUI/src/styles.css`

```css
@import 'tailwindcss';

@theme {
  --color-cr-blue:       #15589C;
  --color-cr-blue-hover: #12497F;
  --color-cr-red:        #A11807;
  --color-cr-red-hover:  #851406;
  --color-cr-tint:       #DFF0FF;
  --color-cr-surface:    #FFFFFF;
  --color-cr-shell:      #1F1F1F;
  --color-cr-ink:        #1F1F1F;
  --color-cr-ink-muted:  #6B7280;
  --color-cr-line:       #E5E7EB;
  --color-cr-success:    #12805C;
  --color-cr-warning:    #B26A00;

  --radius-panel:   16px;
  --radius-card:    12px;
  --radius-control: 8px;

  /* Wordmark only — reached through `.cr-wordmark`, never directly. */
  --font-brand: 'Race Sport', 'Arial Black', Impact, sans-serif;
}
```

Usage: `bg-cr-tint`, `text-cr-ink-muted`, `rounded-panel`.

### Mobile — `cargoApp/src/constants/theme.ts`

```ts
export const Brand = {
  blue: '#15589C',
  blueHover: '#12497F',
  red: '#A11807',
  redHover: '#851406',
  tint: '#DFF0FF',
  surface: '#FFFFFF',
  shell: '#1F1F1F',
  ink: '#1F1F1F',
  inkMuted: '#6B7280',
  line: '#E5E7EB',
  success: '#12805C',
  warning: '#B26A00',
} as const;

export const Radius = { panel: 16, card: 12, control: 8, full: 9999 } as const;

/** Wordmark only — read by `components/ui/wordmark.tsx` and nothing else. */
export const BrandFont = 'Race Sport';
// Spacing already exists in this file — keep using it (4pt scale).
```

---

## 11. Checklist before merging a screen

- [ ] Uses only section 1 colors — no new hex values anywhere in the diff.
- [ ] Every spacing value is a multiple of 4.
- [ ] Page title uppercase (web) / sentence case (mobile stack header).
- [ ] Nav comes from `GET /api/v1/navigation`, not a hardcoded array.
- [ ] User name and role come from `GET /api/v1/me`.
- [ ] All four list states implemented (loading / empty / error / populated).
- [ ] Status shown as a `StatusPill` with a text label, colored per the section 1 table.
- [ ] Keyboard focus visible; icon-only controls have accessible labels.
- [ ] API responses use the section 7.1 envelope with `snake_case` keys.
- [ ] The screen lives in `pages/<module>/`, its fetches in `services/<module>/`, and its
      types in `models/<module>/` — section 5A. No page calls `HttpClient` or `fetch` directly.
- [ ] No derived figure is posted. Totals, net income and balances are the API's to compute.
- [ ] The brand face appears only in the wordmark component. Body copy is Inter / system sans.
