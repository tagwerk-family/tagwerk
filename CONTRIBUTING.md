# Contributing to Fahoba

Thanks for wanting to help. Fahoba is source-available under the
[PolyForm Shield License 1.0.0](LICENSE): anyone may read, run and change
it for themselves; offering it to others as a hosted service or a competing
product is reserved for the licensor.

## Your rights in a contribution

By sending a pull request, patch or other contribution you agree that:

- you wrote it, or have the right to submit it;
- it is licensed to everyone under the project's license, **and** to the
  licensor, Andreas Pointner, under a perpetual, worldwide, irrevocable,
  royalty-free license to use, change, sublicense and distribute it under
  any terms, including commercial ones.

You keep the copyright in your contribution. Without the second grant the
licensor could not run the project commercially once it contains your code,
which is why a pull request cannot be merged without it.

## Before you start

- Open an issue first for anything larger than a fix, so we can agree on the
  approach before you spend time on it.
- Read [`AGENTS.md`](AGENTS.md): it holds the conventions and the pitfalls
  that cost time once already. The reasons behind the
  design live in [`docs/architecture.md`](docs/architecture.md).
- Questions and ideas go to
  [Discussions](https://github.com/fahoba/fahoba/discussions), not issues.

## Plugins instead of pull requests

Much of what a household wants — a new calendar service, a tile for the
bins, a recipe server — fits a plugin better than a change to the core. A
plugin builds on `fahoba/plugin-api` (MIT), so it may carry any licence
and needs no contributor grant. Start with
[`docs/plugins.md`](docs/plugins.md); its *Good first plugins* list has
ideas, and its *Directory* takes a pull request with your plugin's row.

## Setting up

Development runs in [DDEV](https://ddev.com); the [README](README.md#quick-start-development)
has the commands. PHP, Composer and Node are only needed inside the
container — Playwright is the exception and runs from the host.

## What a pull request needs

- **Code in English**: identifiers, comments, test names, commit messages.
  Texts people read go through the translations (`lang/de.json`,
  `shared/i18n/locales/`), German and English.
- **Tests for what changes**, green before you ask for review:

  ```bash
  ddev exec 'cd apps/platform && php artisan test'
  ddev exec 'cd apps/platform && ./vendor/bin/pint --dirty'
  ddev exec 'cd apps/platform/frontend && pnpm test && pnpm typecheck'
  cd apps/platform/frontend/packages/e2e && pnpm run e2e   # after pnpm build
  ```

  GitHub Actions runs PHPUnit, Pint, Vitest and the type check on every pull
  request; Playwright is yours to run.
- **Migrations are append-only**: add a new one, never edit a released one.
- **An entry in [`CHANGELOG.md`](CHANGELOG.md)** under `[Unreleased]` that
  says why, not which files.
- **Both READMEs** (and both kiosk guides) when you change one of them.

## Reporting bugs

An issue with what you did, what you expected and what happened instead,
plus the version (`APP_VERSION` or the image tag) helps most. Security
problems do not belong in an issue — see [`SECURITY.md`](SECURITY.md).
