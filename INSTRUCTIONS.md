# Cargo Rush — User Manual

How to set the system up and run the business from it.

The system starts with **the menus and a set of sign-in accounts, and nothing else**. Every
vehicle, driver, customer and peso in it is one somebody entered — none of it is invented. This
guide goes in the order that works: the things other things depend on, first.

---

## 1. Getting in

### Register your company first

**Nobody signs in until a company exists.** Open the back office and choose **Register your
company** under the sign-in form. It asks for two things at once:

| | What to put in it |
| --- | --- |
| **Company name** | Your trading name, as it appears on your own paperwork |
| Contact number, address | Optional. How to reach you about the account itself. |
| **Full name, email, password** | You. This becomes the first account. |

Pressing **Create company** does everything in one go: it makes the company, gives it its starting
roles, job titles and expense categories, creates your account as the **administrator**, and signs
you in. You land on the dashboard, not back at a login form.

You are the administrator because you are the only person there — somebody has to be able to
create the second account. You can hand the role on and step down later.

### Your company is your own

Everything you enter — every truck, driver, customer, trip, peso and payslip — belongs to your
company and **nobody outside it can see any of it**. Another haulier using the same system sees
none of your work, and you see none of theirs. There is no setting that widens this and no screen
that crosses it.

That has a few consequences worth knowing up front:

- **You can name things whatever you like.** Your "Truck 1" does not clash with anybody else's,
  and your trip references start at `CR-24801` regardless of how long the system has been running
  for other firms.
- **The roles are yours to rename and re-tick.** Calling your dispatchers "controllers" changes
  it for you and for nobody else. Same for job titles and expense categories.
- **An email address belongs to one account.** That is why signing in asks only for your address
  and password — the address already says which company to open. It also means one person cannot
  hold accounts at two companies with the same address.
- **The company name is in the sidebar**, under the Cargo Rush wordmark, so it is always clear
  whose system is on screen.

### Your logo

**Access Control → Company → Upload logo.** It appears in the sidebar beside your name, on every
screen, for everybody in the company.

Any ordinary image file works — PNG, JPEG, GIF or WebP. You do not have to size or crop it first:
whatever you upload is squared off from the middle and resized to the 64×64 the sidebar uses, so a
wide banner keeps its centre and a large file is not sent to everybody's browser on every page.

A few things worth knowing before you pick one:

- **Square images work best**, because the middle of anything else is what survives the crop. A
  wide lockup with the name beside the mark usually loses the name; the mark on its own is the
  one to upload.
- **A transparent background stays transparent.** Save it as a PNG if the mark is not on a solid
  colour.
- **It is small on screen.** It renders at about the size of a fingernail, so anything with fine
  text in it will not read. Look at it in the sidebar before deciding it is right.
- **Replacing it is just uploading again.** The old one is removed for you.
- **No logo is fine.** The sidebar shows your initials instead — "SF" for Southern Freight — and
  **Remove** puts you back to that.

Only an **administrator** or a **general manager** can change it. Everybody else sees it and
cannot touch it.

### Your rates

**Access Control → Rates.** Everything the business charges, keeps or pays tax on is set here, in
one card, and nowhere else. It opens with sensible figures already in it, so you can leave it
alone until something is wrong.

| | What it is |
| --- | --- |
| **Commission %** | What you keep of a run an owner-operator hauls for you. **12% to start with.** |
| **Tariff** | Base + per km + per kg, never below the minimum. What a run is quoted when no rate-card band covers it — see [Pricing](#6-billing) for the bands themselves. |
| **VAT-registered** | Untick if you are below the registration threshold. Your invoices then carry no VAT line at all. |
| **VAT %** | 12% — the standard rate, and it has moved before. |
| **Withholding %** | 2%, what a customer who is a withholding agent keeps back. A customer with its own rate on file keeps that instead. |
| **Payment terms** | How many days a delivered run has to pay. 30 to start with. |
| **Prices are quoted all-in** | Tick it if you quote one figure with the VAT already inside. The net is then worked backwards, so a customer is billed exactly what you quoted them. |

Three things are worth knowing before you change any of them:

- **Nothing already settled moves.** An invoice freezes the tax rates it was raised under, and a
  delivered run freezes the commission it closed out on. Changing a figure here changes what
  happens *next*, and never rewrites a document somebody is holding.
- **The commission is one rate for everybody.** You cannot put one partner on 10% and another on
  15%; if you could, nobody could say what the business charges. The Truckers page shows the rate
  and no longer lets you edit it there.
- **Use the system defaults** puts the whole card back to the figures it shipped with.

Only an **administrator** or a **general manager** can open it.

### The accounts you start with

> **Only on an install that has been set up for you.** A company you registered yourself starts
> with exactly one account — yours. The three below belong to the demonstration company that ships
> with a new deployment.

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

It asks which company the account belongs to, then a name, an email address, a role, and a
password. The password is typed rather than passed as an option, so it does not end up in the
command history.

**The company question comes first, and it is the one to get right.** It decides whose fleet this
person will be looking at, and an account created in the wrong company is a working login staring
at somebody else's books. On an install with only one company it is not asked at all. Otherwise
you pick from a list, or name one with `--company=` (its name, its code or its id).

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

### If you forget your password

**Sign in → Forgotten your password?** Type the address you sign in with and a link is emailed to
you. It works once and stops working after an hour.

The screen says *"if that address has an account"* rather than *"we've emailed you"*, deliberately.
The system will not confirm whether an address is on file — otherwise anybody could use that form
to find out which of their competitors' staff are on this platform.

> **Resetting signs you out everywhere.** Phones, other browsers, everything. That is the point:
> the usual reason for a reset is a password somebody else may have. Changing your password from
> inside the app instead (which asks for your current one) leaves your other devices alone.

Nothing arrives? Check the spam folder, and check the address — a mistyped one produces exactly the
same screen as a correct one.

### Signing in

Open the back office and sign in with that email and password. **There is no company to choose** —
your address already says which one to open, and you land in it.

If the password is wrong the screen says so; if it says it cannot reach the server, the system is
not running rather than the password being wrong. They are different problems and the screen tells
you which. If it says your company is suspended, that is a billing matter and not a password one —
nothing has been deleted, and everything comes back when it is reactivated.

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

**These five are a starting point, not a fixed list.** They are your company's own rows: rename
them, re-tick what each reaches, and add the ones your office actually has — a Treasury Officer, a
General Manager, a Yard Boss. **Access Control** is where that is done, and nothing you change
there affects any other company on the system.

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

> **Or register them in Employees instead, and skip this screen.** Registering
> somebody into the **Driver** or **Helper** position asks for their licence and
> opens the driver record for you — see section 2A. Both routes end at the same
> record, so use whichever you are already on. Drivers Management is the quicker
> one when you are entering the whole fleet at the start; Employees is the right
> one from then on, because it captures the person as well as the licence.

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

## 2A. Putting people on the roster

**Employees → Register employee.**

An employee is the person: their name, their contact, when they were hired, what
they are paid. It is not their login and it is not their driving history — the
system keeps those separately and links to them, so a driver's trips survive a
change of job title and a member of staff can exist without ever signing in.

### The driver details only appear if the job needs them

**Pick the position first.** Choose **Driver** or **Helper** and two more fields
appear: **licence number** and **licence expiry**. Choose Mechanic, Office Staff,
Accountant or anything else and they do not — there is nothing to fill in and
nothing to skip past.

That is the whole rule, and it is read off the position, not off the words in
the title. A job counts as driving when its **default role is Driver**, which is
what you set when you added the position in Access Control. So a company that
adds "Long-haul Driver" or "Yard Marshal" gets the licence fields on those too,
without anybody having to tick a second box — because giving somebody the
driver's access is the same decision as saying they need a driver record.

> **A typed-in Custom title never asks for a licence.** Only the managed list
> knows which jobs drive. If you are registering somebody who drives, pick the
> position from the list rather than typing the title.

### You do not have to check whether they are already on the fleet

Type the licence number and the system works out the rest:

- **Already on file** — it links to that driver record and leaves it standing.
  Their trips, their completed count and their on-time rate are all untouched.
  Only the expiry is updated, because recording a renewal is exactly what you
  are doing when you retype it.
- **Not on file** — it opens the driver record for them, ready to be assigned a
  trip.

Either way there is one record, not two. This replaces the old **Driver record**
dropdown, which asked you to pick from a list of every driver in the fleet — a
question a mechanic's registration had no business asking, and one a new
driver's registration could not answer, because their record did not exist yet.

**One licence, one employee.** If the number you type is already on somebody
else's record, the screen says whose. That is either a typo or two people being
registered as the same driver, and both are worth stopping.

### Moving somebody onto the road later

Edit them, change the position to Driver, and the licence fields appear. Filling
them in opens the driver record then.

**Moving somebody off the road does not delete anything.** Change a driver's
position to something that does not drive and their driver record stays exactly
where it is — every trip in the system points at it, and taking it away would
quietly rewrite who drove what.

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
they cannot disagree with each other. The dashboard's 30-day net income is the same one again.

**What comes off the income.** The five columns on each unit's daily rows, anything you filed under
Expenses, and — this is the part people are surprised by — two kinds of money leaving the bank:
**supplier bills you have actually paid**, from Billing, and **payouts you have actually handed to
partner truckers**. The same three rules govern both:

- **Unpaid counts for nothing.** It is money you owe, not money that has gone. It sits on the
  Payables screen until you settle it.
- **Part-paid counts for the part you paid.** ₱18,000 paid against a ₱30,000 bill takes ₱18,000 off
  the period, not ₱30,000.
- **It lands in the period you paid it**, not the one it was dated. A June bill settled in July is
  July's money, and so is a run hauled in June and paid for in July.

A trucker payout also has to have **landed**. A transfer you have entered but not yet confirmed is
still on its way — it is still owed and it has not yet cost you anything. Confirming it is what
moves it across.

> **One exception, and it matters.** If the trucker owns a truck you hired on a *revenue share*,
> their cut is already charged to the day the run was delivered, in the **Owner share** on that
> unit's sheet. Paying them afterwards settles a debt your books have already counted, so it is not
> counted a second time. Partners hauling in their own trucks have no such sheet, which is why their
> payout is the only record that the money moved.

Supplier bills and trucker payouts belong to the business rather than to any one truck, so they are
in the **Total expenses** figure and in none of the rows of the table — a line under the table says
how much, so the total still reconciles. The same is true of overhead: office rent and an annual
permit are real costs that no unit earned.

> **What is still missing, so you are not surprised by it.** A run a partner hauled files no daily
> sheet, so the money the *customer* pays you for it is not in **Trip income**. Right now the
> payout shows and that side does not. Say so if you want partner runs booked properly — it is a
> bigger change, and it is the one that would show the margin you actually make on them.

> If you want the accrual view instead — every bill counted the day it was raised, paid or not —
> that is the **Income statement** under Financial Statements. Two reports, two questions: what the
> quarter cost you, and what the quarter committed you to.

### Actual income — what is left after what you owe

Quarterly Summary carries two more figures beside Net income:

| Tile | What it is |
| --- | --- |
| **Payables** | Everything still owed as the quarter closed — partners waiting to be paid, unsettled spend, unpaid supplier bills. The same question the Payables screen answers, asked about the end of that quarter rather than about today. |
| **Actual income** | Net income less payables. What is genuinely left once everybody queued up behind you has been settled. |

**Payables is not in Total expenses, and that is deliberate.** A bill you have not paid has cost
the quarter nothing yet. Counting it as an expense would charge the period for money that has not
moved — and then charge it a second time on the day it does. So an unpaid bill sits in Payables; the
moment you settle it, it leaves Payables and appears in Total expenses instead. The two tiles never
count the same peso twice.

**That is what stops paying people from flattering the quarter.** Pay a trucker ₱8,800 and Payables
falls by ₱8,800 — and Total expenses rises by the same ₱8,800, so Actual income does not move. It
should not: you owed the money before and you have paid it now, and you are no better off either
way. Before this, settling up on a Friday made Monday's figure look ₱8,800 healthier.

Net income is what the quarter earned. **Actual income is what you can act on.** A quarter that made
₱29,350 and owes ₱40,000 has still made ₱29,350 — and only one of those two figures tells you not to
draw anything out.

> One limitation worth knowing: an expense records that it is settled, but not the day it was
> settled. So for a **closed** quarter, a bill you have paid since is no longer counted as having
> been owed then, and Payables for that quarter reads slightly low. Supplier invoices carry their
> payment dates and are exact either way, and the quarter you are actually in is exact throughout.

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

### Tax on an invoice

**An invoice total is not what the customer pays.** Every receivable now shows
four figures, and they are all worked out for you:

```
  Net             10,000.00     the haul, from the rate card
+ VAT 12%          1,200.00     charged on, you remit it
= Invoiced        11,200.00     what the document says
- Withheld 2%       -224.00     the customer keeps this back and remits it
= Due             10,976.00     what actually lands in your bank
```

**Withholding is the one that surprises people.** A customer who is a
withholding agent pays you *less than the invoice says*, deliberately — the
difference goes to the BIR on your behalf and you claim it back as a credit. It
is not a short payment, and Cargo Rush treats an invoice paid at its **Due**
figure as fully settled.

Set both on the customer, once, in **Customer Management**:

| Field | What to put in it |
| --- | --- |
| **TIN** | Theirs. A VAT invoice without the buyer's TIN gets sent back. |
| **VAT** | *VAT (12%)* for almost everyone. *Zero-rated* for exporters and PEZA locators. *VAT-exempt* where they genuinely are. |
| **Withholds tax** | *Yes* for government agencies and large corporates. *No* for most small traders. |

Everything raised for that firm afterwards — by hand or by a delivery — picks
those up. **The rates are frozen onto each document as it is issued**, so a
change in law never rewrites an invoice a customer is already holding.

> Not VAT-registered yourself? An administrator can switch it off for the whole
> company, and your invoices then carry no VAT line at all.

### Getting paid

**Record the payment, not the status.** Payments are their own records now:
each has the day the money moved, the method, and — the useful one — the
**reference** off the cheque or transfer, which is what you match a bank
statement against.

That makes two things possible that were not before:

- **Part payments.** ₱50,000 against a ₱120,000 invoice leaves it **Partial**
  with ₱70,000 still owing, instead of forcing you to call it paid or unpaid.
- **One payment, several invoices.** A customer settling the month with one
  transfer is one payment split across the documents it covers — not four
  fictions typed in separately.

Marking an invoice settled is still one action and still its own verb, not a
status you edit. It records a payment for **whatever is left** — the due figure
less anything already received — and the status follows from that. Pressing it
twice does nothing the second time.

Entered against the wrong customer? Delete the payment and every invoice it was
holding up goes back to what it was.

### Who owes you, and how late

**Billing & Invoice → Aging** buckets everything outstanding by how far past
its due date it is — current, 1–30, 31–60, 61–90, over 90 — and lists it worst
customer first. That is the list a collections call is made from.

Each invoice counts at its **balance**, so a document half paid is half a
problem rather than a whole one.

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
| Signed out unexpectedly | The session expired. Sign in again — you land back where you were. Or somebody reset the password on that account, which signs out every device. |
| "Too many attempts" on the sign-in screen | Five wrong passwords for one address in a minute. Wait a minute. This is what stops somebody working through a list of leaked passwords against your accounts. |
| The reset link says it is no longer valid | It has been used, or it is more than an hour old. Ask for a new one — links are single-use on purpose. |
| The reset email never arrives | Check spam first. Then check the address: a mistyped one produces the same screen as a correct one, because the system will not say which addresses exist. |
| A driver sees no trips | Nothing *confirmed* to that driver — an unconfirmed request does not appear in the cab, by design. Or their account is not linked to a driver record. Check both on Trip Management and Drivers Management. |
| Registering somebody does not ask for a licence, and it should | Their position is not a driving one. A job counts as driving when its default role is **Driver** — set that in **Access Control → Positions**, or pick Driver or Helper from the list instead of typing a custom title. |
| "That licence is already on employee EMP-00xx's record" | The number is on somebody else's record. Either it is a typo, or that person is already registered under a different name. One licence belongs to one employee. |
| A driver was registered twice | They were entered once in Drivers Management and again in Employees with a *different* licence number, so the system had no way to tell they were the same person. Retype the licence to match and the two link up. |
| A customer sees nothing at all | Their account is not linked to a customer record. Recreate it with `php artisan cargo:user --role=customer`, which asks which firm it acts for. |
| "This account is not attached to a company" | The account exists but its company row was removed. An administrator has to reattach it; nothing the person signing in can fix. |
| "Southern Freight is suspended" | A billing matter, not a password one. Nothing has been deleted — every trip, invoice and peso comes back untouched on reactivation. |
| A new member of staff signs in and sees an empty system | Their account was created against the wrong company. Check with `php artisan cargo:user --company=...` — the command names the company in its confirmation line. |
| Somebody cannot register with their address | It already has an account somewhere on the system. An address belongs to exactly one account, which is what lets signing in skip the company question. They sign in instead. |
| A customer has no way in | They were added with a phone number and no **Portal login**, so no account was ever made. Edit them, type an address, and the notice above the list gives you the credentials to pass on. |
| A customer says the password does not work | It is the starting one only until they change it. If they have changed it and forgotten it, `php artisan cargo:user --role=customer` gives that firm a second account with a password you choose. |
| A request will not start from the phone | It has not been confirmed. Confirm it from Trip Management and the driver's Start button appears. |
| Proof-of-delivery photos do not load | `php artisan storage:link` has not been run on this install. Run it once; existing deliveries pick up their pictures immediately. |
| The company logo uploads but shows as a broken image | The same cause: `php artisan storage:link` has not been run. Run it once and it appears. |
| The logo lost half of itself | It was wider or taller than it was square, and the middle is what is kept. Upload the mark on its own rather than the full lockup with the name beside it. |
| A delivery earned nothing on the sheet | It had no vehicle assigned, so there is no unit sheet to file against. Assign one before the run goes out. |
| A delivered run was never invoiced | It has no customer, or its price is zero. Both are legitimate — the company's own freight — so nothing is raised. |
| The customer paid less than the invoice says | Check whether they are marked **Withholds tax**. If they are, that is correct and the invoice is settled — the difference went to the BIR. If they are not, mark them so, and future invoices will expect the right figure. |
| An invoice shows more than the rate card quoted | VAT. The quote is the net haul; the invoice adds 12% on top. If your desk quotes all-in prices instead, an administrator can set `TAX_PRICES_INCLUDE_VAT=true` and the VAT is worked out from inside the quoted figure. |
| Receivables dropped when somebody part-paid | They did not — the total counts what is still owed, not the face value. A ₱120,000 invoice with ₱50,000 received shows ₱70,000. |
| An old invoice has no VAT on it | It was issued before tax was switched on, and is left exactly as it was. Retroactively adding 12% would make it disagree with the copy the customer has. |
| A trip is priced lower than it should be | It has no distance. Pin both ends on the map, or type the road distance, and the quote is worked out again — as long as it has not been delivered yet. |
| The fleet total looks low | Only vehicles entered into the system count. A truck that has not been added is not in the fleet. |

---

## 11. Before going live

- [ ] **Your company registered**, and its name correct in the sidebar — it is what appears on
      every screen your people open
- [ ] Your logo uploaded, and checked at sidebar size (**Access Control → Company**)
- [ ] The roles renamed and re-ticked to match the office you actually run (**Access Control**)
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
