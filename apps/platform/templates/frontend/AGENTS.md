# Fahoba frontend — notes for coding assistants

- This is a frontend for Fahoba served by the Fahoba server itself:
  `dist/index.html` at `/`, `dist/assets/*` at `/assets/`. Keep Vite's
  `assetsDir: 'assets'`.
- Talk to the server only through `@fahoba/client` (`createClient()`); the
  sign-in is an httpOnly cookie, never a token in JavaScript or storage.
- No inline `<script>`, no script, font or style from another host: the
  server's Content Security Policy blocks them and the start refuses the page.
- Never decide permissions in the UI alone: show what `permissions`/`can`
  allow, handle `FahobaError.kind` (`pin`, `read-only`, `forbidden`, …).
- Dates: plain `YYYY-MM-DD` in `agenda.household.timezone`, formatted with
  `Intl` and that `timeZone`.
- `dist/` is committed; run `npm run build` before every commit that changes
  `src/` (CI checks it).
- Routes: Fahoba's `apps/platform/routes/api-contract.txt`; details at
  `/api/openapi.json` on a server with the `fahoba/openapi` plugin.
