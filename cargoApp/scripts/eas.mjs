#!/usr/bin/env node
/**
 * Run eas-cli against *this package* rather than the whole repository.
 *
 * `cargoApp` is one project in a polyglot repo — the Laravel API and the
 * Angular console are siblings — and eas-cli decides what to upload by asking
 * git for the repository root. Left alone it tars up all three, which is a
 * hundred megabytes the Android builder has no use for, and on Windows it does
 * not even get that far: it dies recreating `CargoApi/public/storage`, the
 * symlink `artisan storage:link` leaves behind.
 *
 * `EAS_PROJECT_ROOT` (absolute, or eas-cli ignores it) pins the archive to this
 * directory. `EAS_NO_VCS` makes it the *working tree* rather than a clone of
 * HEAD, so a build reflects what is on disk — the repo carries unrelated work
 * in progress most of the time, and requiring a commit of all of it just to cut
 * an app build is not a trade worth making.
 *
 *   npm run build:android
 */
import { spawn } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const eas = spawn('eas', process.argv.slice(2), {
  stdio: 'inherit',
  shell: true,
  cwd: projectRoot,
  env: { ...process.env, EAS_PROJECT_ROOT: projectRoot, EAS_NO_VCS: '1' },
});

eas.on('exit', (code) => process.exit(code ?? 1));
