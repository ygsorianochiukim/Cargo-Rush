# Cargo Rush — User Manual

How to set the system up and run the business from it.

The system starts with **the menus and a set of sign-in accounts, and nothing else**. Every
vehicle, driver, customer and peso in it is one somebody entered — none of it is invented. This
guide goes in the order that works: the things other things depend on, first.

---

## 1. Getting in

### The accounts you start with

Setting the system up creates three accounts, one per role, so every part of it can be opened and
checked from day one:

| Email | Role |
| --- | --- |
| `admin@cargorush.ph` | Administrator |
| `accounts@cargorush.ph` | Accountant |
| `marco@cargorush.ph` | Driver |

They all share the password set in the server configuration as `SEED_PASSWORD`.

> **These are starting accounts, not permanent ones.** Change the passwords, and give each person
> their own account, before anybody relies on the system. A shared login means you cannot tell who
> recorded what.

### Adding real accounts

On the machine running the system:

```
php artisan cargo:user
```

It asks for a name, an email address, a role, and a password. The password is typed rather than
passed as an option, so it does not end up in the command history.

Creating a **driver** also asks for their licence number and expiry, because a driver account
without a driver record signs in fine and then has nothing to show.

Creating a **customer** asks which company the account acts for, offering the
ones already on file and letting you add a new one on the spot. The same
reasoning: every screen a customer sees is scoped to that company, so an
account with none signs in and finds nothing. Two people at the same firm can
each have an account and will see the same deliveries and the same invoices.

For customers, this is the second way round rather than the usual one: adding
the firm in **Customer Management** already creates its account (section 2,
step 3). Use this command to give a *second* person at the same firm their own
login, or to choose the password yourself rather than take the starting one.

### Signing in

Open the back office and sign in with that email and password. If the password is wrong the
screen says so; if it says it cannot reach the server, the system is not running rather than the
password being wrong. They are different problems and the screen tells you which.

### The five roles

| Role | Can reach |
| --- | --- |
| **Administrator** | Everything |
| **Dispatcher** | Trips, GPS, dispatch, deliveries, vehicles, drivers, incidents |
| **Accountant** | Finance, fuel, customers, billing |
| **Driver** | The driver app only |
| **Customer** | The customer app only — their own deliveries and their own invoices |

The sidebar shows what the role is allowed to open, so a dispatcher does not see a Billing menu
they cannot use.

**A customer is not a cut-down member of staff.** They reach a different set of
screens entirely, and every one of them is scoped to their own company: they
can see the deliveries they asked for and the invoices raised against them, and
nothing about anybody else's. There is no setting that widens this.

---

## 2. Setting up — in this order

Each step depends on the one before it. Doing them out of order means going back.

### Step 1 — Drivers and helpers

**Drivers Management → New driver**

Add everyone who drives, **and everyone who rides along as a helper**. They live in the same list:
a helper is a driver record without the keys. There is no separate helper screen to look for.

| Field | What to put in it |
| --- | --- |
| Full name | As it appears on the licence |
| Licence number | Exactly as printed |
| Licence expiry | The system warns you 90 days out |
| LTMS violations | On record. Leave at 0 if none. |
| Status | **Available** means free to be assigned a trip |

### Step 2 — Vehicles

**Vehicle Management → New vehicle**

| Field | What to put in it |
| --- | --- |
| Plate number | As registered |
| Model | e.g. Isuzu Forward |
| Registration number | The LTO reference |
| Capacity (kg) | Maximum load |
| Odometer (km) | The reading **today** |
| Next service at (km) | The reading at which it is next due |
| Assigned driver | Who holds the keys. Leave empty if unassigned. |

Get the odometer right at this point. Everything about service intervals and fuel consumption is
measured from it.

### Step 3 — Customers

**Customer Management → New customer**

Name and contact are all that is needed. Trip count and outstanding balance are **not** entered —
the system works those out from the trips and invoices you record, so they can never disagree with
the Billing screen.

**Adding a customer also gives them a way in.** If the contact is an email
address — or you type one in **Portal login** — the customer gets an account on
the phone app with it, created with the starting password set in the server
configuration as `CUSTOMER_DEFAULT_PASSWORD`. The screen says the address and
that password once, in the notice above the list, so you can pass them on. It
is not shown again.

Leave **Portal login** blank and put a phone number in the contact for a
customer who is not to book their own deliveries; they are still a customer and
still get billed, they simply have no account. Give them one later by editing
them and typing an address — the **Portal login** column says who has one.

> The starting password is the same for every customer, so it is a password to
> hand over and have changed, not one to leave standing. A customer who has
> changed theirs keeps it: editing them again never resets it.

### Step 4 — Ledger units

**Trip Monitoring → Add unit**

These are what the workbook keeps a sheet per: *Truck 1*, *Truck 2*, and so on. Name them the way
the workbook names them, so the two can be compared during the parallel run.

A unit with no plate yet is fine — enter the name, leave the plate empty. It still gets a sheet and
still counts in every total.

Linking a unit to a vehicle is optional. The link is useful; the unit works without it.

---

## 3. Running a trip

### Where a trip comes from

Two places, and they arrive in different states.

**The office books it** — the form below — and names the crew and the unit as
it goes, so it starts life *Scheduled* or *Assigned*.

**A customer asks for it** from the customer app. That arrives as **Pending**,
which means exactly one thing: *nobody has decided about this yet*. It has a
route, a load and a weight, and no driver, no vehicle and no agreed time,
because a customer has no way to know any of those.

### Confirming a request

**Trip Management** — requests sit in a **Delivery requests** panel above the
board, oldest first, with the price the customer was already quoted.

Press **Confirm** and fill in the four things only you know: the driver, the
helper (optional), the vehicle, and when it is actually going out. The time is
pre-filled with what the customer asked for, because agreeing to it is the
usual answer.

Confirming moves it to **Assigned**, and the driver is told. That is the whole
of the office's job on a request: **you do not type a price, an income figure
or an invoice** — those follow on their own, and section 4 explains from what.

You can correct the weight while confirming. Do, if the customer's estimate was
off: the price is worked out again from it. If you have negotiated a rate
instead, type it and the system leaves it alone.

**A request cannot be started until it is confirmed.** A driver who opens the
app will not see it, and the system refuses if they somehow try. That is the
point of the step: *Assigned* means a real driver on a real unit at a real
time, and nothing else can be handed to a cab.

### Booking it

**Trip Management → New trip**, or the **New trip** button in the top bar from anywhere.

Origin, destination, cargo, weight, driver, vehicle, and when it is scheduled. A helper is
optional — but it cannot be the same person as the driver, and the system will say so.

**Origin and destination take a place name, and optionally a point on a map.**

Type the name if you know it — that is all a trip needs, and it is the quickest path for a
booking taken over the phone. Press **Map** when you want the exact spot:

- **Search** for a town, depot or landmark and pick it from the list.
- **Click the map** to drop a pin anywhere, including a gate with no address. The system looks
  up a name for it.
- **Drag the pin** to correct it. The name stays — nudging a pin onto the right gate is
  correcting the point, not choosing a different place.

Renaming the field afterwards keeps the pin, so "Poblacion" can become "Ozamis depot" without
losing the location.

**Pin both ends and the distance fills itself in** — straight-line, not road distance, so treat
it as a floor. If you know the real road distance, enter it and the system leaves it alone.

**You do not enter a reference.** The system assigns it (`CR-24801`, `CR-24802`, …) so two people
booking at the same time cannot land on the same one.

If you set an ETA, it cannot be earlier than the departure time.

### Sending it out

Dispatching a trip records **when and where** it left and moves it to *In transit*. The Dispatch
Monitoring screen is that record.

### While it is running

**GPS Dashboard** shows every unit on the road: where it is, how fast, how far along, and its ETA.
That comes from the driver's phone — the back office reads it and never enters it.

### Closing it out

Completing a trip does **five** things at once:

1. closes the trip,
2. closes the dispatch record,
3. files the delivery log with its proof of delivery,
4. credits the driver with a completed trip,
5. puts the run's income on the Trip Monitoring sheet and raises the customer's
   invoice.

That is why it is a single action rather than five status changes — five could
be done half-way, and the last two are the ones that used to be somebody's job
to remember.

**It happens once.** Whoever closes the run — the driver from the cab, or the
office from Trip Management — the money moves once and once only. Pressing
Complete on a run that is already delivered is refused rather than repeated.

**Proof of delivery is a photograph and a name.** The driver takes a picture of
the load where they left it and types who signed for it. **You do not enter a
proof-of-delivery reference and neither does the driver** — the system assigns
it (`POD-00001`, `POD-00002`, …), the same way it assigns a trip reference. It
used to be a field on the driver's form, which meant the number came from
whoever was standing at the door, and two runs could carry the same one.

A photograph is optional. Signal at a warehouse gate is what it is, and a
delivery that cannot be closed for want of an upload leaves a driver stuck. The
name is not optional: it is what makes the delivery attributable to a person.

---

## 4. Recording the day's money

This is the part that replaces the workbook, and the part worth being careful with.

### The income fills itself in

**You no longer type trip income.** Every trip is priced when it is booked,
from a tariff:

```
price = base fare + (rate per km × distance) + (rate per kg × weight)
```

floored at a minimum charge. The customer is shown that figure the moment they
ask for the pickup, and it is what the office sees on the request before
confirming it.

When the run is delivered, that same figure is added to the day's row for the
unit and billed to the customer. **One number, in three places, that cannot
disagree** — the sheet, the invoice, and what the customer was told.

A unit that runs three hauls in a day still keeps one row, as the workbook does,
and the day is worth all three: each delivery adds its fare to the row rather
than replacing it. Anything you have already typed into that row is added to,
never overwritten.

The rates are yours to set. They live in the server configuration
(`TARIFF_BASE_CENTS`, `TARIFF_PER_KM_CENTS`, `TARIFF_PER_KG_CENTS`,
`TARIFF_MINIMUM_CENTS`) so a bookkeeper can correct them without a developer.
Get them right before the first real booking: changing them later does not
re-price trips already quoted, and it should not — those are prices customers
were promised.

**Distance matters to the price.** A trip nobody has pinned on a map has no
distance, and is charged on the base fare and the weight alone — less than the
haul is worth. Pin both ends, or type the road distance, and the quote is
right. See "Booking it".

**A negotiated rate overrules the tariff.** Type a price on the booking or
confirmation form and the system keeps it exactly, including a deliberate zero
— which is how you book the company's own freight.

### If you were already running trips

Trips booked before the tariff existed carry no price, so they would show ₱0,
credit nothing to the sheet when delivered, and raise no invoice. On the
machine running the system:

```
php artisan cargo:trips-quote --dry-run
```

It lists what it would charge for each, and flags any trip with no distance —
those are quoted on the base fare and the weight alone, so pin both ends on the
map first if you want a real figure. Re-run without `--dry-run` to apply.

It leaves alone anything already priced, and anything already delivered and
invoiced: repricing those would leave the trip disagreeing with a document a
customer is holding.

### Recording the expenses

**Trip Monitoring** → pick the unit's tab → **Record daily trip**

Or the driver records it from the cab, on their phone, at the end of the run. Same row either way.

| You enter | Worked out for you |
| --- | --- |
| Fuel | ~~Trip income~~ — from the tariff, when the run is delivered |
| Driver salary | ~~Total expenses~~ |
| Helper salary | ~~Net income~~ |
| Maintenance | |
| Allowance | |
| Route, remarks | |

Trip income is still on the form, and you can correct it — a haul that was
re-negotiated after the fact, or a figure being transcribed from the old
workbook. What has changed is that you no longer have to.

A trip knows what it was charged. It has no idea what the fuel, the salaries or
the maintenance came to, so those stay yours to enter.

**Total expenses and net income are worked out as you type.** They are shown live above the form so
you can check the row before saving it. They are never typed, and the system rejects an attempt to
send one — that is what stops a total from drifting away from the figures it is made of.

Amounts are entered in pesos, the ordinary way: type `30721` for ₱30,721.00.

### Reading the money back

| Screen | Shows |
| --- | --- |
| **Trip Monitoring** | One unit's daily rows, with that sheet's totals across the top |
| **Profitability** | A 10-day window: income, expenses and net per unit, best performer, expense split |
| **Quarterly Summary** | The same roll-up over a quarter |

Profitability and Quarterly Summary are the **same calculation** over different date ranges, so
they cannot disagree with each other.

### The charts

Every chart responds to a pointer or the keyboard. Hover a bar, a slice or a row and it shows its
figures — on the expenses chart, the five columns the total is made of; on net income, the income
and expenses behind it.

Tab moves between them and shows the same thing, so nothing here needs a mouse.

**Reading net income:** the centre line is zero. A profit runs right, a loss runs left in red.
A loss is never drawn as a short bar.

---

## 5. Fuel

**Fuel Expense → Log fuel** after each fill-up: vehicle, litres, amount, odometer reading, receipt
number.

The odometer reading matters. Recording a fill moves the vehicle's odometer forward — but only
forward, so a mis-typed low reading cannot wind a truck backwards.

At the top of the screen:

- **Daily budget** — what was set for today
- **Spent today** — added up from the receipts, not entered separately
- **Projection** — month-end spend at the current rate
- **Open requests** — fills still marked *Pending*

A **Cancelled** fill is not spend and never enters a total.

---

## 6. Billing

### Deliveries invoice themselves

**You do not raise an invoice for a completed delivery.** When a run is
delivered, a receivable is raised against its customer for the price the trip
was quoted at, due on the terms set in the server configuration
(`BILLING_TERMS_DAYS`, thirty days out of the box).

Each haul raises exactly one document, whoever closed the run and however many
times the button is pressed. The invoice carries the trip's reference, so
reconciling it against the delivery is reading one column rather than matching
dates and amounts by eye.

Two cases raise nothing, and both are deliberate:

- **A run with no customer** — the company's own freight. There is nobody to
  bill, and an invoice addressed to nobody is worse than none.
- **A run priced at zero** — somebody explicitly said it was free.

The delivery still goes through in both, and the income still reaches the
sheet.

### Raising one by hand

**Billing & Invoice → New invoice**, for everything a delivery does not cover:
retainers, adjustments, and anything you owe somebody else.

Pick the direction first, because it changes what is required:

- **Receivable** — money in. Name the customer.
- **Payable** — money out. Name the payee.

A due date cannot precede the issue date.

### Getting paid

Marking an invoice settled is its own action, not a status you edit. It becomes
**Paid**, and the system records the date the money arrived — pressing it twice
does not move that date.

*Paid* is a status of its own, and that matters more than it looks. Settling
used to write *Delivered*, the same word a closed-out haul carries, so nothing
could add up money that had actually arrived without also counting every
delivered trip. That is why the Dashboard can now separate the two.

Anything still pending past its due date becomes **Overdue** on its own — the
system works that out from the date rather than waiting for somebody to change
it. That sweep runs on the schedule; see "Before going live".

### Reading it back

**Billing & Invoice** leads with four figures: outstanding receivables,
collected, outstanding payables, and overdue.

**Dashboard → Receivables** is the same money seen from a distance: what is
pending against what has been successfully paid, the share between them, and —
separately — what the fleet actually earned over the last thirty days.

Those last two are deliberately not the same number. A run delivered on the
last day of the month is income now and cash in thirty days. A dashboard that
merged them would call a good month a cash-flow problem, or the reverse.

---

## 7. Incidents

**Incident Management → Report incident**: what happened, where, when, and who was involved.

The reference (`INC-0231`) is assigned by the system. Reporting an incident also raises a
notification, so it does not sit unseen in a list.

An incident cannot be reported for a future time.

---

## 8. The apps on a phone

**One app, two products.** `cargoApp` is what both drivers and customers
install. Which screens it opens on follows from the account signing in, and
from nothing else — there is no build to choose, no setting to flip and no way
for either to reach the other's screens.

### 8.1 The driver app

#### Signing in

Drivers sign in with the account the office created for them — the same email and password. They
stay signed in between shifts; the phone remembers it securely.

A driver account only works in the driver app. There is nothing for them to reach in the back
office, and nothing in the back office they can open.

#### The five tabs

| Tab | What they do there |
| --- | --- |
| **Dashboard** | Availability switch, current run, what is confirmed and waiting, notifications |
| **Cargo** | What they are carrying, pickup and drop-off, ETA |
| **Tracking** | Starts and stops position reporting; shows distance covered and average speed |
| **Inspect** | Pre-trip checklist, and any maintenance assigned to their unit |
| **More** | Trip history, proof of delivery, licence details, sign out |

#### Reporting position

On the **Tracking** tab, press **Start reporting** when setting off. Dispatch then sees the unit
on the GPS Dashboard, and the driver sees their own speed and progress.

- It reports **every minute, or every 300 metres**, whichever comes first. That is enough for the
  office to follow a run without flattening the phone on a ten-hour day.
- It keeps reporting with **the screen off and the phone in its cradle**. Android shows a notice
  while it is on, so it is never running invisibly.
- **No signal is fine.** Readings are held and sent when the phone reconnects, stamped with the
  time they were taken — so a dead spot shows as the route actually driven, not as an hour parked.
  The tab shows how many are waiting.
- Press **Stop reporting** at the end of the run. It does not stop on its own.

The first time, the phone asks for location permission. Choose **Allow all the time** — "only while
using the app" stops reporting the moment the screen locks, which is most of a trip.

#### The pre-trip check

Work through the checklist and submit it. **The system decides whether the unit is good to go**,
not the app and not the driver: every item has to be answered, and tyres, brakes, lights and
documents all have to pass. A half-filled checklist does not clear a truck, and a pass cannot be
submitted over a failed brake check.

A failure raises a notification for the office straight away.

#### Recording the day

The driver records the day's income and expenses from the Dashboard. It writes **the same row**
the office reads in Trip Monitoring — there is no separate driver ledger to reconcile.

#### Handing a load over

On the **Dashboard**, a run that is on the road shows **Mark delivered**. The
driver is asked for two things:

- **A photo of the load** where they left it — from the camera or the gallery.
  The phone asks for permission the first time. This is optional: if there is
  no signal at the gate, or the camera is refused, the delivery still closes.
- **Who received it** — typed. This is required. It is the signature, and it is
  what makes the delivery attributable to a person.

**No reference to type.** The system assigns `POD-00001`, `POD-00002` and so
on. The driver could never have known that number, and asking for it only ever
produced invented ones.

Handing over closes the run, files the proof, credits the driver, puts the fare
on the day's sheet and invoices the customer — see section 3.

### 8.2 The customer app

#### Signing in

Customers sign in on the same app the drivers use, with the account made when
the office added them in Customer Management — the address and starting
password the screen showed at the time. The app sees the role and opens on
their screens instead.

The first thing to have them do is change that password: until they do, it is
the one every new customer starts with.

A customer account only works here. There is nothing for them in the back
office, and no driver screen they can reach.

#### The five tabs

| Tab | What they do there |
| --- | --- |
| **Home** | What is awaiting confirmation, what is booked, what they owe against what they have paid |
| **Request** | Ask for a pickup |
| **Deliveries** | Every delivery they have asked for, and where each one is |
| **Invoices** | What is owed, what is paid, and which delivery each document is for |
| **More** | Account details, sign out |

#### Asking for a pickup

**Request** takes five things: where it is going from, where to, what is being
moved, what it weighs, and roughly when — today, tomorrow, in three days, or
next week.

Nothing else, because nothing else is theirs to say. The driver, the helper,
the vehicle and the actual departure time are the office's, and are filled in
when the request is confirmed.

**Both ends can be pinned on a map**, the same way a trip is booked in the back
office. Beside each address is a **Map** button; it opens the map with three
ways to the same answer:

- **Search** for the town, depot or landmark by name.
- **Use my current location** — for the customer standing in the yard the load
  is in. The phone asks permission the first time.
- **Tap the map**, then drag the pin onto the exact gate. This is the one that
  works for a depot with no address, which is most of them.

Whatever they use, the pin is looked up and **named** on the spot, and the name
fills the address in if it is still empty. A name already typed is kept —
"Ozamis depot" beats "Poblacion" — because renaming a pin is labelling the
point, not moving it. Under the field, the coordinates show what was actually
tagged.

Pinning is optional and worth doing: the price is worked out from the distance
between the two pins, so a pinned request is quoted on the run it really is
rather than on the base rate and the weight alone. It also puts the exact spot
on the driver's screen instead of a town name. A request with no pins still
files, still prices and still books.

The screen answers with the trip's reference and **the price**, straight away.
That is the difference between a request and a hopeful message: they know what
it costs before anybody rings them back.

The request sits at **Pending** until the office confirms it. On **Deliveries**
each status is spelled out in plain English — "waiting for the office to
confirm a driver and a time", "on the road now" — because *Assigned* means
something precise to a dispatcher and nothing at all to a customer.

#### What they can see

Their own deliveries and their own invoices, and nothing else. Not the fleet,
not another firm's work, not a driver's whereabouts beyond the status of their
own load. Two people at the same company see the same list.

---

## 9. Things worth knowing

**Deleting asks first, always.** The confirmation says what is being deleted and what happens to
records already filed against it.

**A ledger unit with entries cannot be deleted.** Removing it would take the money with it, and a
period that used to balance would quietly stop. Delete the entries first, or leave the unit alone.

**Statuses mean the same thing everywhere, and two of them changed.** *Delivered*
on a trip and on an incident still means closed out. But:

- **Pending** now means *nobody has decided about this yet* — a delivery
  somebody asked for and the office has not confirmed. It used to mean work
  waiting for a driver, which is now **Assigned**.
- **Paid** is what a settled invoice becomes. It used to write *Delivered*,
  which made money that had arrived indistinguishable from a haul that had.

Green is healthy, blue is in progress, amber needs attention, red is a problem —
and every status carries its word as well as its colour.

**Nothing is formatted by the server.** Dates and times show in your own timezone, which is why the
same trip may read differently on a phone set to another one.

---

## 10. When something looks wrong

| What you see | What it usually is |
| --- | --- |
| "Cannot reach the server" | The system is not running, or the network is down. Not a wrong password — the screen names the address it tried. |
| The **phone** cannot reach it but the **browser** can | The server is only listening to itself. It has to be started with `--host=0.0.0.0` for a handset on the same Wi-Fi to see it. |
| Signed out unexpectedly | The session expired. Sign in again — you land back where you were. |
| A driver sees no trips | Nothing *confirmed* to that driver — an unconfirmed request does not appear in the cab, by design. Or their account is not linked to a driver record. Check both on Trip Management and Drivers Management. |
| A customer sees nothing at all | Their account is not linked to a company. Recreate it with `php artisan cargo:user --role=customer`, which asks which firm it acts for. |
| A customer has no way in | They were added with a phone number and no **Portal login**, so no account was ever made. Edit them, type an address, and the notice above the list gives you the credentials to pass on. |
| A customer says the password does not work | It is the starting one only until they change it. If they have changed it and forgotten it, `php artisan cargo:user --role=customer` gives that firm a second account with a password you choose. |
| A request will not start from the phone | It has not been confirmed. Confirm it from Trip Management and the driver's Start button appears. |
| Proof-of-delivery photos do not load | `php artisan storage:link` has not been run on this install. Run it once; existing deliveries pick up their pictures immediately. |
| A delivery earned nothing on the sheet | It had no vehicle assigned, so there is no unit sheet to file against. Assign one before the run goes out. |
| A delivered run was never invoiced | It has no customer, or its price is zero. Both are legitimate — the company's own freight — so nothing is raised. |
| A trip is priced lower than it should be | It has no distance. Pin both ends on the map, or type the road distance, and the quote is worked out again — as long as it has not been delivered yet. |
| The fleet total looks low | Only vehicles entered into the system count. A truck that has not been added is not in the fleet. |

---

## 11. Before going live

- [ ] Every vehicle entered, with today's odometer reading
- [ ] Every driver **and helper** entered, with licence expiry
- [ ] Every customer entered
- [ ] Ledger units named the way the workbook names them
- [ ] **The tariff rates set to the business's real ones**, before the first
      booking is quoted — they are what every price, every ledger row and every
      invoice is worked out from
- [ ] `php artisan cargo:trips-quote` run, if there were already trips on the
      system before the tariff existed
- [ ] `php artisan storage:link` run, so proof-of-delivery photographs resolve
- [ ] The scheduler running (`* * * * * php artisan schedule:run`), so
      scheduled work is released, late trips go overdue, and unpaid invoices
      past their due date go overdue on their own
- [ ] One account per person, with their own password — not one shared login
- [ ] The three starting accounts renamed, given new passwords, or removed
- [ ] The seeded driver record given its real licence number and expiry
- [ ] Drivers signed in on their own phones and able to see their trips
- [ ] `CUSTOMER_DEFAULT_PASSWORD` set to something of this install's own, since
      it is the password every new customer starts with
- [ ] Any customer who is to book their own deliveries given an account, and
      signed in on their phone — and their starting password changed
- [ ] One request filed, confirmed, driven and delivered end to end — and the
      resulting invoice checked against what the customer was quoted
- [ ] The workbook still running alongside for one full cycle
- [ ] The two compared at the end of that cycle, and reconciled
