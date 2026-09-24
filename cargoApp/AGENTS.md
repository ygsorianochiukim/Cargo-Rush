# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

# Building for the Play Store

`npm run build:android` — production AAB, via EAS Build. Install the CLI
globally (`npm i -g eas-cli`); it must **not** be a dependency of this package,
because it pulls in its own TypeScript, which desyncs `package-lock.json` and
makes the builder's `npm ci --include=dev` fail before it compiles a line.

Two things about this repo make a plain `eas build` fail or misbehave, and both
are handled by `scripts/eas.mjs`, so go through the npm script rather than
calling `eas` directly:

- **eas-cli uploads the git root, not this package.** `cargoApp` sits beside a
  Laravel API and an Angular console, so a plain build tars up all three and,
  on Windows, dies recreating `CargoApi/public/storage` — the symlink
  `artisan storage:link` leaves behind. `EAS_PROJECT_ROOT` pins the archive to
  this directory.
- **The build reads the working tree, not HEAD** (`EAS_NO_VCS`). The repo
  usually carries unrelated work in progress; committing all of it to cut an
  app build is not a trade worth making. The flip side: whatever is on disk is
  what ships, so check `git status` here before a production build.

`eas build` also **writes resolved plugin permissions back into `app.json`** —
it will duplicate `android.permissions` and re-add `RECORD_AUDIO` on
expo-image-picker's behalf. The picker is only ever asked for
`mediaTypes: 'images'`, so that microphone permission is dead weight on the
listing; `android.blockedPermissions` strikes it. If the permissions list looks
doubled after a build, that is what happened — revert it.

Version codes come from EAS (`appVersionSource: "remote"` in `eas.json`) and
auto-increment per production build, so there is no `versionCode` in `app.json`
to bump. `version` there is still the user-facing one.

The first AAB has to be uploaded to the Play Console by hand — `eas submit`
can only take over once the listing exists.
