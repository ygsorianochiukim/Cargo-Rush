# Cargo Rush on Google Play

Everything the Play Console will ask for, answered from what this app actually
does. Work top to bottom.

---

## 0. The API origin — done, but know why it mattered

`src/services/shared/api.service.ts` falls back like this when
`EXPO_PUBLIC_API_URL` is unset:

```ts
const host = Constants.expoConfig?.hostUri?.split(':')[0];  // undefined off Metro
return host ? `http://${host}:8000` : 'http://localhost:8000';
```

`hostUri` is the machine that served the JS bundle. A release build has no
Metro, so it is `undefined` and every call goes to **`http://localhost:8000`**
— the handset itself. The splash and the login form look perfect and nothing
works. `http://` would fail anyway: Android blocks cleartext from API 28.

Set on EAS, against the live server:

| Environment | Value |
|---|---|
| `production` | `https://api.aya-it.online` |
| `preview` | `https://staging.aya-it.online` |

Preview points at staging deliberately, so an internal test build cannot write
into production data. Change it if you want release candidates tested against
the real thing.

Both hosts are live, serve HTTPS, and 308-redirect from HTTP.
`POST /api/v1/login` answers 422 to an empty body, which is the endpoint this
app actually calls.

To confirm a build picked it up, the build log must contain:

```
Environment variables with visibility "Plain text" and "Sensitive" loaded from
the "production" environment on EAS: EXPO_PUBLIC_API_URL.
```

If it instead says *"No environment variables ... found"*, the build is the
broken one — do not upload it. **Any `.aab` built before 18 September 2026 has
this bug**, including version code 4.

---

## 1. Account and app

- Play Console developer account — US$25, one-off, allow a day or two for
  identity verification.
- **Create app** → name `Cargo Rush`, app type *App*, free.
- Package name is fixed at **`ph.cargorush.app`** and cannot be changed after
  the first upload.

Upload the first `.aab` **by hand** to Internal testing. `eas submit` can only
target a listing that already exists.

---

## 2. Data safety

Answer from the code, not from memory. What the app sends to your own API:

| Data type | Collected | Why | Notes |
|---|---|---|---|
| Name | Yes | Account management | `ShipperRegistration.name`, customer sign-up |
| Email address | Yes | Account management | Sign-in and registration |
| Phone number | Yes, optional | Account management | `contact_phone` on registration |
| Password | Yes | Account management | Sanctum bearer token auth |
| **Precise location** | **Yes, incl. background** | App functionality | `gps/pings` while a trip runs |
| Photos | Yes | App functionality | Proof of delivery, `mediaTypes: 'images'` |
| Device ID | Yes | Account management | `device_name` names the token's handset |

- Collected, **not** shared with third parties.
- Data **is** encrypted in transit — *only true once §0 is done*. Answer
  honestly; a false answer here is a policy violation in its own right.
- Users can request deletion — say how (the office, or an in-app route).
- You need a **privacy policy URL** that is publicly reachable and covers
  exactly this list, including background location.

---

## 3. Background location declaration

`ACCESS_BACKGROUND_LOCATION` triggers a manual review with a written
justification **and a screen-recorded demo**. Budget days to weeks. Draft:

> Cargo Rush is a fleet management app used by haulage drivers during paid
> deliveries. Background location is used for one feature: reporting the
> vehicle's position along an active trip so the dispatch office can see where
> a load is and give customers an accurate ETA.
>
> Collection starts only when a driver taps to start a trip and stops when the
> trip is delivered or cancelled. It never runs outside an active trip. A
> persistent foreground-service notification is shown throughout.
>
> Foreground-only location is insufficient: a delivery runs for hours with the
> phone cradled and the screen off, and tracking that stops when the screen
> locks reports nothing for the part of the journey that matters.

The demo video must show: signing in → starting a trip → the foreground-service
notification → the position updating with the app backgrounded.

---

## 4. Store listing

- **App name** (30): `Cargo Rush`
- **Short description** (80):
  `Fleet management for haulage — trips, tracking and proof of delivery.`
- **Full description** (4000): trips and dispatch, live GPS for the office,
  proof-of-delivery photos, vehicle inspections, incident reports, and a
  customer portal for booking a load. State plainly that it is for staff of a
  haulage firm and its customers — reviewers reject vague listings.

Assets required:

| Asset | Spec | Status |
|---|---|---|
| App icon | 512×512 PNG | ready — `assets/store/play-icon-512.png` |
| Feature graphic | 1024×500 PNG | **not in repo — must be made** |
| Phone screenshots | 2–8, min 320px side | **must be captured** |
| Privacy policy URL | public, must load | draft at `store/privacy-policy.md` — **fill in `{COMPANY}` (4×) and `{CONTACT_EMAIL}` (3×), then host it**. See below |

Content rating questionnaire: a business logistics tool — no ads, no user
content, no purchases. Expect *Everyone*.

### Hosting the privacy policy

The serving side is already in place: `CargoUI`'s vhost has an exact-match
`location = /privacy` that serves `privacy.html`, so the finished policy goes
live at **`https://app.aya-it.online/privacy`** on the next deploy.

Two things first, and neither is something to guess at — this is the document
Google holds you to:

1. Replace `{COMPANY}` (4 occurrences) with the legal entity that owns the
   Play listing, and `{CONTACT_EMAIL}` (3) with a monitored address.
2. Convert `store/privacy-policy.md` to `CargoUI/public/privacy.html` and
   commit it. Anything that renders Markdown to a standalone HTML page will
   do; it needs no styling to satisfy the requirement, only to be readable and
   publicly reachable.

Check it loads before pasting the URL into the console — a policy URL that
404s is a rejection, and it is the single most common one.

---

## 5. Automating releases after the first

Once the listing exists, `eas submit` can push builds. It needs a Google Cloud
service account:

1. Play Console → **Setup → API access** → link a Google Cloud project.
2. Create a service account, grant it **Release manager** on this app.
3. Download its JSON key. **Do not commit it** — keep it outside the repo.
4. Point `eas.json` at it:

```json
"submit": { "production": { "android": {
  "serviceAccountKeyPath": "../../play-service-account.json",
  "track": "internal",
  "releaseStatus": "draft"
} } }
```

Then `npm run submit:android`.
