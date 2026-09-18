# Cargo Rush on Google Play

Everything the Play Console will ask for, answered from what this app actually
does. Work top to bottom.

---

## 0. Blocker — the current build cannot reach any server

`src/services/shared/api.service.ts` resolves the API like this:

```ts
const configured = process.env.EXPO_PUBLIC_API_URL;   // unset
const host = Constants.expoConfig?.hostUri?.split(':')[0];  // undefined off Metro
return host ? `http://${host}:8000` : 'http://localhost:8000';
```

`hostUri` is the machine that served the JS bundle. A release build has no
Metro, so it is `undefined`, and every call falls through to
**`http://localhost:8000`** — the handset itself. Sign-in fails, and so does
everything behind it. The splash and the login form will look perfect and
nothing will work.

There is also a second problem behind it: `http://`. Android blocks cleartext
traffic by default from API 28, so the production API has to be **HTTPS**
regardless.

**Fix before any upload**, with the real API origin:

```bash
eas env:create --environment production --name EXPO_PUBLIC_API_URL \
  --value https://api.cargorush.ph --visibility plaintext
eas env:create --environment preview --name EXPO_PUBLIC_API_URL \
  --value https://api.cargorush.ph --visibility plaintext
```

Then rebuild. Confirm it took by checking the build log's environment line —
it currently reads *"No environment variables ... found for the production
environment"*.

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
| Privacy policy URL | public, must load | draft at `store/privacy-policy.md` — **host it**; `aya-it.online/privacy` currently 404s |

Content rating questionnaire: a business logistics tool — no ads, no user
content, no purchases. Expect *Everyone*.

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
