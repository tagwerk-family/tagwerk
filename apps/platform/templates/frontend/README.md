# A Fahoba frontend

A starting point for your own interface to [Fahoba](https://github.com/fahoba/fahoba)
— for a wall tablet, a phone, an e-ink display, whatever your family needs.
It is a plain Vite + TypeScript app on [`@fahoba/client`](https://www.npmjs.com/package/@fahoba/client):
pair a wall or sign in, then the coming week, reloaded when something changes.
Swap in Vue, React or Svelte as you like.

## Develop

```bash
npm install
FAHOBA_URL=https://fahoba.test npm run dev    # your Fahoba server
```

Vite passes `/api` and `/broadcasting` on to the server, so the sign-in
cookie belongs to the dev server's origin — exactly as it will when Fahoba
serves your files. Pair with a code from Fahoba's admin (Setup → Devices).

## Ship

```bash
npm run build      # writes dist/, which you commit
git tag v1.0.0 && git push --tags
```

Rename `acme/fahoba-frontend` in `composer.json` and publish the repository
on Packagist (or name it in `FAHOBA_COMPOSER_REPOSITORIES`). An operator
then sets in Fahoba's `deploy/.env`:

```
FAHOBA_FRONTEND=acme/fahoba-frontend:^1.0
```

The container installs it at start and serves `dist/` at `/` instead of the
official app; `/api`, `/admin` and the rest stay Fahoba's.

## The rules

- **`dist/index.html` plus `dist/assets/`.** Fahoba serves `index.html` at
  `/` and everything else from `/assets/`. A `sw.js`, `manifest.webmanifest`,
  `icon.svg`, `icon-192.png`, `icon-512.png` beside `index.html` are served
  too, if you have them.
- **No inline scripts, nothing from other hosts.** The server's Content
  Security Policy allows script files of its own origin only; the start
  refuses a page that would stay blank. Fonts and pictures belong in
  `assets/` as well.
- **The server decides.** What someone may do arrives as `permissions` and
  per-item `can`; a wall's write may answer `pin` (ask for the parent PIN,
  then repeat). Hide what is not allowed — the server refuses it anyway.
- **Days are the household's** (`agenda.household.timezone`), never the
  device's.
- **The contract** is the route list in Fahoba's
  `apps/platform/routes/api-contract.txt` and the guide `docs/frontends.md`.

MIT licensed — make it yours.
