import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { dropHousehold, ownHousehold, pair, tinker } from './helpers'

/*
 * Checks the ground rule from section 8 of the specification: the wall must
 * never show an empty page. If the server fails, the tablet shows the last
 * state with a timestamp -- visibly stale instead of seemingly empty.
 *
 * The outage is simulated by rejecting the requests, not by stopping DDEV:
 * that is faster, more precise and leaves the environment intact.
 */


/*
 * A Wednesday in a school week of the sample timetable (`SchoolSeeder`):
 * lessons are rules, so the date stays valid, while today may be a holiday
 * or fall in the holidays.
 */
const SCHOOL_DAY = new Date('2026-10-14T10:00:00+02:00')

/*
 * After pairing, not before: pair() waits for the sample events, which the
 * seeders place around the day they ran. The reload mounts the wall anew,
 * so it takes its day from the fixed clock.
 */
async function onSchoolDay(page: Page): Promise<void> {
  await page.clock.setFixedTime(SCHOOL_DAY)
  await page.reload()
}

/**
 * The device settings as on the phone: an overview, a topic one tap
 * further (`appearance`, `start`, `views`, `rest`). Escape leads from a
 * topic back to the overview, a second one closes.
 */
async function deviceSettings(page: Page, topic?: string): Promise<Locator> {
  await page.getByTestId('theme').click()

  const dialog = page.getByTestId('settings-sheet')
  await expect(dialog).toBeVisible()

  if (topic) {
    await dialog.locator(`[data-testid="settings-page"][data-value="${topic}"]`).click()
  }

  return dialog
}

test('pairs and shows the calendar', async ({ page }) => {
  /*
   * A fixed day and an all-day event of its own: the seeders place theirs
   * relative to the day they ran, twice under the same title, and the month
   * boundary moved them in and out of the month shown.
   */
  const title = `E2E Ganztag ${Date.now()}`
  tinker(
    'app(App\\Support\\HouseholdContext::class)->forId(App\\Models\\Household::orderBy("id")->first()->id, function () {'
    + ' $start = Carbon\\CarbonImmutable::parse("2026-10-16", "Europe/Vienna");'
    + ' App\\Models\\Event::create(["calendar_id" => App\\Models\\Calendar::where("is_active", true)->orderBy("id")->first()->id,'
    + ` "title" => "${title}", "starts_at" => $start, "ends_at" => $start->addDay(), "all_day" => true]); });`,
  )

  try {
    await pair(page)
    await onSchoolDay(page)
    await page.getByRole('button', { name: 'Kalender', exact: true }).click()

    // The wall starts with three days; the month shows the full data set.
    await expect(page.getByRole('button', { name: '3 Tage' })).toHaveAttribute('aria-pressed', 'true')

    await page.getByRole('button', { name: 'Monat' }).click()
    await expect(page.getByRole('heading', { name: 'Oktober 2026' })).toBeVisible()

    // What can go wrong: an all-day event slips over the day boundary.
    const event = page.getByTestId('event').filter({ hasText: title })
    await expect(event).toHaveCount(1)
    await expect(page.locator('[data-testid="day"][data-iso="2026-10-16"]').getByTestId('event').filter({ hasText: title })).toBeVisible()
  }
  finally {
    tinker(`App\\Models\\Event::withoutGlobalScope("household")->where("title", "${title}")->delete();`)
  }
})

test('remembers the selected view across a restart', async ({ page }) => {
  await pair(page)

  // Exactly the switcher, not the paging buttons "Vorige/Nächste Woche".
  const weekToggle = page.getByRole('button', { name: 'Woche', exact: true })

  await weekToggle.click()
  await expect(weekToggle).toHaveAttribute('aria-pressed', 'true')

  await page.reload()

  // After a restart the wall is back on the home page; the remembered
  // setting applies to the calendar.
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()

  await expect(page.getByRole('button', { name: 'Woche', exact: true }))
    .toHaveAttribute('aria-pressed', 'true')

  // Leave things tidy: the setting now lives on the device, no longer in
  // browser storage — otherwise the next run would start on the week.
  await page.getByRole('button', { name: '3 Tage', exact: true }).click()
})

/*
 * The core of the week view: an event stands where it takes place.
 *
 * Checked against the displayed time, not against a fixed value: the
 * placement depends on the household's time zone, and a hard-coded pixel
 * value would end up testing the test machine's time zone as well.
 */
test('places events in the hour grid at their time', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Woche', exact: true }).click()

  const event = page.getByTestId('event').filter({ hasText: 'Elternabend' }).first()
  await expect(event).toBeVisible()

  const time = (await event.innerText()).match(/(\d{2}):(\d{2})/)

  expect(time).not.toBeNull()

  // Grid from 6:00 at 68 px per hour.
  const minutes = Number(time![1]) * 60 + Number(time![2]) - 6 * 60
  const expected = minutes / 60 * 68

  const top = await event.evaluate(element => Number.parseFloat((element as HTMLElement).style.top))
  expect(Math.abs(top - expected)).toBeLessThan(2)
})

test('shows the last state instead of an empty page when the server is unreachable', async ({ page }) => {
  await pair(page)

  const before = await page.getByTestId('event').count()
  expect(before).toBeGreaterThan(0)

  /*
   * From here the server is gone. setOffline instead of page.route: the
   * service worker sits between page and network, and page.route does not
   * capture its requests -- the page would really still be online. A
   * context-wide network outage hits both and matches the real case.
   */
  await page.context().setOffline(true)

  // Switching views triggers a request that now fails.
  await page.getByRole('button', { name: 'Woche' }).click()

  // The core assertion: something is still hanging on the wall.
  await expect(page.getByTestId('event').first()).toBeVisible()

  // And it identifies itself as stale instead of pretending to be current.
  await expect(page.getByText(/Stand von \d{2}:\d{2} Uhr/)).toBeVisible()
})

test('reports the expired access including the provider', async ({ page }) => {
  await pair(page)

  // The sample source is set to invalid_grant: without a notice the wall
  // would look normal and show old events unnoticed for days. The provider
  // is named as well, because a household may have connected several.
  await expect(page.getByTestId('household-notice').filter({ hasText: /Die Verbindung zu Google Kalender.* ist abgelaufen/ })).toBeVisible()
})

test('keeps the selected theme on the device', async ({ page }) => {
  await pair(page)

  await deviceSettings(page, 'appearance')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/theme') && response.request().method() === 'PUT'),
    page.getByRole('radio', { name: 'Dunkel' }).click(),
  ])

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  /*
   * Reload without browser storage: the head script only remembers the
   * theme for the first moment. It stays dark afterwards only if the server
   * stored the choice for this device.
   */
  await page.evaluate(() => localStorage.clear())
  await page.reload()

  await deviceSettings(page, 'appearance')
  await expect(page.getByRole('radio', { name: 'Dunkel' })).toHaveAttribute('aria-checked', 'true')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/*
 * A design pack (`tagwerk/example-theme`, dev only) chosen on the device:
 * its stylesheet arrives from the server and its tokens win over the
 * core's; Tagwerk's own takes them back.
 */
test('a device picks a design pack and back', async ({ page }) => {
  await pair(page)
  const dialog = await deviceSettings(page, 'appearance')
  const accent = () => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim())

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/theme') && response.request().method() === 'GET'),
    dialog.locator('[data-cell="design"][data-value="tagwerk/example-theme"]').click(),
  ])

  await expect(page.locator('html')).toHaveAttribute('data-design', 'tagwerk/example-theme')
  await expect.poll(accent).toMatch(/^#(0a6680|7cc6dc)$/)

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/theme') && response.request().method() === 'GET'),
    dialog.locator('[data-cell="design"][data-value="core"]').click(),
  ])

  await expect(page.locator('html')).not.toHaveAttribute('data-design', /.+/)
  await expect.poll(accent).toMatch(/^#(c2410c|fdba74)$/)
})

test('checks off through the task and asks who it was', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()

  // The list instead of the day view: there every task appears exactly once,
  // regardless of whom it belongs to.
  await page.getByTestId('task-view').filter({ hasText: 'Liste' }).click()

  // Done tasks are hidden by default; this test follows one through its states.
  const showDone = page.getByTestId('show-done')
  await expect(showDone).toHaveAttribute('aria-pressed', 'false')
  await showDone.click()
  await expect(showDone).toHaveAttribute('aria-pressed', 'true')

  /*
   * Specifically an open occurrence instead of the very first one: which
   * tasks are already done depends on the data — and with a completed one
   * the test would check the revert, which needs no question.
   * Held by its key, not its position: a ticked task moves to the end.
   */
  const open = page.getByTestId('task').filter({ hasNot: page.getByTestId('done-mark') }).first()
  await expect(open).toBeVisible()

  const row = page.locator(`[data-occurrence="${await open.getAttribute('data-occurrence')}"]`)
  const mark = row.getByTestId('done-mark')
  const details = page.getByTestId('task-details')

  // No box any more: the tap opens the task, and nothing is saved yet.
  await expect(row.getByTestId('complete')).toHaveCount(0)
  await row.getByTestId('task-open').click()
  await expect(details).toBeVisible()
  await details.getByRole('button', { name: 'Schließen' }).click()
  await expect(mark).toHaveCount(0)

  await row.getByTestId('task-open').click()
  await details.getByTestId('task-details-complete').click()

  /*
   * "Hey, bist du das?" only exists with an assigned person. Without an
   * assignee the list of names is open right away; a task per person asks
   * nothing — all paths lead to the same result.
   */
  const ja = page.getByTestId('completed-yes')
  const person = page.getByTestId('completed-person').first()

  await expect(ja.or(person).or(mark)).toBeVisible()

  if (await ja.isVisible()) {
    await ja.click()
  }
  else if (await person.isVisible()) {
    await person.click()
  }

  // Only a mark: it shows the state and does nothing on its own.
  await expect(mark).toBeVisible()
  await expect(row.getByTestId('task-open').getByTestId('done-mark')).toBeVisible()

  // Ticked tasks move below the open ones of their day.
  await expect.poll(() => row.evaluate(node => [...node.parentElement!.children]
    .slice([...node.parentElement!.children].indexOf(node) + 1)
    .some(sibling => !sibling.querySelector('[data-testid="done-mark"]'))))
    .toBe(false)

  // Hidden again, the done task leaves the list — until shown once more.
  await showDone.click()
  await expect(row).toHaveCount(0)
  await showDone.click()
  await expect(mark).toBeVisible()

  // Revert through the task as well — without a question, because
  // reverting credits nobody. Otherwise the next run finds it done.
  await row.getByTestId('task-open').click()
  await details.getByTestId('task-details-complete').click()
  await expect(mark).toHaveCount(0)

  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
})

/*
 * Ticking in a row: the previous tick's reload lands while the next task's
 * dialog is open and replaces the rows. The tick must still show at once,
 * not with the reload after it.
 */
test('ticks a task whose row a reload replaced while its dialog was open', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await page.getByTestId('task-view').filter({ hasText: 'Liste' }).click()

  const showDone = page.getByTestId('show-done')

  await showDone.click()
  await expect(showDone).toHaveAttribute('aria-pressed', 'true')

  const open = page.getByTestId('task').filter({ hasNot: page.getByTestId('done-mark') }).first()

  await expect(open).toBeVisible()

  const row = page.locator(`[data-occurrence="${await open.getAttribute('data-occurrence')}"]`)
  const mark = row.getByTestId('done-mark')
  const details = page.getByTestId('task-details')

  await row.getByTestId('task-open').click()
  await expect(details).toBeVisible()

  // The wall reloads when it becomes visible — as with any other reload.
  const reloaded = page.waitForResponse(response => response.url().includes('/api/wall/tasks?'))

  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))
  await reloaded

  await details.getByTestId('task-details-complete').click()

  const ja = page.getByTestId('completed-yes')
  const person = page.getByTestId('completed-person').first()

  await expect(ja.or(person).or(mark)).toBeVisible()

  if (await ja.isVisible()) {
    await ja.click()
  }
  else if (await person.isVisible()) {
    await person.click()
  }

  await expect(mark).toBeVisible()

  // Revert, so the next run finds it open.
  await row.getByTestId('task-open').click()
  await details.getByTestId('task-details-complete').click()
  await expect(mark).toHaveCount(0)

  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
})

/*
 * Writing a recipe is not in the tablet's defaults, but the tablet offers
 * it and the parent PIN unlocks it — like tasks.
 */
test('writes a recipe on the wall after the parent PIN', async ({ page }) => {
  const title = `E2E Wandrezept ${Date.now()}`

  await pair(page)
  await page.getByRole('button', { name: 'Rezepte', exact: true }).click()
  await page.getByTestId('recipe-new').click()

  const editor = page.getByTestId('recipe-editor')
  await editor.getByTestId('recipe-title').fill(title)
  await editor.getByTestId('recipe-ingredients').fill('200 g Mehl')
  await editor.getByTestId('recipe-save').click()

  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()
  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }
  await pin.getByRole('button', { name: 'OK' }).click()

  const sheet = page.getByRole('dialog', { name: title })
  await expect(sheet.getByText('Mehl')).toBeVisible()
  await expect(sheet.getByTestId('recipe-edit')).toBeVisible()
})

test('opens a recipe and puts the ingredients on the shopping list', async ({ page }) => {
  await pair(page)

  await page.getByRole('button', { name: 'Rezepte' }).click()

  // From the built-in book — without any external service.
  await expect(page.getByTestId('recipe-card').filter({ hasText: 'Kaiserschmarrn' })).toBeVisible()

  await page.getByTestId('recipe-card').filter({ hasText: 'Kaiserschmarrn' }).click()

  const dialog = page.getByRole('dialog', { name: 'Kaiserschmarrn' })
  await expect(dialog.getByText('Rosinen', { exact: true })).toBeVisible()

  await page.getByTestId('ingredients-to-list').click()

  /*
   * Whether the ingredient is new or already on the list from an earlier
   * run depends on the development database's data. What is checked is the
   * result: it is on the list.
   */
  await page.getByRole('button', { name: 'Listen', exact: true }).click()
  await expect(page.getByText('Rosinen')).toBeVisible()
})

/*
 * A recipe goes into the plan from the recipe: a week to page, a meal to tap.
 * Three weeks ahead, where the demo plan has nothing — and removed again.
 */
test('plans a recipe for a day of a later week', async ({ page }) => {
  await pair(page)

  await page.getByRole('button', { name: 'Rezepte' }).click()
  await page.getByTestId('recipe-card').filter({ hasText: 'Kaiserschmarrn' }).click()
  await page.getByTestId('recipe-plan').click()

  const picker = page.getByTestId('meal-slot-picker')
  await expect(picker.getByTestId('slot-week-title')).toHaveText('Diese Woche')

  for (let step = 0; step < 3; step++) {
    await picker.getByTestId('slot-week-forward').click()
  }

  // Seven days ahead in a later week, none of them past.
  await expect(picker.getByTestId('slot-day')).toHaveCount(7)

  const day = picker.getByTestId('slot-day').nth(2)
  const date = await day.getAttribute('data-date')

  await day.locator('[data-testid="slot"][data-kind="dinner"]').click()
  await expect(picker).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: 'Eingeplant:' })).toBeVisible()

  await page.getByRole('button', { name: 'Essen', exact: true }).click()
  await expect(page.getByTestId('week-title')).toHaveText('Diese Woche')
  await page.getByTestId('week-forward').click()
  await expect(page.getByTestId('week-title')).toHaveText('Nächste Woche')
  await page.getByTestId('week-forward').click()
  await page.getByTestId('week-forward').click()
  await expect(page.getByTestId('meal-day')).toHaveCount(7)

  const meal = page.locator(`[data-testid="meal-day"][data-date="${date}"]`).getByTestId('meal').filter({ hasText: 'Kaiserschmarrn' })
  await expect(meal.first()).toBeVisible()

  // Removed again — together with what an aborted earlier run left there.
  while (await meal.count() > 0) {
    await meal.first().getByRole('button', { name: '„Kaiserschmarrn“ entfernen' }).click()
    await Promise.all([
      page.waitForResponse(response => response.url().includes('/api/wall/meals/') && response.request().method() === 'DELETE' && response.ok()),
      meal.first().getByRole('button', { name: /wirklich/i }).click(),
    ])
  }

  await expect(meal).toHaveCount(0)

  await page.getByTestId('week-today').click()
  await expect(page.getByTestId('week-title')).toHaveText('Diese Woche')
})

/*
 * A planned recipe opens from the plan: the instructions, and only there the
 * button for the shopping list. Someone reads along at the stove, so the
 * wall does not rest while it is open — and does again once it is closed.
 */
test('opens a planned recipe from the plan and stays awake while it is open', async ({ page }) => {
  await page.clock.install()
  await pair(page)

  const settings = await deviceSettings(page, 'rest')
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    settings.locator('[data-testid="rest-area"][data-value="calendar"]').click(),
  ])
  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    settings.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
  ])
  await page.keyboard.press('Escape')
  await page.keyboard.press('Escape')

  // Planned four weeks ahead, away from the demo plan and the test before.
  await page.getByRole('button', { name: 'Rezepte' }).click()
  await page.getByTestId('recipe-card').filter({ hasText: 'Kaiserschmarrn' }).click()
  await page.getByTestId('recipe-plan').click()

  const picker = page.getByTestId('meal-slot-picker')

  for (let step = 0; step < 4; step++) {
    await picker.getByTestId('slot-week-forward').click()
  }

  const day = picker.getByTestId('slot-day').nth(4)
  const date = await day.getAttribute('data-date')

  await day.locator('[data-testid="slot"][data-kind="dinner"]').click()
  await expect(picker).toHaveCount(0)

  await page.getByRole('button', { name: 'Essen', exact: true }).click()

  for (let step = 0; step < 4; step++) {
    await page.getByTestId('week-forward').click()
  }

  const meal = page.locator(`[data-testid="meal-day"][data-date="${date}"]`).getByTestId('meal').filter({ hasText: 'Kaiserschmarrn' })
  await expect(meal.first()).toBeVisible()

  // The ingredients no longer sit on the plan, only in the recipe.
  await expect(meal.first().getByTestId('ingredients-to-list')).toHaveCount(0)
  await meal.first().getByTestId('meal-recipe').click()

  const recipe = page.getByRole('dialog', { name: 'Kaiserschmarrn' })
  await expect(recipe.getByText('Rosinen', { exact: true })).toBeVisible()
  await expect(recipe.getByTestId('recipe-plan')).toHaveCount(0)

  await recipe.getByTestId('ingredients-to-list').click()
  await expect(recipe.getByTestId('recipe-notice')).toBeVisible()

  const saver = page.getByTestId('screensaver')

  await page.clock.runFor(61_000)
  await expect(recipe).toBeVisible()
  await expect(saver).toHaveCount(0)

  await page.keyboard.press('Escape')
  await expect(recipe).toHaveCount(0)

  await page.clock.runFor(61_000)
  await expect(saver).toBeVisible()
  await saver.click()
  await expect(saver).toHaveCount(0)

  // Removed again — together with what an aborted earlier run left there.
  while (await meal.count() > 0) {
    await meal.first().getByRole('button', { name: '„Kaiserschmarrn“ entfernen' }).click()
    await Promise.all([
      page.waitForResponse(response => response.url().includes('/api/wall/meals/') && response.request().method() === 'DELETE' && response.ok()),
      meal.first().getByRole('button', { name: /wirklich/i }).click(),
    ])
  }
})

test('shows the timetable and switches between the children', async ({ page }) => {
  await pair(page)
  await onSchoolDay(page)

  // By test id, not by name: the sample data contains an
  // event "Musikschule", and it carries the same name.
  await page.getByTestId('mode').filter({ hasText: 'Schule' }).click()

  const children = page.getByRole('tab')
  await expect(children).toHaveCount(2)

  // The first child is preselected; its plan shows without further action.
  await expect(children.first()).toHaveAttribute('aria-selected', 'true')
  await expect(page.locator('[data-testid^="lesson-"]').filter({ hasText: 'Mathematik' }).first()).toBeVisible()

  await children.nth(1).click()
  await expect(children.nth(1)).toHaveAttribute('aria-selected', 'true')
})

/*
 * The calendar band is off by default: the timetable has its own view, and
 * in the calendar it would otherwise crowd out the events.
 */
test('shows the school lessons in the calendar and remembers the toggle', async ({ page }) => {
  await pair(page)
  await onSchoolDay(page)
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()
  await page.getByRole('button', { name: 'Woche', exact: true }).click()

  const toggle = page.getByTestId('school-band')
  await expect(toggle).toHaveAttribute('aria-pressed', 'false')
  await expect(page.getByTestId('school-lesson')).toHaveCount(0)

  await toggle.click()
  await expect(page.getByTestId('school-lesson').first()).toBeVisible()

  // Every lesson carries the child's face, or nobody knows whose it is.
  await expect(page.getByTestId('school-lesson-person')).toHaveCount(await page.getByTestId('school-lesson').count())

  await page.reload()
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()
  await expect(page.getByTestId('school-band')).toHaveAttribute('aria-pressed', 'true')

  // Leave things tidy: the next run expects the band to be off again.
  await page.getByTestId('school-band').click()
  await expect(page.getByTestId('school-band')).toHaveAttribute('aria-pressed', 'false')
})


/*
 * Creating on the device, as it happens in everyday life: someone stands at
 * the wall and thinks of something. The path leads through the parent PIN —
 * so it is also checked that the job is retried by itself afterwards and
 * nobody has to tap twice. Changing and removing follow while the PIN is
 * still unlocked.
 *
 * The title carries a timestamp; should the run break off, the teardown
 * removes it.
 */
test('creates, changes and removes a task on the tablet', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()

  // The list, because there every task appears exactly once — including one
  // without an assignee, which lands under "Für alle" in the day view.
  await page.getByTestId('task-view').filter({ hasText: 'Liste' }).click()

  const title = `E2E Zähne ${Date.now()}`

  await page.getByTestId('task-new').first().click()

  const form = page.getByRole('dialog', { name: 'Neue Aufgabe' })
  await form.getByTestId('task-title').fill(title)
  await form.getByTestId('icon').first().click()
  await form.getByTestId('task-points').nth(2).click()
  await form.getByTestId('task-save').click()

  // The keys are laid out 1 to 9 in this order; the PIN is 1234.
  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()

  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }

  await pin.getByRole('button', { name: 'OK' }).click()

  const row = page.getByTestId('task').filter({ hasText: title })
  await expect(row).toBeVisible()
  await expect(row).toContainText('2')

  await row.getByTestId('task-open').click()

  const details = page.getByTestId('task-details')
  await expect(details.getByRole('heading', { name: title })).toBeVisible()
  // Every popup closes with Escape.
  await page.keyboard.press('Escape')
  await expect(details).toHaveCount(0)

  // Changing: the time of day sits folded away under "more options".
  // The wall's details offer no editing; the row's pencil does.
  await row.getByTestId('task-open').click()
  await expect(details.getByTestId('task-details-edit')).toHaveCount(0)
  await page.keyboard.press('Escape')
  await row.getByTestId('task-edit').click()

  const update = page.getByRole('dialog', { name: 'Aufgabe ändern' })
  await update.getByTestId('task-more').click()
  await update.getByTestId('task-daypart').filter({ hasText: 'Morgens' }).click()
  await update.getByTestId('task-save').click()
  await expect(update).toHaveCount(0)
  await expect(row).toContainText('morgens')

  // A holiday: ticked without stars, nobody asked who — and taken back.
  await row.getByTestId('task-open').click()
  await details.getByTestId('task-details-skip').click()
  await expect(details).toHaveCount(0)
  await expect(page.getByTestId('completed-person')).toHaveCount(0)
  await page.getByTestId('show-done').click()
  await expect(row.getByTestId('done-mark')).toBeVisible()
  await row.getByTestId('task-open').click()
  await expect(details.getByTestId('task-skipped')).toHaveText('Ohne Sterne abgehakt')
  await expect(details.getByTestId('undo-stars')).toHaveCount(0)
  await details.getByTestId('task-details-complete').click()
  await expect(row.getByTestId('done-mark')).toHaveCount(0)

  // Removing is two-step, through the pencil in the row.
  await row.getByTestId('task-edit').click()
  await update.getByTestId('task-remove').click()
  await update.getByTestId('task-remove').click()
  await expect(row).toHaveCount(0)

  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
})

/*
 * The path via the "+" in the header. Tapping a day in the grid leads to the
 * same dialog, but it is nothing anyone discovers on their own.
 */
test('creates an event via the plus button and deletes it again', async ({ page }) => {
  await pair(page)

  const title = `E2E Termin ${Date.now()}`

  await page.getByTestId('event-new').click()

  const dialog = page.getByRole('dialog', { name: 'Neuer Termin' })
  await dialog.getByRole('textbox').first().fill(title)
  await dialog.getByRole('button', { name: 'Speichern' }).click()

  const event = page.getByTestId('event').filter({ hasText: title })
  await expect(event.first()).toBeVisible()

  await event.first().click()

  const update = page.getByRole('dialog', { name: 'Termin ändern' })
  await update.getByRole('button', { name: 'Löschen' }).click()
  await update.getByRole('button', { name: 'Wirklich löschen' }).click()

  await expect(event).toHaveCount(0)
})

/*
 * The day view: one column per person. Whoever checks off there picks no
 * name — the column already says it. If a column is empty, "Super!" shows.
 */
test('checks off in the day view without picking a name and praises the empty column', async ({ page }) => {
  // Kiosk tablets often have the system's animations off; the device's switch decides.
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()

  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
  await expect(page.getByTestId('day-title')).toHaveText('Heute')

  // Done rows stay in sight, so the test can take them back afterwards.
  await page.getByTestId('show-done').click()
  await expect(page.getByTestId('show-done')).toHaveAttribute('aria-pressed', 'true')

  /*
   * "Für alle" still asks for a name, hence a person column. It is held by
   * its label: a filter on "still has open items" would lose it exactly when
   * the test has checked off.
   */
  const columnName = await page
    .locator('[data-testid="day-column"]:not([aria-label="Für alle"])')
    .filter({ has: page.getByTestId('column-task').filter({ hasNot: page.getByTestId('done-mark') }) })
    .first()
    .getAttribute('aria-label')

  expect(columnName).not.toBeNull()

  const column = page.locator(`[data-testid="day-column"][aria-label="${columnName}"]`)
  const details = page.getByTestId('task-details')

  // The row's label says the state: the title, or the title and "erledigt".
  const open = (title: string) => column.locator(`[data-testid="column-open-task"][aria-label="${title}"]`)
  const done = (title: string) => column.locator(`[data-testid="column-open-task"][aria-label="${title}, erledigt"]`)

  const openTitles = await column
    .getByTestId('column-task')
    .filter({ hasNot: page.getByTestId('done-mark') })
    .getByTestId('column-open-task')
    .evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-label') ?? ''))

  await expect(column.getByTestId('column-complete')).toHaveCount(0)

  for (const [index, title] of openTitles.entries()) {
    await open(title).click()
    await details.getByTestId('task-details-complete').click()
    await expect(done(title).getByTestId('done-mark')).toBeVisible()

    // Every tick is cheered: stars, fireworks and a word.
    if (index === 0) {
      await expect(page.getByTestId('cheer')).toBeVisible()
      await expect.poll(() => page.locator('[aria-hidden="true"] > canvas').first().evaluate((canvas: HTMLCanvasElement) => {
        const pixels = canvas.getContext('2d')!.getImageData(0, 0, canvas.width, canvas.height).data

        return pixels.some((value, index) => index % 4 === 3 && value > 0)
      })).toBe(true)
    }
  }

  await expect(column.getByTestId('column-done')).toBeVisible()
  await expect(column.getByTestId('column-open')).toHaveText('0 offen')

  // Leave things tidy: the run shares the database with development.
  for (const title of openTitles) {
    await done(title).click()
    await details.getByTestId('task-details-complete').click()
    await expect(open(title)).toBeVisible()
  }

  await expect(column.getByTestId('column-done')).toHaveCount(0)
})

/*
 * The photo frame. Without uploaded pictures and without a connected folder
 * a hint shows there — not an error message: on a wall in the hallway
 * nobody can do anything about it.
 */
test('shows the photo frame with a hint while there are no photos', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Fotos' }).click()

  const frame = page.getByTestId('photo-frame')
  const hint = page.getByText('Noch keine Fotos.')

  await expect(frame.or(hint).first()).toBeVisible()

  await page.getByRole('button', { name: 'Kalender', exact: true }).click()
})

/*
 * At rest the photo takes the whole screen: no bar, no header, the time in
 * the picture's corner. The photo is this test's own, drawn in portrait so
 * the blurred copy has space to fill, and removed again afterwards.
 */
test('rests on photos edge to edge with the time in a corner', async ({ page }) => {
  tinker(
    '$image = imagecreatetruecolor(600, 900); imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));'
    + ' ob_start(); imagejpeg($image); $content = ob_get_clean();'
    + ' App\\Models\\Photo::withoutGlobalScope("household")->forceCreate(['
    + '"household_id" => App\\Models\\Household::orderBy("id")->first()->id,'
    + ' "path" => app(App\\Services\\PhotoImage::class)->store($content),'
    + ' "caption" => "E2E Rahmen", "fingerprint" => hash("sha256", uniqid("e2e", true))]);',
  )

  try {
    await page.clock.install()
    await pair(page)

    const dialog = await deviceSettings(page, 'rest')

    // Only the photos in the carousel.
    for (const area of await dialog.getByTestId('rest-area').all()) {
      const photos = await area.getAttribute('data-value') === 'photos'
      const pressed = await area.getAttribute('aria-pressed') === 'true'

      if (photos !== pressed) {
        await Promise.all([
          page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
          area.click(),
        ])
      }
    }

    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      dialog.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
    ])
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    await page.clock.runFor(61_000)
    await expect(page.getByTestId('screensaver')).toBeVisible()

    const frame = page.getByTestId('photo-frame')
    await expect(frame).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(0)
    await expect(page.getByTestId('photo-clock')).toBeVisible()
    await expect(page.getByTestId('photo-caption')).toContainText('E2E Rahmen')

    const box = (await frame.boundingBox())!
    const viewport = page.viewportSize()!
    expect(Math.round(box.width)).toBe(viewport.width)
    expect(Math.round(box.height)).toBe(viewport.height)
  }
  finally {
    tinker('App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->where("caption", "like", "E2E %")->get()->each(function ($foto) { $foto->forceDelete(); });')
  }
})

/*
 * The frame as this tablet set it up: a mosaic instead of one picture,
 * controls after a tap, a heart by double tap. The photos are this test's
 * own; the preference belongs to the freshly paired tablet only.
 */
test('shows the frame as a mosaic with controls and hearts a picture', async ({ page }) => {
  tinker(
    'foreach (range(1, 5) as $index) { $image = imagecreatetruecolor(600, 900);'
    + ' imagefill($image, 0, 0, imagecolorallocate($image, 40 * $index, 120, 200)); ob_start(); imagejpeg($image); $content = ob_get_clean();'
    + ' App\\Models\\Photo::withoutGlobalScope("household")->forceCreate(['
    + '"household_id" => App\\Models\\Household::orderBy("id")->first()->id,'
    + '"path" => app(App\\Services\\PhotoImage::class)->store($content),'
    + '"caption" => "E2E Mosaik $index", "fingerprint" => hash("sha256", uniqid("e2e", true))]); }',
  )

  try {
    await pair(page)
    const dialog = await deviceSettings(page, 'photos')

    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      dialog.locator('[data-testid="preference"][data-cell="photo_style"][data-value="mosaic"]').click(),
    ])
    await expect(dialog.locator('[data-testid="preference"][data-cell="photo_style"][data-value="mosaic"]')).toHaveAttribute('aria-pressed', 'true')
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    await page.getByRole('button', { name: 'Fotos' }).first().click()
    const frame = page.getByTestId('photo-frame')
    await expect(frame).toHaveAttribute('data-layout', 'mosaic')
    await expect(page.getByTestId('photo-mosaic-tile')).toHaveCount(5)

    // One tap shows the controls; pausing keeps them.
    await frame.click({ position: { x: 40, y: 40 } })
    await expect(page.getByTestId('photo-controls')).toBeVisible()
    await page.getByTestId('photo-pause').click()
    await expect(page.getByTestId('photo-paused')).toBeVisible()

    // A double tap hearts the picture under the finger.
    const favorite = page.waitForResponse(response => /\/api\/wall\/photos\/\d+\/favorite$/.test(response.url()))
    await page.getByTestId('photo-mosaic-tile').first().dblclick()
    expect((await (await favorite).json() as { favorite: boolean }).favorite).toBe(true)
  }
  finally {
    tinker('App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->where("caption", "like", "E2E %")->get()->each(function ($foto) { $foto->forceDelete(); });')
  }
})

/*
 * "An der Wand zeigen" from a phone: the picture appears over everything
 * and goes with a tap. A household of its own: the picture would otherwise
 * cover the walls of every test running beside this one.
 */
test('shows a picture sent from a phone over everything', async ({ page }) => {
  const household = ownHousehold('Ida')

  try {
    tinker(
      '$image = imagecreatetruecolor(900, 600); imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40)); ob_start(); imagejpeg($image); $content = ob_get_clean();'
      + ` $photo = App\\Models\\Photo::withoutGlobalScope("household")->forceCreate(["household_id" => ${household},`
      + '"path" => app(App\\Services\\PhotoImage::class)->store($content),'
      + '"caption" => "E2E Gesendet", "fingerprint" => hash("sha256", uniqid("e2e", true))]);'
      + ` app(App\\Services\\Photos\\PhotoCast::class)->show(${household}, app(App\\Services\\Photos\\PhotoLibrary::class)->entry("builtin",`
      + ' App\\Integrations\\Photos\\Builtin\\BuiltinPhotoSource::describe($photo)));',
    )

    await pair(page, household)
    const cast = page.getByTestId('photo-cast')
    await expect(cast).toBeVisible()
    await expect(cast).toContainText('E2E Gesendet')
    await cast.click()
    await expect(cast).toHaveCount(0)
  }
  finally {
    tinker(`Illuminate\\Support\\Facades\\Cache::forget("photo-cast:${household}");`
      + ` App\\Models\\Photo::withoutGlobalScope("household")->where("household_id", ${household})->get()->each(function ($foto) { $foto->forceDelete(); });`)
    dropHousehold(household)
  }
})

/**
 * The home page: place tiles, drag them, remove them again.
 *
 * Rearranging sits behind the parent PIN — a moved tile is seen afterwards
 * by everyone walking past the tablet.
 */
test('creates a tile, drags it and removes it again', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Start' }).click()

  const raster = page.getByTestId('dashboard-grid')
  await expect(raster).toBeVisible()

  await page.getByTestId('dashboard-edit').click()

  // The keys are laid out 1 to 9 in this order; the PIN is 1234.
  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()

  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }

  await pin.getByRole('button', { name: 'OK' }).click()

  await expect(page.getByTestId('dashboard-edit')).toHaveAttribute('aria-pressed', 'true')

  await page.getByTestId('widget-picker').click()
  await page.getByTestId('widget-offer').filter({ hasText: 'Familie' }).click()

  const tile = page.locator('[data-testid="dashboard-tile"][data-type="members"]')
  await expect(tile).toHaveCount(1)

  /*
   * Dragged below all other tiles: it is certainly free there, and a tile
   * dragged onto an occupied area deliberately snaps back.
   */
  const placements = await page.getByTestId('dashboard-tile').evaluateAll(node => node.map((el) => {
    const style = (el as HTMLElement).style

    return {
      y: Number.parseInt(style.gridRow.split('/')[0]!.trim(), 10),
      h: Number.parseInt(style.gridRow.split('span')[1]!.trim(), 10),
    }
  }))

  const bottom = Math.max(...placements.map(placement => placement.y + placement.h))
  const own = await tile.evaluate(el => Number.parseInt((el as HTMLElement).style.gridRow.split('/')[0]!.trim(), 10))

  const gridCell = await raster.boundingBox()
  const rowCount = Math.max(6, bottom - 1)
  const rowHeight = gridCell!.height / rowCount

  const handle = await tile.boundingBox()
  const target = (bottom - own) * rowHeight

  await page.mouse.move(handle!.x + handle!.width / 2, handle!.y + 20)
  await page.mouse.down()
  await page.mouse.move(handle!.x + handle!.width / 2, handle!.y + 20 + target, { steps: 8 })
  await page.mouse.up()

  // Below all others: one row lower than the lowest edge so far.
  const dragged = `${bottom} / span 2`

  await expect.poll(async () => tile.evaluate(el => (el as HTMLElement).style.gridRow)).toBe(dragged)

  /*
   * The drag has to survive the restart — otherwise it only lived in the
   * browser. Polled, because the service worker first delivers the cached
   * state and only then the fresh one.
   */
  await page.reload()

  await expect(tile).toHaveCount(1)
  await expect.poll(async () => tile.evaluate(el => (el as HTMLElement).style.gridRow)).toBe(dragged)

  // Leave things tidy: the run works on the development database.
  await page.getByTestId('dashboard-edit').click()
  await tile.getByTestId('tile-remove').click()

  await expect(page.locator('[data-testid="dashboard-tile"][data-type="members"]')).toHaveCount(0)
})

/*
 * Upright, twelve columns made every tile a sliver: there the grid has six,
 * the landscape tiles set anew in reading order, arranging left to landscape.
 */
test('sets the dashboard on half the columns on an upright tablet', async ({ page }) => {
  await page.setViewportSize({ width: 800, height: 1280 })
  await pair(page)
  await page.getByRole('button', { name: 'Start' }).click()

  const raster = page.getByTestId('dashboard-grid')
  await expect(raster).toHaveCSS('grid-template-columns', /^(\S+ ){5}\S+$/)
  await expect(page.getByTestId('dashboard-edit')).toHaveCount(0)
  await expect(page.getByTestId('dashboard-arrange-hint')).toHaveText('Anordnen lässt sich das Dashboard im Querformat.')

  // Nothing pokes out sideways.
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(800)
})

/*
 * The single day shows columns per person. That is the view someone stands
 * in front of in the morning, wanting to know who has to go where today — a
 * shared column does not answer that.
 */
test('shows a day in the calendar with one column per person and hides people', async ({ page }) => {
  await pair(page)

  // Counted only once the day's events are in: the "Familie" column appears
  // with them, and a count taken earlier would not match the one at the end.
  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/agenda') && response.ok()),
    page.getByRole('button', { name: 'Tag', exact: true }).click(),
  ])

  const columns = page.getByTestId('person-column')
  await expect(columns.first()).toBeVisible()

  const before = await columns.count()
  expect(before).toBeGreaterThan(1)

  // Hiding a person takes their column with it: the filter applies to the
  // events and thus also to what the day puts side by side.
  const chip = page.getByTestId('people-chip').filter({ hasNotText: 'Familie' }).first()

  await chip.click()
  await expect(chip).toHaveAttribute('aria-pressed', 'true')
  await expect(columns).toHaveCount(1)

  // Back to "all": an empty selection shows everyone again.
  await chip.click()
  await expect(columns).toHaveCount(before)

  await page.getByRole('button', { name: '3 Tage', exact: true }).click()
})

/*
 * Yesterday stays visible: what was left open, what got done — but a past
 * day is history, so nothing there ticks any more.
 */
test('looks back at past task days without ticking', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()
  await expect(page.getByTestId('day-title')).toHaveText('Heute')
  await expect(page.getByTestId('past-day')).toHaveCount(0)

  await page.getByRole('button', { name: 'Voriger Tag' }).click()
  await expect(page.getByTestId('day-title')).not.toHaveText('Heute')
  await expect(page.getByTestId('past-day')).toBeVisible()

  // The demo household has a past full of tasks, done ones among them.
  await page.getByTestId('show-done').click()
  await page.getByTestId('column-task').first().click()

  const details = page.getByTestId('task-details')
  await expect(details).toBeVisible()
  await expect(details.getByTestId('past-task')).toBeVisible()
  await expect(details.getByTestId('task-details-complete')).toHaveCount(0)
  await page.keyboard.press('Escape')

  await page.getByRole('button', { name: 'Nächster Tag' }).click()
  await expect(page.getByTestId('day-title')).toHaveText('Heute')
  await expect(page.getByTestId('past-day')).toHaveCount(0)
  await page.getByTestId('show-done').click()
})

test('shows only the chosen people in the task day view', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()

  const columns = page.getByTestId('day-column')

  // Counted only once the tasks are in: "Für alle" appears with them.
  await expect(page.getByTestId('column-task').first()).toBeVisible()

  const before = await columns.count()
  expect(before).toBeGreaterThan(1)

  // The same bar as over the calendar: one tap narrows to that person.
  const chip = page.getByTestId('people-chip').filter({ hasNotText: 'Für alle' }).first()
  // The name span: the avatar is aria-hidden, the progress sits in sr-only text.
  const name = (await chip.locator('span > span').first().textContent())?.trim() ?? ''

  await chip.click()
  await expect(chip).toHaveAttribute('aria-pressed', 'true')
  await expect(columns).toHaveCount(1)
  await expect(columns.first()).toHaveAttribute('aria-label', name)

  await chip.click()
  await expect(columns).toHaveCount(before)
})

/* The tablet in a child's room comes up showing that child again. */
test('keeps the task filter after a restart', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()

  const chip = page.getByTestId('people-chip').filter({ hasNotText: 'Für alle' }).first()
  // The name span: the avatar is aria-hidden, the progress sits in sr-only text.
  const name = (await chip.locator('span > span').first().textContent())?.trim() ?? ''

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    chip.click(),
  ])

  await page.reload()
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await expect(page.getByTestId('people-chip').filter({ hasText: name })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByTestId('day-column')).toHaveCount(1)
})

/* The demo household pays a day bonus: each child's column shows how close it is. */
test('shows the day bonus as a bar over the column', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await page.getByTestId('task-view').filter({ hasText: 'Tag' }).click()

  const bar = page.getByTestId('column-day-bonus').first()
  await expect(bar).toBeVisible()
  await expect(bar).toContainText('+2')
})

test('jumps from the tasks tile to that person and keeps the choice', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Start', exact: true }).click()

  const row = page.getByTestId('widget-tasks-person').first()
  await expect(row).toBeVisible()
  // The name's own line: without a photo the avatar's initial comes first.
  const name = (await row.innerText()).split('\n').map(line => line.trim()).find(line => line.length > 1)!

  await row.click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Aufgaben')
  const chip = page.getByTestId('people-chip').filter({ hasText: name })
  await expect(chip).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByTestId('people-chip').filter({ hasNotText: name }).first()).toHaveAttribute('aria-pressed', 'false')

  // Away and back: the choice stays until someone changes it.
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
  await expect(chip).toHaveAttribute('aria-pressed', 'true')

  await chip.click()
})

test('opens an event from the calendar tile', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Start', exact: true }).click()

  const event = page.locator('[data-testid="dashboard-tile"][data-type="calendar"] [data-testid="event"]').first()
  await expect(event).toBeVisible()
  await event.click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
})

test('shows the calendar week in the hour grid', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Woche', exact: true }).click()

  await expect(page.getByTestId('calendar-week').first()).toContainText(/\d+\s*KW/)
})

test('runs a timer from the clock tile and rings at the end', async ({ page }) => {
  await page.clock.install()
  await pair(page)
  await page.getByRole('button', { name: 'Start', exact: true }).click()

  await page.getByTestId('clock-open-timer').click()
  const tools = page.getByTestId('clock-tools')
  await tools.getByTestId('timer-preset').first().click()
  await expect(tools.getByTestId('timer-time')).toHaveText('01:00')
  await tools.getByTestId('timer-start').click()

  // Closing the window does not stop the timer.
  await page.keyboard.press('Escape')
  await expect(tools).toHaveCount(0)

  await page.clock.runFor(61_000)

  await expect(tools).toBeVisible()
  await expect(tools.getByRole('alert')).toHaveText('Die Zeit ist um!')
  await tools.getByTestId('timer-dismiss').click()
  await expect(tools.getByTestId('timer-time')).toHaveText('00:00')

  await tools.getByTestId('clock-tab-stopwatch').click()
  await tools.getByTestId('stopwatch-start').click()
  await page.clock.runFor(3_000)
  await tools.getByTestId('stopwatch-stop').click()
  await expect(tools.getByTestId('stopwatch-time')).toHaveText('00:03')
})

test('sets the timer by swiping minutes and seconds', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Start', exact: true }).click()
  await page.getByTestId('clock-open-timer').click()
  const tools = page.getByTestId('clock-tools')

  async function swipe(testId: string, dy: number): Promise<void> {
    const box = await tools.getByTestId(testId).boundingBox()
    if (box === null) {
      throw new Error(`${testId} is not visible`)
    }
    const x = box.x + box.width / 2
    const y = box.y + box.height / 2
    await page.mouse.move(x, y)
    await page.mouse.down()
    await page.mouse.move(x, y + dy, { steps: 10 })
    await page.mouse.up()
  }

  // 20 px per step: five steps up, one step down.
  await swipe('timer-minutes', -100)
  await expect(tools.getByTestId('timer-time')).toHaveText('05:00')
  await swipe('timer-seconds', 20)
  await expect(tools.getByTestId('timer-time')).toHaveText('05:59')

  await tools.getByTestId('timer-minutes').press('ArrowDown')
  await expect(tools.getByTestId('timer-time')).toHaveText('04:59')
})

test('closes the device dialog with Escape', async ({ page }) => {
  await pair(page)

  const dialog = await deviceSettings(page, 'views')
  await expect(dialog.getByRole('heading', { level: 2 })).toHaveText('Ansichten')

  // The first Escape leads back to the overview (appearance, start, views,
  // photo frame, resting mode), the second closes.
  await page.keyboard.press('Escape')
  await expect(dialog.getByTestId('settings-page')).toHaveCount(5)

  await page.keyboard.press('Escape')
  await expect(page.getByTestId('settings-sheet')).toHaveCount(0)
})

/*
 * Every device brings its own preferences. The start screen is checked
 * because it is the only one that shows only after a restart — and that is
 * exactly the case the setting lives on the server for.
 */
test("remembers the device's start screen", async ({ page }) => {
  await pair(page)

  const dialog = await deviceSettings(page, 'start')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    dialog.locator('[data-testid="preference"][data-cell="start_area"][data-value="lists"]').click(),
  ])

  await page.reload()

  // Without further action on the lists instead of the dashboard.
  await expect(page.getByRole('button', { name: 'Listen', exact: true })).toHaveAttribute('aria-current', 'page')

  // Leave things tidy: the next run expects the home page.
  await deviceSettings(page, 'start')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    dialog.locator('[data-testid="preference"][data-cell="start_area"][data-value="dashboard"]').click(),
  ])
})

/**
 * The menu per tablet: hiding an area needs the parent PIN, and a reload
 * keeps it. Each device is paired fresh, so the change stays on this one.
 */
test('hides an area in the menu behind the parent PIN', async ({ page }) => {
  await pair(page)

  const bar = page.getByRole('navigation', { name: 'Bereiche' })
  await expect(bar.getByRole('button', { name: 'Listen', exact: true })).toBeVisible()

  const dialog = await deviceSettings(page, 'start')
  await dialog.locator('[data-testid="menu-entry"][data-value="lists"] [data-testid="menu-visible"]').click()

  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()

  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT' && response.ok()),
    pin.getByRole('button', { name: 'OK' }).click(),
  ])

  await expect(bar.getByRole('button', { name: 'Listen', exact: true })).toHaveCount(0)

  await page.reload()

  await expect(bar.getByRole('button', { name: 'Kalender', exact: true })).toBeVisible()
  await expect(bar.getByRole('button', { name: 'Listen', exact: true })).toHaveCount(0)
})

test('shows today as an hour grid in the calendar tile', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Start' }).click()

  const tile = page.locator('[data-testid="dashboard-tile"][data-type="calendar"]')
  test.skip(await tile.count() === 0, 'The dashboard of the demo household has no calendar tile.')

  await expect(tile.getByTestId('widget-calendar-grid')).toBeVisible()
  await expect(tile.getByTestId('day')).toHaveCount(1)
})

/*
 * The resting wall: after the chosen minutes without a touch it turns dark
 * and runs its carousel; the first touch only wakes it. The browser clock is
 * wound forward instead of waiting minutes.
 */
test('rests after a while and wakes on the first touch', async ({ page }) => {
  await page.clock.install()
  await pair(page)

  const dialog = await deviceSettings(page, 'rest')

  for (const area of ['calendar', 'tasks', 'timetable']) {
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      dialog.locator(`[data-testid="rest-area"][data-value="${area}"]`).click(),
    ])
  }

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    dialog.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
  ])
  // Day and night alike, in steps of five: the test does not depend on the hour it runs.
  for (const cell of ['screensaver_dim', 'screensaver_dim_night']) {
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      dialog.locator(`[data-testid="screensaver-dim"][data-cell="${cell}"]`).fill('65'),
    ])
  }

  // While choosing, the wall shows how dark it will be, then lets go of it.
  const preview = page.getByTestId('dim-preview')
  await expect(preview).toHaveCSS('opacity', '0.65')
  await page.clock.runFor(2_000)
  await expect(preview).toBeHidden()

  await page.keyboard.press('Escape')
  await page.keyboard.press('Escape')

  const bar = page.getByRole('navigation', { name: 'Bereiche' })
  await expect(bar).toBeVisible()

  await page.clock.runFor(61_000)

  const saver = page.getByTestId('screensaver')
  await expect(saver).toBeVisible()
  await expect(bar).toBeHidden()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/\w/)

  // A dark veil as strong as chosen lies over the page on top of the dark theme.
  await expect(saver).toHaveCSS('opacity', '0.65')

  // The carousel moves on to the tasks after the interval.
  await page.clock.runFor(31_000)
  await expect(page.getByRole('heading', { level: 1, name: 'Aufgaben' })).toBeVisible()

  // The timetable shows one child after the other, one interval each.
  await page.clock.runFor(31_000)
  await expect(page.getByRole('heading', { level: 1, name: 'Schule' })).toBeVisible()
  const selected = page.locator('[data-testid^="timetable-child-"][aria-selected="true"]')
  const first = await selected.getAttribute('data-testid')
  await page.clock.runFor(31_000)
  await expect(selected).not.toHaveAttribute('data-testid', first ?? '')

  await saver.click()
  await expect(saver).toBeHidden()
  await expect(bar).toBeVisible()
})

/**
 * Extra stars: the star-plus button first asks for the parent PIN, then the
 * popup wants an amount and a reason — without a reason nothing is given.
 * The reason carries "E2E" so the teardown removes the booking again.
 */
test('gives extra stars with a reason after the parent PIN', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Sterne', exact: true }).click()

  await page.getByTestId('bonus').first().click()

  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()
  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }
  await pin.getByRole('button', { name: 'OK' }).click()

  const bonus = page.getByTestId('bonus-dialog')
  await expect(bonus).toBeVisible()
  await expect(bonus.getByTestId('bonus-save')).toBeDisabled()

  const reason = `E2E Zimmer aufgeräumt ${Date.now()}`
  await bonus.getByTestId('bonus-points').filter({ hasText: '+3' }).click()
  await bonus.getByTestId('bonus-reason').fill(reason)
  await bonus.getByTestId('bonus-save').click()

  await expect(bonus).toBeHidden()

  // The history is closed at first: it is for looking up, not for looking at.
  const booking = page.locator('li').filter({ hasText: reason })
  await expect(booking).toHaveCount(0)
  await page.getByTestId('bookings-toggle').click()
  await expect(booking).toContainText('+3')

  // Just booked: under "Heute", with its time.
  await expect(page.getByTestId('booking-day').first()).toHaveText('Heute')
  await expect(booking.getByTestId('booking-time')).toHaveText(/^\d{2}:\d{2}$/)

  // Filtered on the server: only deductions, then back to everything.
  const bookings = page.getByTestId('booking')
  await page.getByTestId('booking-sign').filter({ hasText: 'Abzüge' }).click()
  await expect(booking).toHaveCount(0)
  for (const row of await bookings.all()) {
    await expect(row).toContainText('−')
  }

  await page.getByTestId('booking-sign').filter({ hasText: 'Alles' }).click()
  await expect(booking).toContainText('+3')
})

/**
 * The same extra stars straight from a child's column in the tasks, so a
 * parent need not switch areas. The PIN is asked when saving, and the typed
 * reason survives it. In a household of its own: the column counts the
 * child's whole day, and parallel tests book for the demo children.
 */
test('gives extra stars from the task column of a child', async ({ page }) => {
  const household = ownHousehold('Ida')

  try {
    await pair(page, household)
    await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()

    const column = page.locator('[data-testid="day-column"][aria-label="Ida"]')
    await expect(column).toBeVisible()
    await expect(column.getByTestId('column-stars')).toHaveCount(0)

    await column.getByTestId('column-bonus').click()

    const bonus = page.getByTestId('bonus-dialog')
    await expect(bonus).toBeVisible()
    await bonus.getByTestId('bonus-reason').fill(`E2E Tisch abgeräumt ${Date.now()}`)
    await bonus.getByTestId('bonus-save').click()

    const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
    await expect(pin).toBeVisible()
    for (const rank of [0, 1, 2, 3]) {
      await pin.getByTestId('pin-key').nth(rank).click()
    }

    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/wall/points') && response.status() === 201),
      pin.getByRole('button', { name: 'OK' }).click(),
    ])

    await expect(bonus).toBeHidden()

    // The column counts the extra star, as the stars area does.
    await expect(column.getByTestId('column-stars')).toHaveAttribute('aria-label', /\b1\b/)
  }
  finally {
    dropHousehold(household)
  }
})

/**
 * A sibling gives stars, booked by a parent from the receiver's star-plus:
 * at most what the giver has, and both see it in the history. In a
 * household of its own, because it counts what each child has.
 */
test('gives stars from one child to another', async ({ page }) => {
  const household = ownHousehold('Ida')

  try {
    tinker(
      `$lia = App\\Models\\Person::withoutHouseholdScope()->create(["household_id" => ${household}, "name" => "Lia", "role" => "child", "birthday" => "2016-05-01", "color" => "#2563eb"]);`
      + ` App\\Models\\PointEntry::withoutHouseholdScope()->create(["household_id" => ${household}, "person_id" => $lia->id, "points" => 3, "reason" => "Start"]);`,
    )

    await pair(page, household)
    await page.getByRole('button', { name: 'Sterne', exact: true }).click()
    await page.getByRole('button', { name: 'Extra-Sterne für Ida' }).click()

    const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
    await expect(pin).toBeVisible()
    for (const rank of [0, 1, 2, 3]) {
      await pin.getByTestId('pin-key').nth(rank).click()
    }
    await pin.getByRole('button', { name: 'OK' }).click()

    const bonus = page.getByTestId('bonus-dialog')
    await bonus.locator('[data-testid="bonus-mode"][data-value="gift"]').click()
    await expect(bonus.getByRole('heading')).toHaveText('Sterne für Ida')
    await expect(bonus.getByTestId('bonus-save')).toBeDisabled()

    await bonus.getByTestId('gift-from').filter({ hasText: 'Lia' }).click()
    await bonus.getByTestId('gift-all').click()
    await expect(bonus.getByTestId('gift-count')).toHaveText('3')
    await expect(bonus.getByTestId('gift-more')).toBeDisabled()
    await bonus.getByTestId('gift-less').click()
    await bonus.getByTestId('gift-note').fill('fürs Kino')

    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/wall/points/gift') && response.status() === 201),
      bonus.getByTestId('bonus-save').click(),
    ])
    await expect(bonus).toBeHidden()

    await page.getByTestId('bookings-toggle').click()
    await expect(page.locator('li').filter({ hasText: 'Geschenk von Lia: fürs Kino' })).toContainText('+2')
    await expect(page.locator('li').filter({ hasText: 'Geschenk an Ida: fürs Kino' })).toContainText('−2')
  }
  finally {
    dropHousehold(household)
  }
})

/**
 * With a child tapped, every reward shows how far its stars already reach.
 */
test('shows the progress of the chosen child on every reward', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Sterne', exact: true }).click()

  const rewards = page.getByTestId('reward')
  await expect(rewards.first()).toBeVisible()
  await expect(page.getByTestId('reward-progress')).toHaveCount(0)

  await page.getByTestId('points-person').first().click()
  await expect(page.getByTestId('reward-progress')).toHaveCount(await rewards.count())
  await expect(page.getByTestId('reward-progress').first()).toContainText('/')
})

/**
 * A child asks for a reward without the PIN: tap yourself, tap the card. Only
 * deciding on the request is for the parents — declining here also leaves
 * the demo data as it was.
 */
test('a child requests a reward without the parent PIN', async ({ page }) => {
  await pair(page)
  await page.getByRole('button', { name: 'Sterne', exact: true }).click()

  const affordable = page.getByTestId('reward').and(page.locator(':enabled'))
  const people = page.getByTestId('points-person')
  await expect(people.first()).toBeVisible()

  // Whoever has enough stars for something; the demo data decides who.
  for (const person of await people.all()) {
    await person.click()
    if (await affordable.count() > 0) {
      break
    }
    await person.click()
  }

  const card = affordable.first()
  const name = (await card.locator('span.font-display').innerText()).trim()

  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/rewards/') && response.request().method() === 'POST' && response.ok()),
    card.click(),
  ])

  await expect(page.getByRole('dialog', { name: 'Eltern-PIN' })).toBeHidden()

  const request = page.getByTestId('request').filter({ hasText: `„${name}“` })
  await expect(request).toBeVisible()

  // The tap answers where the child looks, beside their balance — the list
  // above sits below the cards. Other tests' requests may add to the sum.
  const chosen = people.and(page.locator('[aria-pressed="true"]'))
  await expect(chosen.getByTestId('requested')).toContainText('−')

  await request.getByRole('button', { name: 'Ablehnen' }).click()
  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()
  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }
  await pin.getByRole('button', { name: 'OK' }).click()

  await expect(request).toBeHidden()
})

/**
 * A request decided on a phone leaves the wall at its next sync. Locally
 * there is no Reverb, so the five-minute cycle stands in for the
 * announcement — both run the same refresh. The requests sit below the
 * rewards they were asked from.
 */
test('drops a request decided elsewhere at the next sync', async ({ page }) => {
  await page.clock.install()
  await pair(page)
  await page.getByRole('button', { name: 'Sterne', exact: true }).click()

  const affordable = page.getByTestId('reward').and(page.locator(':enabled'))
  const people = page.getByTestId('points-person')
  await expect(people.first()).toBeVisible()

  for (const person of await people.all()) {
    await person.click()
    if (await affordable.count() > 0) {
      break
    }
    await person.click()
  }

  const card = affordable.first()
  const name = (await card.locator('span.font-display').innerText()).trim()

  const [response] = await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/rewards/') && response.request().method() === 'POST' && response.ok()),
    card.click(),
  ])
  const { id } = await response.json() as { id: number }

  const request = page.getByTestId('request').filter({ hasText: `„${name}“` })
  await expect(request).toBeVisible()

  const rewardsBox = await page.getByRole('heading', { name: 'Belohnungen', exact: true }).boundingBox()
  const requestsBox = await page.getByRole('heading', { name: 'Offene Anfragen' }).boundingBox()
  expect(requestsBox!.y).toBeGreaterThan(rewardsBox!.y)

  tinker(`App\\Models\\RewardRedemption::withoutHouseholdScope()->findOrFail(${id})->forceFill(["rejected_at" => now()])->save();`)

  await Promise.all([
    page.waitForResponse(response => response.url().includes('/api/wall/rewards') && response.request().method() === 'GET'),
    page.clock.runFor(5 * 60 * 1000),
  ])

  await expect(request).toBeHidden()
})

/**
 * Two switches per tablet: colourful menu icons and animations. Both are
 * plain preferences, so no PIN.
 */
test('draws the stars filled and golden when asked', async ({ page }) => {
  await pair(page)

  const dialog = await deviceSettings(page, 'appearance')

  for (const key of ['star_filled', 'star_color']) {
    const toggle = dialog.locator(`[data-testid="preference"][data-cell="${key}"]`)
    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      toggle.click(),
    ])
  }

  await page.keyboard.press('Escape')
  await page.keyboard.press('Escape')
  await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()

  const star = page.getByTestId('star').first()
  await expect(star).toHaveAttribute('data-filled', 'on')
  await expect(star).toHaveAttribute('data-color', 'on')
  await expect(star).toHaveAttribute('fill', 'currentColor')
})

test('switches to colourful menu icons and stills animations', async ({ page }) => {
  await pair(page)
  await expect(page.getByTestId('mode-emoji')).toHaveCount(0)
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'on')

  const dialog = await deviceSettings(page, 'appearance')
  const icons = dialog.locator('[data-testid="preference"][data-cell="colored_icons"]')
  await expect(icons).toHaveAttribute('aria-checked', 'false')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    icons.click(),
  ])
  await expect(page.getByTestId('mode-emoji').first()).toBeVisible()

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    dialog.locator('[data-testid="preference"][data-cell="animations"]').click(),
  ])
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off')
  await expect(icons).toHaveAttribute('aria-checked', 'true')
})

/** Glass is the tablet's choice; the gradient behind it is the household's. */
test('switches to the glass look over the household gradient', async ({ page }) => {
  await pair(page)
  await expect(page.locator('html')).toHaveAttribute('data-look', 'classic')
  await expect(page.locator('html')).toHaveAttribute('data-backdrop', /.+/)

  const dialog = await deviceSettings(page, 'appearance')
  const glass = dialog.locator('[data-testid="preference"][data-cell="look"]')
  await expect(glass).toHaveAttribute('aria-checked', 'false')

  await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
    glass.click(),
  ])
  await expect(page.locator('html')).toHaveAttribute('data-look', 'glass')
  await expect(glass).toHaveAttribute('aria-checked', 'true')
  expect(await page.locator('html').evaluate(html => getComputedStyle(html).backgroundImage)).toContain('radial-gradient')
})

/*
 * The weather answer is fixed here: the demo household has no place, and a
 * real forecast would change under the test. Without the service worker,
 * because it sits between page and network and page.route would miss.
 */
test.describe('weather', () => {
  test.use({ serviceWorkers: 'block' })

  const hours = (date: string, from: number) => Array.from({ length: 24 - from }, (_, index) => ({
    time: `${String(from + index).padStart(2, '0')}:00`,
    temperature: 12 + index,
    code: 0,
    label: 'Klar',
    symbol: '☀️',
    rain: index === 2 ? 30 : 0,
  }))

  const day = (date: string, from: number) => ({
    date, min: 9, max: 23, code: 0, label: 'Klar', symbol: '☀️', sunshine: 11, rain: 0, hours: hours(date, from),
  })

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/wall/weather', route => route.fulfill({
      json: {
        weather: {
          current: 14, min: 9, max: 23, code: 0, label: 'Klar', symbol: '☀️', unit: 'C',
          today: day('2026-09-30', 14),
          days: [day('2026-10-01', 0), day('2026-10-02', 0), day('2026-10-03', 0)],
        },
        hint: null,
      },
    }))
  })

  test('opens the hours of a day from the weather tile', async ({ page }) => {
    await pair(page)
    await page.getByRole('button', { name: 'Start', exact: true }).click()

    await page.getByTestId('weather-open').click()
    const dialog = page.getByTestId('weather-dialog')

    // Today from the current hour on, not from midnight.
    await expect(dialog.getByTestId('weather-hour').first()).toContainText('14:00')
    await expect(dialog.getByTestId('weather-hour')).toHaveCount(10)
    await expect(dialog.getByTestId('weather-tab').first()).toHaveAttribute('aria-selected', 'true')

    await dialog.getByTestId('weather-tab').nth(2).click()
    await expect(dialog.getByTestId('weather-hour')).toHaveCount(24)

    // A mouse drags the hours along; there is no scrollbar to grab.
    const strip = dialog.getByTestId('weather-hours')
    const before = await strip.evaluate(element => element.scrollLeft)
    const box = (await strip.boundingBox())!
    await page.mouse.move(box.x + box.width - 40, box.y + 40)
    await page.mouse.down()
    await page.mouse.move(box.x + 40, box.y + 40, { steps: 5 })
    await page.mouse.up()
    expect(await strip.evaluate(element => element.scrollLeft)).toBeGreaterThan(before)

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)

    // A coming day opens on that day.
    await page.getByTestId('weather-day').nth(1).click()
    await expect(dialog.getByTestId('weather-tab').nth(2)).toHaveAttribute('aria-selected', 'true')
  })

  test('closes the weather window when the wall rests', async ({ page }) => {
    await page.clock.install()
    await pair(page)

    const settings = await deviceSettings(page, 'rest')

    // Resting needs an area to show; without one it stays off.
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      settings.locator('[data-testid="rest-area"][data-value="calendar"]').click(),
    ])
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      settings.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
    ])
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    await page.getByRole('button', { name: 'Start', exact: true }).click()
    await page.getByTestId('weather-open').click()
    await expect(page.getByTestId('weather-dialog')).toBeVisible()

    await page.clock.runFor(61_000)
    await expect(page.getByTestId('screensaver')).toBeVisible()
    await expect(page.getByTestId('weather-dialog')).toHaveCount(0)
  })

  test('shows the weather in place of the mark at the top of the menu', async ({ page }) => {
    await pair(page)
    await expect(page.getByTestId('menu-weather')).toHaveCount(0)

    const settings = await deviceSettings(page, 'start')
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/preferences') && response.request().method() === 'PUT'),
      settings.locator('[data-testid="preference"][data-cell="menu_top"][data-value="weather"]').click(),
    ])
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    const top = page.getByTestId('menu-weather')
    await expect(top).toContainText('14°')
    await expect(top).toContainText('23°')
    await expect(top).toContainText('9°')

    await top.click()
    await expect(page.getByTestId('weather-dialog')).toBeVisible()
  })
})

/*
 * Whoever sits at a keyboard types the PIN: digits, Backspace to correct,
 * Enter to confirm — the same path as the keys on screen.
 */
test('takes the parent PIN from the keyboard', async ({ page }) => {
  await pair(page)

  await page.getByTestId('theme').click()
  await page.getByTestId('unpair').click()
  await page.getByTestId('unpair').click()

  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()

  const filled = pin.getByTestId('pin-display').locator('.bg-ink')

  await page.keyboard.type('129')
  await expect(filled).toHaveCount(3)
  await page.keyboard.press('Backspace')
  await expect(filled).toHaveCount(2)
  await expect(pin.getByTestId('pin-ok')).toBeDisabled()

  await page.keyboard.type('34')
  await expect(pin.getByTestId('pin-ok')).toBeEnabled()
  await page.keyboard.press('Enter')

  await expect(page.getByRole('heading', { name: 'Willkommen bei Tagwerk' })).toBeVisible()
})

/*
 * A browser holding a tablet's cookie is always the tablet. Unpairing is the
 * way out — behind the parent PIN and two taps — and ends on the start page.
 */
test('unpairs the tablet behind the PIN and shows the start page', async ({ page }) => {
  await pair(page)

  await page.getByTestId('theme').click()
  await page.getByTestId('unpair').click()
  await expect(page.getByTestId('unpair')).toHaveText('Wirklich entkoppeln?')
  await page.getByTestId('unpair').click()

  // The keys are laid out 1 to 9 in this order; the PIN is 1234.
  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })
  await expect(pin).toBeVisible()

  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }

  await pin.getByRole('button', { name: 'OK' }).click()

  await expect(page.getByRole('heading', { name: 'Willkommen bei Tagwerk' })).toBeVisible()

  // Still the start page after a reload: the cookie is gone, not just hidden.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Willkommen bei Tagwerk' })).toBeVisible()
})
