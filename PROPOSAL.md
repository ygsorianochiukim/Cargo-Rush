# Cargo Rush — Fleet Management System

**A proposal for replacing the spreadsheet.**

---

## 1. Where the business is now

Cargo Rush runs its fleet out of a workbook: `v3 Cargorush Master Dashboard 2026.xlsx`, one sheet
per truck, a row per day, and a dashboard that sums it all up.

The workbook works. That is worth saying plainly, because a proposal that opens by calling the
current system broken usually ends up rebuilding it badly. The formulas in it are correct, the
figures reconcile, and the people using it know exactly where everything is.

What it cannot do is keep up with more than one person at a time.

| The workbook | What that costs |
| --- | --- |
| One file, one editor | Two people cannot record two trucks at once |
| Filled in after the fact | A day's figures are entered from memory or a scrap of paper, hours later |
| No live position | "Where is CR-24817?" is a phone call |
| Trips, fuel and billing kept apart | A customer's outstanding balance is worked out by hand |
| No driver access | Proof of delivery arrives when the driver gets back to the depot |

The proposal is **not** to change how the business thinks about its money. It is to keep the
workbook's arithmetic exactly as it is, and put it somewhere several people can reach at once —
including the driver in the cab.

---

## 2. What is being delivered

Three applications against one system.

| Part | Who uses it | What it is |
| --- | --- | --- |
| **Back office** | Operations, accounts | A web dashboard — fifteen modules |
| **Driver app** | Drivers, in the cab | Five tabs, on their own phone |
| **The system** | Neither, directly | The single source of truth both read from |

These are **different products against one system**, not the same app at two sizes. An operations
controller sits at a desk and runs the whole business; a driver holds a phone one-handed and works
one trip at a time. Handing the driver a shrunken dashboard would be handing them something they
cannot use while driving.

### 2.1 The back office

| Group | Module | What it is for |
| --- | --- | --- |
| **Operations** | Dashboard | The consolidated view: active trips, on-time rate, fleet utilisation, open incidents |
| | GPS Dashboard | Where every unit is now, and when it is expected |
| | Trip Management | Booking a trip: route, cargo, driver, helper, vehicle, schedule |
| | Dispatch Monitoring | When and where each unit left, and when it arrived |
| | Delivery Logs | The closed-out record, with proof of delivery |
| **Assets** | Vehicle Management | Registration, capacity, odometer, service intervals |
| | Drivers Management | Licences, expiry, LTMS violations, availability |
| | Fuel Expense | Daily budget, receipts, odometer, consumption and projection |
| **Finance** | Trip Monitoring | The workbook's per-truck sheets, one tab per unit |
| | Profitability | The 10-day dashboard: income, expenses and net per unit, best performer |
| | Quarterly Summary | The same roll-up over a quarter |
| **Business** | Customer Management | Records, transaction history, feedback |
| | Billing & Invoice | Receivables, payables, payment history |
| **Support** | Incident Management | What happened, where, and when |
| | Notifications | Incidents, licences expiring, services due, overdue invoices |

### 2.2 The driver app

| Tab | What a driver does with it |
| --- | --- |
| **Dashboard** | Sets availability, sees the current run and what is queued behind it |
| **Cargo** | The cargo, the pickup and drop-off, the ETA |
| **Tracking** | Reports position; sees distance covered and average speed |
| **Inspect** | Pre-trip checklist with an assisted good-to-go call, plus assigned maintenance |
| **More** | Trip history, proof of delivery, licence and account details |

The driver also records the day's income and expenses from the cab — the workbook's "record the
daily trip income and expenses in each truck" step, done where the run actually happened rather
than from memory that evening.

---

## 3. What is deliberately not in scope

Being clear about this now is cheaper than discovering it in week six.

| Not included | Why |
| --- | --- |
| Live map tiles | Position is captured and shown as a named location with progress and ETA. Drawing it on a real map is a separate integration with its own licensing cost. |
| Payroll | Driver and helper salary are recorded as trip expenses. Payslips, deductions and government contributions are a different system. |
| Accounting export | Invoices and expenses are recorded and totalled. Filing them into a formal chart of accounts is not attempted. |
| Dedicated GPS hardware | The driver's phone is the position source. Tracker units can be added later against the same endpoints. |
| Dark mode | Light only for the first release. The groundwork is in place. |

---

## 4. How the money is handled

This is what the business will judge the system on, so it is worth stating exactly.

**The workbook's formulas are kept, unchanged:**

```
total_expenses = fuel + driver_salary + helper_salary + maintenance + allowance
net_income     = trip_income - total_expenses
net_share      = net_income / total_net_income
```

Four commitments follow from that:

1. **Totals are never typed.** Total expenses and net income are worked out from the five expense
   figures every time they are shown. Nobody can enter a total that disagrees with its own parts —
   the system refuses one outright rather than accepting it quietly.
2. **Money is whole centavos.** ₱30,721.00 is stored as `3072100`. There is no rounding drift,
   because there is nothing to round.
3. **A loss is a loss.** Net income shows as a negative with a minus sign, and the chart draws it
   left of the axis. A bad month is never flattened into a short bar.
4. **A unit with no plate is still a unit.** It gets a sheet and appears in every total. Nothing is
   quietly filtered out of the fleet.

These are verified against the source workbook by an automated test suite: the same figures the
workbook itself prints, re-checked on every change to the system.

---

## 5. Delivery

| Phase | What happens | Result |
| --- | --- | --- |
| **1 — Foundation** | Database, API, sign-in, roles | The system exists and can be signed into |
| **2 — Back office** | Fifteen modules, all entry, all reporting | Operations can run the business from it |
| **3 — Driver app** | Five tabs, sign-in, position reporting, inspections | Drivers are on the system |
| **4 — Real data** | Vehicles, drivers, customers, units and routes entered | The system holds the actual fleet |
| **5 — Parallel run** | System and workbook kept side by side | Figures confirmed to reconcile before the workbook is retired |

**Phase 5 is not optional.** The workbook should keep running alongside the system for at least one
full cycle. If the two disagree, that gets found while there is still something to check against —
not after the spreadsheet has been closed.

---

## 6. What the business has to supply

The system ships with **the menus and a set of sign-in accounts, and no business data**. That is
deliberate: it holds the real fleet, not a demonstration one. Nobody should have to explain why
their fleet contains a truck they have never owned.

So it can be opened on day one — and everything after that is the business's own:

- **The vehicles** — plate, model, registration, capacity, current odometer
- **The drivers and helpers** — name, licence number, expiry
- **The customers** — name and contact
- **The units** for the ledger, named the way the workbook names them
- **The routes** actually run

All of it is entered through the system's own screens. Nothing needs a developer, and no data is
loaded from a file the office cannot see. `INSTRUCTIONS.md` walks through it in order.

---

## 7. Risks, honestly

| Risk | How it is handled |
| --- | --- |
| **Drivers do not use the app** | It replaces the paper trip ticket rather than adding to it. Adoption should be checked in the first month, not assumed. |
| **No signal on the route** | Position readings are stamped when taken, not when sent, so a run through a dead spot records correctly once the phone reconnects. |
| **Figures disagree with the workbook** | The parallel run in phase 5 exists for exactly this. The formulas are tested against the workbook's own printed totals. |
| **Data entry is a large one-off effort** | It is. It is also the only way the system holds real records. Budget real time for it. |
| **The brand font is personal-use licensed** | The bundled Race Sport is the personal-use release. A commercial licence must be bought before public launch. Nothing else depends on it — the wordmark falls back cleanly. |

---

## 8. What success looks like

Not "the system is live". These:

- Two people record two trucks at the same time, on the day it happened.
- "Where is that unit?" is answered by looking, not by phoning.
- A customer's outstanding balance is read off a screen rather than added up.
- Proof of delivery is on the system before the driver gets back to the depot.
- The quarter closes from figures already entered, not from a week of catching up.
