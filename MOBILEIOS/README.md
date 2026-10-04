# AKM — iOS customer app

The customer portal from `MOBILEAPP`, as a standalone Expo project for iPhone and iPad.
It contains only what a customer account can reach. Staff, technician and agent
features stay in `MOBILEAPP`; this project does not import from it.

## What's in it

| Tab | Screen | Source |
| --- | --- | --- |
| Dashboard | Balance card, Pay Now (Xendit), payment history | `src/pages/DashboardCustomer.tsx` |
| Bills | Statements (PDF), invoices, payment history | `src/pages/Bills.tsx` |
| Support | Latest ticket, ticket details, new ticket with photo | `src/pages/Support.tsx` |
| Menu | Profile, notifications, about, release notes, sign out | `src/pages/Menu.tsx` |

It also has login and forgot password (`src/pages/Login.tsx`), push-token registration,
the Messenger chat button and the forced-update gate.

Only customer accounts (role `customer` / role id 3) can sign in. A staff login is
refused at the login screen and its token is revoked. A staff session restored from
storage is signed out.

When the API answers 401 to a request that carried a token, the app shows
"Session Expired" and returns to the login screen.

## Run

```sh
cd MOBILEIOS/frontend
npm install
cp .env.example .env
npx expo start
```

Point `.env` at a local API for development. There is no `ios/` folder in the repo:
`npx expo prebuild` or EAS generates it.

Checks that run on any OS:

```sh
npm run typecheck
npm run bundle:ios
```

## Build and submit

```sh
npm run build:ios
npm run submit:ios
```

Builds take `EXPO_PUBLIC_API_BASE_URL` from the `env` block in `eas.json`.
Signing uses local credentials (`credentialsSource: local`), as `MOBILEAPP` does.
`eas submit` reads the App Store Connect key from `MOBILEIOS/AuthKey_68K8S2XRJQ.p8`.
The key is not committed.

The app uses the same Expo project, bundle id (`com.akm.sync`) and App Store record
(`6816959602`) as the iOS configuration in `MOBILEAPP`. Push notifications keep working
with the backend's existing Expo tokens, and a customer who updates keeps their session.

## Forced updates

`/app-version/config` describes the Android release only, and the iOS app ignores those
fields. To force an iOS update, have the endpoint also return `ios_min_version`
(and optionally `ios_latest_version` and `appstore_url`). Without them the gate stays off.
See `src/services/appVersionService.ts`.
