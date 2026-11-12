# @fahoba/client

Build your own frontend for [Fahoba](https://github.com/fahoba/fahoba), the
family calendar for wall tablets and phones. This package talks to the
server and carries the rules the official app applies, without a framework:
use it from Vue, React, Svelte or plain TypeScript.

```bash
npm install @fahoba/client
```

```ts
import { createClient, FahobaError } from '@fahoba/client'

const fahoba = createClient()

try {
  const { kind } = await fahoba.session()          // 'device' (wall) or 'user' (account)
  const agenda = await fahoba.agenda('2026-11-02', '2026-11-08')
  await fahoba.realtime(areas => reload(areas))    // 'tasks', 'lists', … or 'all'
}
catch (error) {
  if (error instanceof FahobaError && error.kind === 'unauthenticated') {
    showPairingOrSignIn()                           // fahoba.pair(code) / fahoba.login(…)
  }
}
```

- **Served by the Fahoba server.** The sign-in lives in httpOnly cookies;
  your frontend never sees a token and must run on the server's origin. An
  operator installs it in place of the official app (see the guide).
- **Every other route** goes through `fahoba.request(path, { method, body,
  query })`; the server describes them at `/api/openapi.json` (plugin
  `fahoba/openapi`).
- **Refusals carry a `kind`:** `pin` (a wall needs the parent PIN — ask,
  then repeat), `read-only` (the message explains why), `not-found` (also
  an area switched off), `forbidden`, `invalid` (`firstMessage` for a form),
  `unauthenticated`, `throttled`, `unavailable`.
- **The server decides, the screen shows.** Permissions arrive as `can` on
  each item and `permissions` on the account; hide what is not allowed, the
  server refuses it anyway.
- **Rules to apply the same way:** `occurrenceKey()` (a task occurrence is
  task + day + person + daypart), `keepTicks()` (a load never undoes a tick
  this device just made), `justFinished()` (celebrate only the moment a day
  becomes done), `alarmIn()`/`withoutServer()` (light and dark without
  asking every minute), `shownNotices()`, `startLanguage()`,
  `holdAnnouncements()` while the device writes.
- **Dates are the household's.** Days are plain `YYYY-MM-DD` in
  `agenda.household.timezone`; format with `Intl` and that `timeZone`,
  never with the device's own.

Starting point: the
[`fahoba-frontend-template`](https://github.com/fahoba/fahoba-frontend-template).
The guide is `docs/frontends.md` in the Fahoba repository.

MIT licensed.
