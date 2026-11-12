import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { createAccount, dropHousehold, login, openSignIn, ownHousehold, storedAccount, tinker } from './helpers'

/*
 * The app as a phone, signed in as an account. That is a throwaway account from
 * mobile.setup.ts; the teardown removes it again.
 */

/**
 * The areas sit in a menu that slides in from the left. Opened here unless
 * it is open already; a tap on an area closes it again.
 */
async function menu(page: Page): Promise<Locator> {
  const nav = page.getByTestId('menu')

  await settled(page)

  if (!(await nav.isVisible())) {
    await page.getByTestId('menu-open').click()
  }

  await expect(nav).toBeVisible()
  await settled(page)

  return nav
}

/**
 * Until the menu has finished sliding. Tapped mid-slide, an entry moves
 * under the pointer — or the menu is on its way out and gone a moment later.
 */
async function settled(page: Page): Promise<void> {
  await page.waitForFunction(() => [...document.querySelectorAll('nav, [data-testid="menu-scrim"]')]
    .every(element => element.getAnimations().length === 0))
}

/**
 * The settings are at the foot of the menu: an overview, each topic one tap
 * further (`appearance`, `language`, `start`, `calendars`).
 */
async function openSettings(page: Page, topic?: string): Promise<void> {
  await menu(page)
  await page.getByTestId('settings').click()

  if (topic) {
    await page.locator(`[data-testid="settings-page"][data-value="${topic}"]`).click()
  }
}

/** Escape leads from a topic back to the overview, a second one closes. */
async function closeSettings(page: Page): Promise<void> {
  const sheet = page.getByTestId('settings-sheet')

  while (await sheet.count() > 0) {
    await page.keyboard.press('Escape')
  }
}

interface Manifest {
  start_url: string
  scope: string
  icons: { src: string }[]
}

test('starts on the dashboard after login without an error message', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { level: 1, name: 'Start' })).toBeVisible()
  await expect(page.getByTestId('dashboard-tile').first()).toBeVisible()

  await page.waitForLoadState('networkidle')
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('shows the calendar as an hour grid or as a list', async ({ page }) => {
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
    page.goto('/'),
  ])

  await (await menu(page)).getByRole('button', { name: 'Kalender' }).click()

  // A week or month does not fit a phone: it offers day, three days and list,
  // in the sheet behind the filter — the row above the grid stays one row.
  const calendar = page.getByTestId('calendar')
  await expect(calendar.getByTestId('calendar-view')).toHaveCount(0)
  await calendar.getByTestId('calendar-filter').click()
  await expect(calendar.getByTestId('calendar-view')).toHaveCount(3)
  await expect(calendar.getByTestId('day')).toHaveCount(1)

  const put = () => page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok())

  await Promise.all([put(), calendar.locator('[data-testid="calendar-view"][data-value="three"]').click()])
  await expect(calendar.getByTestId('day')).toHaveCount(3)

  await Promise.all([put(), calendar.locator('[data-testid="calendar-view"][data-value="list"]').click()])
  await expect(calendar.getByTestId('day')).toHaveCount(0)
  await expect(calendar.getByTestId('calendar-list').getByRole('heading', { level: 3, name: 'Heute' })).toBeVisible()

  // Stored on the account: after a reload the list is still there.
  await page.reload()
  await expect(page.getByTestId('calendar-list').getByRole('heading', { level: 3, name: 'Heute' })).toBeVisible()

  // Leave things tidy for the next run.
  await page.getByTestId('calendar-filter').click()
  await Promise.all([put(), page.locator('[data-testid="calendar-view"][data-value="day"]').click()])
})

test('hides a tab in the menu and shows it again', async ({ page }) => {
  // Only once the preferences are loaded: a late answer would undo the click.
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
    page.goto('/'),
  ])

  await expect((await menu(page)).getByRole('button', { name: 'Essen', exact: true })).toBeVisible()

  await page.getByTestId('settings').click()
  await page.locator('[data-testid="settings-page"][data-value="start"]').click()

  const toggle = page.locator('[data-testid="menu-entry"][data-value="meals"] [data-testid="menu-visible"]')
  const put = () => page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok())

  await Promise.all([put(), toggle.click()])

  // Same wait as above: tapped before the answer, the switch still shows the
  // default, hides the tab a second time and leaves the account that way.
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
    page.reload(),
  ])
  const bar = await menu(page)
  await expect(bar.getByRole('button', { name: 'Kalender' })).toBeVisible()
  await expect(bar.getByRole('button', { name: 'Essen', exact: true })).toHaveCount(0)

  await page.getByTestId('settings').click()
  await page.locator('[data-testid="settings-page"][data-value="start"]').click()
  await Promise.all([put(), toggle.click()])
  await closeSettings(page)
  await expect((await menu(page)).getByRole('button', { name: 'Essen', exact: true })).toBeVisible()
})

test('switches between all areas', async ({ page }) => {
  await page.goto('/')

  for (const name of ['Aufgaben', 'Listen', 'Sterne', 'Essen', 'Start']) {
    await (await menu(page)).getByRole('button', { name, exact: true }).click()

    // A tap on an area closes the menu.
    await expect(page.getByRole('navigation', { name: 'Bereiche' })).toHaveCount(0)
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
    await expect((await menu(page)).getByRole('button', { name, exact: true })).toHaveAttribute('aria-current', 'page')
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('menu')).toHaveCount(0)
  }
})

/*
 * The same view as on the wall, only narrow: the phone shows the whole week
 * as a grid, like a printed timetable — no strip of single days.
 */
test('shows the timetable as a week grid', async ({ page }) => {
  await page.goto('/')

  // By test id, not by name: the sample data contains an
  // event "Musikschule", and it carries the same name.
  await (await menu(page)).getByTestId('tab').filter({ hasText: 'Schule' }).click()

  await expect(page.getByRole('heading', { level: 1, name: 'Schule', exact: true })).toBeVisible()

  const grid = page.getByTestId('timetable-grid')
  await expect(grid.getByRole('columnheader', { name: 'Mo', exact: true })).toBeVisible()
  await expect(grid.getByRole('columnheader', { name: 'Fr', exact: true })).toBeVisible()
  await expect(page.getByTestId('timetable-days')).toHaveCount(0)
})

// The throwaway account is removed afterwards, so its choice needs no undo.
test('keeps the calendar choice for the account', async ({ page }) => {
  await page.goto('/')
  await openSettings(page, 'calendars')

  const first = page.getByTestId('calendar-choice').first()
  await expect(first).toHaveAttribute('aria-checked', 'true')

  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/calendars/choice') && response.request().method() === 'PUT'),
    first.click(),
  ])
  await expect(first).toHaveAttribute('aria-checked', 'false')

  await page.reload()
  await openSettings(page, 'calendars')
  await expect(page.getByTestId('calendar-choice').first()).toHaveAttribute('aria-checked', 'false')
})

test('switches the language for the account and back to the household', async ({ page }) => {
  await page.goto('/')
  await openSettings(page, 'language')

  const choice = (value: string) => page.getByTestId('language').and(page.locator(`[data-value="${value}"]`))

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/auth/locale') && response.request().method() === 'PUT'),
    choice('en').click(),
  ])

  await expect(page.getByRole('heading', { name: 'Language' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')

  // Stored for the account, not in the browser: a reload keeps it.
  await page.reload()
  await page.getByTestId('menu-open').click()
  await expect(page.getByRole('navigation').getByText('Home')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('menu')).toHaveCount(0)

  await openSettings(page, 'language')
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/auth/locale') && response.request().method() === 'PUT'),
    choice('').click(),
  ])

  await expect(page.getByRole('heading', { name: 'Sprache' })).toBeVisible()
  await expect(choice('')).toHaveAttribute('aria-pressed', 'true')
})

test('keeps the selected theme for the account', async ({ page }) => {
  await page.goto('/')
  await openSettings(page, 'appearance')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/theme') && response.request().method() === 'PUT'),
    page.getByRole('dialog').getByRole('radio', { name: 'Dunkel' }).click(),
  ])

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  // Reload without browser storage: it only stays dark if the server stored
  // the choice for the account.
  await page.evaluate(() => localStorage.clear())
  await page.reload()

  await openSettings(page, 'appearance')
  await expect(page.getByRole('dialog').getByRole('radio', { name: 'Dunkel' })).toHaveAttribute('aria-checked', 'true')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

test('is set up as one PWA for wall and phone at /', async ({ page }) => {
  await page.goto('/')

  const area = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope)
  expect(new URL(area).pathname).toBe('/')

  const link = await page.locator('link[rel=manifest]').getAttribute('href')
  expect(link).not.toBeNull()

  const manifestUrl = new URL(link ?? '', page.url())
  const response = await page.request.get(manifestUrl.href)
  expect(response.ok()).toBe(true)

  const manifest: Manifest = await response.json()
  expect(new URL(manifest.start_url, manifestUrl).pathname).toBe('/')
  expect(new URL(manifest.scope, manifestUrl).pathname).toBe('/')

  // Every icon is really there — PNG first, Android ignores SVG in places.
  expect(manifest.icons[0].src).toMatch(/\.png$/)

  for (const icon of manifest.icons) {
    const symbol = await page.request.get(new URL(icon.src, manifestUrl).href)
    expect(symbol.ok(), icon.src).toBe(true)
    expect(symbol.headers()['content-type']).toMatch(/^image\//)
  }

  // Icon and status bar badge of the notifications (sw.js).
  for (const file of ['icon-192.png', 'badge-96.png']) {
    const symbol = await page.request.get(new URL(file, manifestUrl).href)
    expect(symbol.headers()['content-type'], file).toContain('image/png')
  }
})

test('shows the latest bookings in the stars area', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

  await page.getByTestId('bookings-toggle').click()
  await expect(page.getByTestId('booking').first()).toBeVisible()
})

/*
 * The mail flows answer the same for any address. An unknown one keeps them
 * off the stored account's sign-in limit (five a minute), which the tests
 * after them still need.
 */
const UNKNOWN_EMAIL = 'e2e-unknown@example.test'

test.describe('without stored login', () => {
  test.use({ storageState: { cookies: [], origins: [] } })


  test('rejects a wrong password', async ({ page }) => {
    await openSignIn(page)

    await page.getByLabel('E-Mail').fill(storedAccount().email)
    await page.getByLabel('Passwort').fill('ganz-sicher-falsch')
    await page.getByTestId('login').click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Bereiche' })).toHaveCount(0)
  })

  /*
   * A child's phone offers what the child may do and nothing more: no new
   * tasks, and a sibling's task cannot be ticked. The server refuses both
   * anyway; the phone just does not pretend otherwise.
   */
  test('offers a child only what it may change', async ({ page }) => {
    await openSignIn(page)
    await login(page, createAccount('child'))

    await (await menu(page)).getByRole('button', { name: 'Aufgaben' }).click()
    await expect(page.getByTestId('day-column').first()).toBeVisible()

    await expect(page.getByTestId('task-new')).toHaveCount(0)
    await expect(page.getByTestId('column-new')).toHaveCount(0)

    /*
     * Someone else's task opens for reading, but offers nothing to check
     * off. The account belongs to the first child, and which column is
     * someone else's depends on the data — so each column's first task is
     * opened until one offers no button. "Für alle" would count as the child's.
     */
    const columns = page.locator('[data-testid="day-column"]:not([aria-label="Für alle"])')
    const details = page.getByTestId('task-details')
    let readOnly = 0

    for (const column of await columns.all()) {
      const task = column.getByTestId('column-open-task').first()

      if (await task.count() === 0) {
        continue
      }

      await task.click()
      await expect(details).toBeVisible()
      readOnly += await details.getByTestId('task-details-complete').count() === 0 ? 1 : 0
      await page.keyboard.press('Escape')
      await expect(details).toHaveCount(0)
    }

    expect(readOnly).toBeGreaterThan(0)
  })

  // The answer is the same whether the address exists or not.
  test('requests a reset link for a forgotten password', async ({ page }) => {
    await openSignIn(page)

    await page.getByRole('button', { name: 'Passwort vergessen?' }).click()
    await page.getByLabel('E-Mail').fill(UNKNOWN_EMAIL)
    await page.getByTestId('login').click()

    await expect(page.getByText('ist der Link unterwegs')).toBeVisible()
  })

  test('takes the reset link from the address and rejects a used one', async ({ page }) => {
    await page.goto('/?reset=used-token&email=nobody%40example.test')

    await expect(page.getByLabel('Neues Passwort')).toBeVisible()
    expect(page.url()).not.toContain('reset=')

    await page.getByLabel('Neues Passwort').fill('new-password-1')
    await page.getByLabel('Passwort wiederholen').fill('new-password-1')
    await page.getByTestId('login').click()

    await expect(page.getByRole('alert')).toContainText('abgelaufen')
  })

  test('greets an invited person and asks for a password', async ({ page }) => {
    await page.goto('/?invite=used-token&email=nobody%40example.test')

    await expect(page.getByText('Willkommen!')).toBeVisible()
    await page.getByLabel('Neues Passwort').fill('new-password-1')
    await page.getByLabel('Passwort wiederholen').fill('new-password-1')
    await page.getByTestId('login').click()

    await expect(page.getByRole('alert')).toContainText('abgelaufen')
  })

  /*
   * The social sign-in plugin is off in the development database, so its
   * list is faked; the way to the provider and back is PHPUnit's.
   */
  test('offers the social sign-in and explains a refusal', async ({ page }) => {
    await page.route('**/api/auth/social', route => route.fulfill({
      json: { providers: [{ key: 'google', label: 'Google' }] },
    }))

    await openSignIn(page)
    await expect(page.getByTestId('social-login')).toHaveAttribute('href', '/social/google/redirect')
    await expect(page.getByTestId('social-login')).toHaveText('Mit Google anmelden')

    await page.goto('/?social=unknown')

    await expect(page.getByRole('alert')).toContainText('kein Konto')
    expect(page.url()).not.toContain('social=')
  })

  test('shows no social sign-in while the plugin is off', async ({ page }) => {
    await openSignIn(page)

    await expect(page.getByLabel('Passwort')).toBeVisible()
    await expect(page.getByTestId('social-login')).toHaveCount(0)
  })

  test('asks for the code after sending a sign-in link', async ({ page }) => {
    await openSignIn(page)

    await page.getByRole('button', { name: 'Anmeldelink per E-Mail' }).click()
    await page.getByLabel('E-Mail').fill(UNKNOWN_EMAIL)
    await page.getByTestId('login').click()

    await page.getByLabel('Code aus der E-Mail').fill('ZZZZZZ')
    await page.getByTestId('login').click()

    await expect(page.getByRole('alert')).toContainText('Code stimmt nicht')
  })

  test('rejects a used sign-in link from the address', async ({ page }) => {
    await page.goto('/?login=used-token&email=nobody%40example.test')

    await expect(page.getByRole('alert')).toContainText('Code stimmt nicht')
    expect(page.url()).not.toContain('login=')
  })

  test('logs out and stays logged out after reload', async ({ page }) => {
    await openSignIn(page)
    await login(page, storedAccount())

    await openSettings(page)
    await page.getByRole('button', { name: 'Abmelden' }).click()

    await expect(page.getByTestId('login')).toBeVisible()

    // Nobody signed in any more: the start page with both ways in.
    await page.reload()
    await expect(page.getByRole('heading', { name: 'Willkommen bei Fahoba' })).toBeVisible()
  })

  /*
   * Grandma follows two families with one sign-in: invited by the second,
   * she says yes, switches over and back. A fresh account, because the
   * stored one is shared by tests running alongside.
   */
  test('joins a second household and switches between them', async ({ page }) => {
    const account = createAccount()
    const second = ownHousehold('E2E Enkel')
    const name = tinker(`echo App\\Models\\Household::findOrFail(${second})->name;`).trim().split('\n').pop() ?? ''

    try {
      tinker(
        `$haushalt = App\\Models\\Household::findOrFail(${second});`
        + ` $person = App\\Models\\Person::withoutGlobalScope("household")->create(["household_id" => ${second}, "name" => "E2E Oma", "role" => "guest", "color" => "#0d9488"]);`
        + ` app(App\\Services\\Memberships::class)->invite($haushalt, $person, "${account.email}");`,
      )

      await openSignIn(page)
      await login(page, account)

      await openSettings(page, 'household')
      const invitation = page.getByTestId('household-invitation')
      await expect(invitation).toContainText(name)
      await invitation.getByTestId('household-accept').click()
      await expect(invitation).toHaveCount(0)

      const target = page.locator(`[data-testid="household"][data-value="${second}"]`)
      await Promise.all([page.waitForURL('/'), target.click()])
      await expect(page.getByTestId('menu-open')).toBeVisible()

      // The menu names the household it looks at and leads to the choice.
      const household = (await menu(page)).getByTestId('menu-household')
      await expect(household).toContainText(name)
      await household.click()
      await expect(page.locator(`[data-testid="household"][data-value="${second}"]`)).toHaveAttribute('aria-pressed', 'true')

      // A guest leaves; the person stays in the household with its history.
      await page.getByTestId('household-leave').click()
      await Promise.all([page.waitForURL('/'), page.getByTestId('household-leave').click()])
      await expect(page.getByTestId('menu-open')).toBeVisible()

      await openSettings(page)
      await expect(page.locator('[data-testid="settings-page"][data-value="household"]')).toHaveCount(0)
    }
    finally {
      dropHousehold(second)
    }
  })
})

/*
 * On the phone without the parent PIN: someone is signed in there, and the
 * action carries their name. On the wall the same path asks for the PIN.
 *
 * The title carries a timestamp and the task is removed again at the end:
 * the run works on the development database's data.
 */
test('creates a task on the phone and removes it again', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByTestId('tab').filter({ hasText: 'Aufgaben' }).click()

  const title = `E2E Handy ${Date.now()}`

  await page.getByTestId('task-new').first().click()

  const form = page.getByRole('dialog', { name: 'Neue Aufgabe' })
  await form.getByTestId('task-title').fill(title)
  await form.getByTestId('icon').first().click()
  await form.getByTestId('task-save').click()

  // Saved without an intermediate step — no PIN dialog in between.
  await expect(form).toBeHidden()

  // As on the wall: tapping the text opens the task, and from there the
  // phone offers changing it.
  const row = page.getByTestId('column-task').filter({ hasText: title })
  await expect(row).toBeVisible()

  await row.getByTestId('column-open-task').click()
  await page.getByTestId('task-details-edit').click()

  const update = page.getByRole('dialog', { name: 'Aufgabe ändern' })
  await update.getByTestId('task-remove').click()
  await update.getByTestId('task-remove').click()

  await expect(row).toHaveCount(0)
})

/*
 * A fresh picture per run, drawn in the browser: the development database
 * keeps what was uploaded before, and a known picture would be skipped.
 * The teardown removes what the throwaway account uploaded.
 */
test('uploads a photo for the frame and recognises it the second time', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByTestId('tab').filter({ hasText: 'Fotos' }).click()
  await page.getByTestId('photos-manage').click()

  const jpeg = await page.evaluate(() => {
    const canvas = document.createElement('canvas')
    canvas.width = 64
    canvas.height = 48
    const context = canvas.getContext('2d')!
    context.fillStyle = `hsl(${Math.floor(Math.random() * 360)} 70% 50%)`
    context.fillRect(0, 0, 64, 48)
    context.fillText(String(Date.now()), 2, 24)

    return canvas.toDataURL('image/jpeg').split(',')[1]!
  })
  const file = { name: 'IMG_20260801_101010.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg, 'base64') }
  const summary = page.getByTestId('upload-summary')

  await page.getByTestId('photo-input').setInputFiles([file, { name: 'clip.mp4', mimeType: 'video/mp4', buffer: Buffer.from('x') }])
  await expect(summary).toHaveText('1 hochgeladen')
  await expect(page.getByText('1 Datei übersprungen')).toBeVisible()

  await page.getByTestId('photo-input').setInputFiles([file])
  await expect(summary).toHaveText('1 hochgeladen · 1 schon vorhanden')
})

/*
 * The grid behind the upload: a long press selects, "Löschen" moves into
 * the trash, and from there the photo comes back or goes for good. The
 * picture is this run's own, so the run leaves nothing behind.
 */
test('selects a photo, moves it to the trash, restores and purges it', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByTestId('tab').filter({ hasText: 'Fotos' }).click()
  await page.getByTestId('photos-manage').click()

  const jpeg = await page.evaluate(() => {
    const canvas = document.createElement('canvas')
    canvas.width = 64
    canvas.height = 48
    const context = canvas.getContext('2d')!
    context.fillStyle = `hsl(${Math.floor(Math.random() * 360)} 70% 50%)`
    context.fillRect(0, 0, 64, 48)
    context.fillText(String(Date.now()), 2, 24)

    return canvas.toDataURL('image/jpeg').split(',')[1]!
  })

  // The grid sorts by the day taken, so the upload is found by its id, not its place.
  const [uploaded] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/photos') && response.request().method() === 'POST'),
    page.getByTestId('photo-input').setInputFiles([{ name: 'IMG_20260802_101010.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg, 'base64') }]),
  ])
  await expect(page.getByTestId('upload-summary')).toHaveText('1 hochgeladen')

  const { id } = await uploaded.json() as { id: number }
  const tile = page.locator(`[data-testid="photo-tile"][data-id="${id}"]`)
  await expect(tile).toBeVisible()
  await expect.poll(async () => tile.evaluate(element => (element.querySelector('img') as HTMLImageElement).complete)).toBe(true)

  async function hold(): Promise<void> {
    const box = (await tile.boundingBox())!
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
    await page.mouse.down()
    await page.waitForTimeout(700)
    await page.mouse.up()
  }

  // A plain tap opens the large view.
  await tile.click()
  await expect(page.getByTestId('photo-viewer')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('photo-viewer')).toHaveCount(0)

  await hold()
  const bar = page.getByTestId('selection-bar')
  await expect(bar).toContainText('1 ausgewählt')
  await expect(tile).toHaveAttribute('aria-pressed', 'true')
  await bar.getByTestId('selection-delete').click()
  await expect(tile).toHaveCount(0)

  // The trash has its own button and says how long the photo stays.
  await page.getByTestId('photo-trash').click()
  await expect(tile).toBeVisible()
  await expect(tile.getByTestId('days-left')).toHaveText('noch 30 Tage')

  await hold()
  await bar.getByTestId('selection-restore').click()
  await expect(tile).toHaveCount(0)
  await page.getByTestId('trash-back').click()
  await expect(tile).toBeVisible()

  // Delete again and empty it from the trash — two taps for "for good".
  await hold()
  await bar.getByTestId('selection-delete').click()
  await expect(tile).toHaveCount(0)
  await page.getByTestId('photo-trash').click()
  await hold()
  await bar.getByTestId('selection-purge').click()
  await expect(bar.getByTestId('selection-purge')).toHaveText(/Wirklich endgültig/)
  await bar.getByTestId('selection-purge').click()
  await expect(tile).toHaveCount(0)
  await page.getByTestId('trash-back').click()
  await expect(tile).toHaveCount(0)
})

/*
 * The library like a photo app: a heart in the large view, a new album
 * from there, the album among the collections. Photo and album are the
 * test's own and leave again.
 */
test('hearts a photo, puts it into a new album and finds it there', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByTestId('tab').filter({ hasText: 'Fotos' }).click()
  await page.getByTestId('photos-manage').click()

  const jpeg = await page.evaluate(() => {
    const canvas = document.createElement('canvas')
    canvas.width = 48
    canvas.height = 64
    const context = canvas.getContext('2d')!
    context.fillStyle = `hsl(${Math.floor(Math.random() * 360)} 70% 50%)`
    context.fillRect(0, 0, 48, 64)
    context.fillText(String(Date.now()), 2, 24)

    return canvas.toDataURL('image/jpeg').split(',')[1]!
  })

  const [uploaded] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/photos') && response.request().method() === 'POST'),
    page.getByTestId('photo-input').setInputFiles([{ name: 'IMG_20260803_101010.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg, 'base64') }]),
  ])
  const { id } = await uploaded.json() as { id: number }
  const album = `E2E Album ${Date.now()}`

  try {
    const tile = page.locator(`[data-testid="photo-tile"][data-id="${id}"]`)
    await tile.click()

    const viewer = page.getByTestId('photo-viewer')
    await viewer.getByTestId('viewer-heart').click()
    await expect(viewer.getByTestId('viewer-heart')).toHaveAttribute('aria-pressed', 'true')

    await viewer.getByTestId('viewer-album').click()
    await page.getByTestId('album-name').fill(album)
    await page.getByTestId('album-create').click()
    await expect(viewer.getByTestId('viewer-notice')).toHaveText('Zum Album hinzugefügt.')
    await page.keyboard.press('Escape')
    await expect(viewer).toHaveCount(0)

    // The heart shows on the tile, the album among the collections.
    await page.locator('[data-testid="photo-tab"][data-value="collections"]').click()
    await page.getByTestId('album-card').filter({ hasText: album }).click()
    await expect(tile).toBeVisible()

    await page.getByTestId('album-delete').click()
    await page.getByTestId('album-delete').click()
    await expect(page.getByTestId('album-card').filter({ hasText: album })).toHaveCount(0)
  }
  finally {
    tinker(`App\\Models\\PhotoAlbum::withoutGlobalScope("household")->where("name", "${album}")->delete();`
      + ` App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->whereKey(${id})->get()->each(function ($foto) { $foto->forceDelete(); });`)
  }
})

/*
 * Tidying up works on the phone too, behind the same two taps as on the
 * wall. The item is the test's own, so the run leaves no trace.
 */
test('clears ticked items from a list', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Listen' }).click()

  const list = page.locator('section').filter({ has: page.getByTestId('item-input') }).first()
  const title = `E2E ${Date.now()}`

  await list.getByTestId('item-input').fill(title)
  await list.getByRole('button', { name: 'Hinzufügen' }).click()

  const item = list.getByTestId('item').filter({ hasText: title })
  await item.locator('[aria-pressed]').click()
  await expect(item.locator('[aria-pressed]')).toHaveAttribute('aria-pressed', 'true')

  const clear = list.getByTestId('clear-done')
  await clear.click()
  await expect(clear).toHaveText('Wirklich alle erledigten entfernen?')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/done') && response.request().method() === 'DELETE' && response.ok()),
    clear.click(),
  ])
  await expect(item).toHaveCount(0)

  // Back on the lists after reloading: the phone starts elsewhere, and an
  // item that is not shown proves nothing.
  await page.reload()
  await (await menu(page)).getByRole('button', { name: 'Listen' }).click()
  await expect(page.getByTestId('item').first()).toBeVisible()
  await expect(page.getByTestId('item').filter({ hasText: title })).toHaveCount(0)
})

test('removes a single item from a list, as on the wall', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Listen' }).click()

  const list = page.locator('section').filter({ has: page.getByTestId('item-input') }).first()
  const title = `E2E ${Date.now()}`

  await list.getByTestId('item-input').fill(title)
  await list.getByRole('button', { name: 'Hinzufügen' }).click()

  const item = list.getByTestId('item').filter({ hasText: title })

  // Two steps: a passing thumb must not delete anything.
  await item.getByRole('button', { name: `„${title}“ entfernen` }).click()
  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/items/') && response.request().method() === 'DELETE' && response.ok()),
    item.getByRole('button', { name: /wirklich/i }).click(),
  ])
  await expect(item).toHaveCount(0)
})

test('plans a meal on the phone and removes it again', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Essen', exact: true }).click()

  const title = `E2E ${Date.now()}`
  const day = page.getByTestId('meal-day').first()

  await day.getByTestId('meal-plan').first().click()
  await page.getByRole('dialog').getByTestId('meal-title').fill(title)
  await page.getByRole('dialog').getByRole('button', { name: 'Eintragen' }).click()

  const meal = day.getByTestId('meal').filter({ hasText: title })
  await expect(meal).toBeVisible()

  await meal.getByRole('button', { name: `„${title}“ entfernen` }).click()
  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/meals/') && response.request().method() === 'DELETE' && response.ok()),
    meal.getByRole('button', { name: /wirklich/i }).click(),
  ])
  await expect(meal).toHaveCount(0)
})

/*
 * The stars tab as on the wall: tapping a child shows its progress on
 * every reward.
 */
test('shows the reward progress of a tapped child', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

  const rewards = page.getByTestId('reward')
  await expect(rewards.first()).toBeVisible()

  await page.getByTestId('points-person').first().click()
  await expect(page.getByTestId('reward-progress')).toHaveCount(await rewards.count())
})

/*
 * The day view on a phone: one row on top (day, filter, new), one column at
 * a time with room for its tasks, the next a swipe away, "Für alle" as the
 * last one. The toolbar above once left the columns a sliver.
 */
test('swipes through tall day columns on a phone', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Aufgaben' }).click()

  // View, done tasks and people sit in one sheet behind the filter button.
  const filter = page.getByTestId('task-filter')
  const sheet = page.getByTestId('task-filter-sheet')
  await expect(page.getByTestId('task-view')).toHaveCount(0)
  await filter.click()
  await sheet.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
  await sheet.getByTestId('task-filter-close').click()
  await expect(sheet).toHaveCount(0)
  await expect(filter).toHaveAttribute('data-active', 'false')

  const first = page.getByTestId('day-column').first()
  await expect(first).toBeVisible()
  expect((await first.boundingBox())?.height ?? 0).toBeGreaterThan(300)

  const everyone = page.locator('[data-testid="day-column"][aria-label="Für alle"]')

  if (await everyone.count() > 0) {
    await expect(everyone).toHaveCount(1)
    await everyone.scrollIntoViewIfNeeded()
    await expect(everyone).toBeInViewport()
  }

  // Hiding a person marks the button, "show all" clears it again.
  await filter.click()
  await sheet.getByTestId('people-chip').last().click()
  await page.keyboard.press('Escape')
  await expect(sheet).toHaveCount(0)
  await expect(filter).toHaveAttribute('data-active', 'true')

  await filter.click()
  await sheet.getByTestId('people-all').click()
  await sheet.getByTestId('task-filter-close').click()
  await expect(filter).toHaveAttribute('data-active', 'false')
})

/*
 * The install band: Chromium's event is faked (a headless browser never
 * fires it), "later" keeps it away for seven days, then it asks again.
 */
test('offers to install the app and asks again after seven days', async ({ page }) => {
  // Answered, so the push sheet does not take the band's turn.
  await page.addInitScript(() => localStorage.setItem('fahoba-push-asked', '1'))

  const band = page.getByTestId('install-offer')

  async function browserOffersInstall(): Promise<void> {
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.evaluate(() => dispatchEvent(Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
      prompt: async () => {},
      userChoice: Promise.resolve({ outcome: 'dismissed' }),
    })))
  }

  await page.goto('/')
  await browserOffersInstall()
  await expect(band).toBeVisible()
  await expect(band.getByTestId('install-now')).toBeVisible()
  await band.getByTestId('install-later').click()
  await expect(band).toHaveCount(0)

  await page.reload()
  await browserOffersInstall()
  await expect(band).toHaveCount(0)

  // Seven days on: the "later" lies a week back.
  await page.evaluate(() => {
    const week = 7 * 24 * 60 * 60 * 1000

    localStorage.setItem('fahoba-install-snoozed', String(Number(localStorage.getItem('fahoba-install-snoozed')) - week))
  })
  await page.reload()
  await browserOffersInstall()
  await expect(band).toBeVisible()
})

/*
 * Notifications are offered once per phone after signing in; the settings
 * keep the switch. The development server has no VAPID keys, so the key
 * answer is faked — the offer is what is tested, not the push service.
 */
test('offers notifications once and keeps the switch in the settings', async ({ page }) => {
  await page.route('**/api/push/key', route => route.fulfill({
    json: { configured: true, public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U' },
  }))
  await page.goto('/')

  const offer = page.getByTestId('push-offer')
  await expect(offer).toBeVisible()
  await offer.getByTestId('push-offer-no').click()
  await expect(offer).toHaveCount(0)

  // Answered once, it stays away — the switch is in the settings.
  await page.reload()
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(offer).toHaveCount(0)

  await openSettings(page)
  await expect(page.getByTestId('push')).toHaveAttribute('aria-checked', 'false')
  await expect(page.getByTestId('push')).toBeEnabled()
})

/*
 * Switching on takes a moment, so it shows: the button spins, then the sheet
 * confirms and the server is asked for a sample. Permission and push service
 * are stubbed — a headless browser has neither.
 */
test('shows progress and success when switching notifications on', async ({ page }) => {
  await page.addInitScript(() => {
    Notification.requestPermission = async () => 'granted'
    Object.defineProperty(PushManager.prototype, 'subscribe', {
      value: async () => {
        const endpoint = `https://push.example.test/e2e-${Date.now()}`

        return { endpoint, toJSON: () => ({ endpoint, keys: { p256dh: 'p256dh', auth: 'auth' } }) }
      },
    })
  })
  await page.route('**/api/push/key', route => route.fulfill({
    json: { configured: true, public_key: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U' },
  }))

  // Held back a moment, so the progress can be seen at all.
  let release: () => void = () => {}
  const held = new Promise<void>((resolve) => {
    release = resolve
  })
  await page.route('**/api/push/subscribe', async (route) => {
    await held
    await route.continue()
  })

  await page.goto('/')

  const offer = page.getByTestId('push-offer')
  const yes = offer.getByTestId('push-offer-yes')
  const posted = page.waitForRequest(request => request.url().endsWith('/api/push/subscribe') && request.method() === 'POST')

  await yes.click()
  await expect(yes).toHaveAttribute('aria-busy', 'true')
  await expect(yes).toContainText('Wird eingeschaltet')

  release()
  expect((await posted).postDataJSON()).toMatchObject({ confirm: true })

  await expect(offer.getByRole('heading', { name: 'Benachrichtigungen sind an' })).toBeVisible()
  await offer.getByTestId('push-offer-close').click()
  await expect(offer).toHaveCount(0)
})

/*
 * Colourful icons and animations are the account's, like on the wall the
 * tablet's. Switched back at the end: the stored account is shared.
 */
test('switches to colourful tab icons and stills animations', async ({ page }) => {
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
    page.goto('/'),
  ])
  await menu(page)
  await expect(page.getByTestId('tab-emoji')).toHaveCount(0)
  await page.getByTestId('settings').click()
  await page.locator('[data-testid="settings-page"][data-value="appearance"]').click()

  const put = () => page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok())
  const look = (cell: string) => page.locator(`[data-testid="preference"][data-cell="${cell}"]`)

  await Promise.all([put(), look('colored_icons').click()])
  await expect(look('colored_icons')).toHaveAttribute('aria-checked', 'true')
  await closeSettings(page)
  await menu(page)
  await expect(page.getByTestId('tab-emoji').first()).toBeVisible()
  await page.getByTestId('settings').click()
  await page.locator('[data-testid="settings-page"][data-value="appearance"]').click()

  await Promise.all([put(), look('animations').click()])
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off')

  await Promise.all([put(), look('colored_icons').click()])
  await Promise.all([put(), look('animations').click()])
  await expect(look('animations')).toHaveAttribute('aria-checked', 'true')
  await closeSettings(page)
  await menu(page)
  await expect(page.getByTestId('tab').first()).toBeVisible()
  await expect(page.getByTestId('tab-emoji')).toHaveCount(0)
})

/*
 * The menu slides in like the wall's bar: today's weather on top when the
 * account chose it, and the hours behind a tap. The answer is fixed, the
 * demo household has no place.
 */
/*
 * Writing in the calendar, as on the wall but without a PIN: the account's
 * permissions decide. The title carries a timestamp; the test deletes it.
 */
test('creates an event on the phone and deletes it again', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Kalender' }).click()

  const title = `E2E Termin ${Date.now()}`

  await page.getByTestId('event-new').click()

  const dialog = page.getByRole('dialog', { name: 'Neuer Termin' })
  await dialog.getByRole('textbox').first().fill(title)
  await dialog.getByRole('button', { name: 'Speichern' }).click()
  await expect(dialog).toHaveCount(0)

  const event = page.getByTestId('calendar').getByTestId('event').filter({ hasText: title })
  await event.first().click()
  await page.getByTestId('event-edit').click()

  const update = page.getByRole('dialog', { name: 'Termin ändern' })
  await update.getByRole('button', { name: 'Löschen' }).click()
  await update.getByRole('button', { name: 'Wirklich löschen' }).click()

  await expect(event).toHaveCount(0)
})

test('opens a recipe on the phone as a sheet', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Rezepte', exact: true }).click()

  await page.getByTestId('recipe-card').filter({ hasText: 'Kaiserschmarrn' }).click()

  const sheet = page.getByRole('dialog', { name: 'Kaiserschmarrn' })
  await expect(sheet.getByText('Rosinen', { exact: true })).toBeVisible()
  await expect(sheet.getByTestId('ingredients-to-list')).toBeVisible()

  await page.keyboard.press('Escape')
  await expect(sheet).toHaveCount(0)
})

/*
 * A family recipe photographed instead of typed: only the title and the
 * picture of the card, then it sits among what the family made itself.
 */
test('saves a recipe as a photo and finds it among the own ones', async ({ page }) => {
  const title = `E2E Omas Karte ${Date.now()}`

  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Rezepte', exact: true }).click()
  await page.getByTestId('recipe-new').click()

  const editor = page.getByTestId('recipe-editor')
  await editor.getByTestId('recipe-title').fill(title)

  const jpeg = await page.evaluate(() => {
    const canvas = document.createElement('canvas')
    canvas.width = 64
    canvas.height = 48
    canvas.getContext('2d')!.fillRect(0, 0, 64, 48)

    return canvas.toDataURL('image/jpeg').split(',')[1]!
  })

  await editor.getByTestId('recipe-page-input').setInputFiles([{ name: 'karte.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg, 'base64') }])
  await editor.getByTestId('recipe-save').click()

  const sheet = page.getByRole('dialog', { name: title })
  await expect(sheet.getByTestId('recipe-page-list').locator('img')).toHaveCount(1)
  await expect(sheet.getByText('keine Zutaten hinterlegt', { exact: false })).toHaveCount(0)

  await page.keyboard.press('Escape')
  await page.getByTestId('recipe-list-own').click()
  await expect(page.getByTestId('recipe-card').filter({ hasText: title })).toBeVisible()
})

/*
 * A parent decides a child's request on the phone; before, that was only
 * possible at the wall. Rejecting, because it books nothing.
 */
test('rejects a reward request on the phone', async ({ page }) => {
  const id = tinker(
    '$haushalt = App\\Models\\Household::orderBy("id")->first();'
    + ' $kind = App\\Models\\Person::withoutGlobalScope("household")->where("household_id", $haushalt->id)->where("role", "child")->firstOrFail();'
    + ' $belohnung = App\\Models\\Reward::withoutGlobalScope("household")->where("household_id", $haushalt->id)->firstOrFail();'
    + ' echo App\\Models\\RewardRedemption::withoutGlobalScope("household")->forceCreate(["household_id" => $haushalt->id,'
    + ' "reward_id" => $belohnung->id, "person_id" => $kind->id, "cost" => 1])->id;',
  ).trim().split('\n').pop()

  try {
    await page.goto('/')
    await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

    const request = page.locator(`[data-testid="request"][data-id="${id}"]`)
    await request.getByTestId('reject').click()
    await expect(request).toHaveCount(0)
  }
  finally {
    tinker(`App\\Models\\RewardRedemption::withoutGlobalScope("household")->whereKey(${Number(id)})->delete();`)
  }
})

/** A request of one star for a child of the demo household; returns its id. */
function oneStarRequest(): number {
  return Number(tinker(
    '$haushalt = App\\Models\\Household::orderBy("id")->first();'
    + ' $kind = App\\Models\\Person::withoutGlobalScope("household")->where("household_id", $haushalt->id)->where("role", "child")->firstOrFail();'
    + ' $belohnung = App\\Models\\Reward::withoutGlobalScope("household")->where("household_id", $haushalt->id)->firstOrFail();'
    + ' echo App\\Models\\RewardRedemption::withoutGlobalScope("household")->forceCreate(["household_id" => $haushalt->id,'
    + ' "reward_id" => $belohnung->id, "person_id" => $kind->id, "cost" => 1])->id;',
  ).trim().split('\n').pop())
}

/** Takes the request away again, with the star it may have debited. */
function dropRequest(id: number): void {
  tinker(
    `App\\Models\\PointEntry::withoutGlobalScope("household")->where("reward_redemption_id", ${id})->delete();`
    + ` App\\Models\\RewardRedemption::withoutGlobalScope("household")->whereKey(${id})->delete();`,
  )
}

/** On the phone the parent's account decides — no PIN, and it goes through. */
test('approves a reward request on the phone without a PIN', async ({ page }) => {
  const id = oneStarRequest()

  try {
    await page.goto('/')
    await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

    const request = page.locator(`[data-testid="request"][data-id="${id}"]`)

    await Promise.all([
      page.waitForResponse(response => response.url().endsWith(`/api/wall/redemptions/${id}/approve`) && response.status() === 200),
      request.getByTestId('approve').click(),
    ])

    await expect(request).toHaveCount(0)
    await expect(page.getByRole('dialog', { name: 'Eltern-PIN' })).toHaveCount(0)
    await expect(page.getByText('Das hat nicht geklappt')).toHaveCount(0)
  }
  finally {
    dropRequest(id)
  }
})

/**
 * Approved on the wall while the phone still lists it: the phone says why
 * and drops the stale row instead of a bare failure.
 */
test('explains a request that was decided elsewhere', async ({ page }) => {
  const id = oneStarRequest()

  try {
    await page.goto('/')
    await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

    const request = page.locator(`[data-testid="request"][data-id="${id}"]`)
    await expect(request).toBeVisible()

    tinker(`App\\Models\\RewardRedemption::withoutGlobalScope("household")->whereKey(${id})->update(["rejected_at" => now()]);`)

    await request.getByTestId('approve').click()

    await expect(page.getByText('Diese Anfrage ist bereits entschieden.')).toBeVisible()
    await expect(request).toHaveCount(0)
  }
  finally {
    dropRequest(id)
  }
})

/*
 * Taking stars away is one star at most; the info beside the minus says why
 * and what helps instead, so parents read it where they decide.
 */
test('explains beside minus one why there is no bigger deduction', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByRole('button', { name: 'Sterne' }).click()

  await page.getByTestId('bonus').first().click()

  const dialog = page.getByTestId('bonus-dialog')
  await expect(dialog.getByTestId('bonus-points')).toHaveText([/\+1$/, /\+2$/, /\+3$/, /\+5$/, /-1$/])

  await dialog.getByTestId('deduct-info').click()
  await expect(dialog.getByTestId('deduct-info')).toHaveAttribute('aria-expanded', 'true')
  await expect(dialog.getByTestId('deduct-explained')).toContainText('Wiedergutmachen')

  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
})

test.describe('menu', () => {
  test.use({ serviceWorkers: 'block' })

  test('shows the weather on top and opens its hours', async ({ page }) => {
    await page.route('**/api/wall/weather', route => route.fulfill({
      json: {
        weather: {
          current: 14, min: 9, max: 23, code: 0, label: 'Klar', symbol: '☀️', unit: 'C',
          today: { date: '2026-09-30', min: 9, max: 23, code: 0, label: 'Klar', symbol: '☀️', hours: [
            { time: '14:00', temperature: 14, code: 0, label: 'Klar', symbol: '☀️', rain: 0 },
          ] },
          days: [],
        },
        hint: null,
      },
    }))
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
      page.goto('/'),
    ])

    const put = () => page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok())

    await openSettings(page, 'start')
    await Promise.all([put(), page.locator('[data-testid="preference"][data-cell="menu_top"][data-value="weather"]').click()])
    await closeSettings(page)

    await menu(page)
    await expect(page.getByTestId('menu-weather')).toContainText('14°')
    await page.getByTestId('menu-weather').click()
    await expect(page.getByTestId('weather-hour')).toHaveCount(1)
    await page.keyboard.press('Escape')

    // Back to the mark: the stored account is shared by the tests.
    await openSettings(page, 'start')
    await Promise.all([put(), page.locator('[data-testid="preference"][data-cell="menu_top"][data-value="logo"]').click()])
  })
})

/*
 * The areas along the bottom instead of only behind the burger. The burger
 * stays for settings and the admin.
 */
test('puts the menu along the bottom on request', async ({ page }) => {
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'GET'),
    page.goto('/'),
  ])

  const put = () => page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok())

  await expect(page.getByTestId('bottom-menu')).toHaveCount(0)

  await openSettings(page, 'start')
  await Promise.all([put(), page.locator('[data-testid="preference"][data-cell="phone_menu"][data-value="bottom"]').click()])
  await closeSettings(page)

  const bar = page.getByTestId('bottom-menu')
  await bar.locator('[data-testid="bottom-tab"][data-value="timetable"]').click()
  await expect(page.getByRole('heading', { level: 1, name: 'Schule', exact: true })).toBeVisible()
  await expect(bar.locator('[data-testid="bottom-tab"][data-value="timetable"]')).toHaveAttribute('aria-current', 'page')
  await expect(page.getByTestId('menu-open')).toBeVisible()

  // Back to the drawer: the stored account is shared by the tests.
  await openSettings(page, 'start')
  await Promise.all([put(), page.locator('[data-testid="preference"][data-cell="phone_menu"][data-value="drawer"]').click()])
})

/*
 * The admin under the app's sign-in: "Verwaltung" in the menu opens it
 * without a second login, "Zur App" leads back.
 */
test('opens the admin from the menu and comes back', async ({ page }) => {
  await page.goto('/')
  await (await menu(page)).getByTestId('admin-link').click()

  await expect(page).toHaveURL(/\/admin$/)
  await page.getByTestId('to-app').click()

  await expect(page).toHaveURL(/fahoba\.test\/$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Start' })).toBeVisible()
})

/* Usable on a phone: no page in the admin scrolls sideways at 390 pixels. */
test('shows every admin page without sideways scrolling', async ({ page }) => {
  // 29 pages; right after a build each compiles its views first, some take seconds.
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 390, height: 844 })

  const pages = [
    '/admin', '/admin/accounts', '/admin/accounts/create', '/admin/api-access', '/admin/calendar-subscriptions',
    '/admin/calendars', '/admin/checklists',
    '/admin/checklists/create', '/admin/connections', '/admin/devices', '/admin/devices/create',
    '/admin/export', '/admin/households', '/admin/lessons', '/admin/lessons/create', '/admin/lessons/list', '/admin/people',
    '/admin/people/create', '/admin/photos', '/admin/photos/create', '/admin/point-entries', '/admin/profile',
    '/admin/recipes', '/admin/recipes/create', '/admin/rewards', '/admin/rewards/create',
    '/admin/school-closures', '/admin/statistics', '/admin/tasks', '/admin/tasks/create', '/admin/webhooks',
  ]

  // The interfaces' pages exist only while switched on, and they are off by default.
  const household = 'App\\Models\\Household::orderBy("id")->first()'
  tinker(`${household}->update(["opted_in_features" => ["api", "fahoba/ics-feed", "fahoba/webhooks"]]);`)

  try {
    for (const path of pages) {
      await page.goto(path)
      await page.waitForLoadState('networkidle')

      // Signed in through the app's cookie, not sent to the login page.
      expect(new URL(page.url()).pathname, path).toBe(path)

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
      expect.soft(overflow, path).toBeLessThanOrEqual(0)
    }
  }
  finally {
    tinker(`${household}->update(["opted_in_features" => null]);`)
  }
})

/*
 * A tick forgotten on the wall is caught up on the phone: the parent pages
 * back to yesterday, and the child gets the stars, not the parent. In a
 * household of its own: it counts what the child has.
 */
test('catches up on a forgotten task of a past day', async ({ page }) => {
  const household = ownHousehold('Ida')

  try {
    const output = tinker(
      `$ida = App\\Models\\Person::withoutHouseholdScope()->where("household_id", ${household})->where("name", "Ida")->firstOrFail();`
      + ` App\\Models\\Task::create(["household_id" => ${household}, "title" => "E2E Gestern", "points" => 2, "person_id" => $ida->id,`
      + ' "rrule" => "FREQ=DAILY", "due_on" => now("Europe/Vienna")->subDay()->toDateString(), "active" => true]);'
      + ` echo "token=" . App\\Models\\User::whereRelation("memberships", "household_id", ${household})->firstOrFail()->createToken("e2e")->plainTextToken;`,
    )
    const token = output.match(/token=(\S+)/)?.[1]

    if (!token) {
      throw new Error(`No token in output: ${output}`)
    }

    await page.context().clearCookies()
    await page.context().addCookies([{
      name: 'fahoba_user_token',
      value: token,
      url: 'https://fahoba.test',
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    }])
    await page.goto('/')
    await (await menu(page)).getByTestId('tab').filter({ hasText: 'Aufgaben' }).click()

    await page.getByRole('button', { name: 'Voriger Tag' }).click()
    await expect(page.getByTestId('past-day')).toBeVisible()

    const row = page.getByTestId('column-task').filter({ hasText: 'E2E Gestern' })
    await row.getByTestId('column-open-task').click()

    const details = page.getByTestId('task-details')
    await expect(details.getByTestId('past-task')).toHaveCount(0)

    await Promise.all([
      page.waitForResponse(response => response.url().includes('/completion') && response.status() === 201),
      details.getByTestId('task-details-complete').click(),
    ])

    await page.getByTestId('task-filter').click()
    await page.getByTestId('show-done').click()
    await page.getByTestId('task-filter-close').click()
    await expect(row.getByTestId('done-mark')).toBeVisible()

    const stars = tinker(
      `$ida = App\\Models\\Person::withoutHouseholdScope()->where("household_id", ${household})->where("name", "Ida")->firstOrFail();`
      + ' echo "stars=" . App\\Models\\PointEntry::withoutHouseholdScope()->where("person_id", $ida->id)->whereNotNull("task_completion_id")->sum("points");',
    )
    expect(stars).toContain('stars=2')
  }
  finally {
    dropHousehold(household)
  }
})
