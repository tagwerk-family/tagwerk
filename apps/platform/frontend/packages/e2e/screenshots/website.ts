import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { devices, expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { adminSession, createAccount, login, openSignIn, pair, tinker } from '../tests/helpers'
import { borrowLocation, menu, morning, returnLocation, settle, theme } from './shots'

/*
 * Pictures for the marketing website:
 *   WEBSITE_SHOTS=<dir>/ pnpm exec playwright test -c screenshots.config.ts website.ts
 * The website repository is expected beside this one (WEBSITE_SHOTS overrides).
 * Photos come from PHOTO_DIR (the credited Wikimedia files of the website's
 * CREDITS.md, kept in storage/app/website-photos, not in Git); afterAll
 * removes the rows again.
 */
const OUT = process.env.WEBSITE_SHOTS ?? new URL('../../../../../../../tagwerk-website/src/assets/screenshots/', import.meta.url).pathname
const PHOTO_DIR = process.env.PHOTO_DIR ?? new URL('../../../../storage/app/website-photos/', import.meta.url).pathname

const PHOTOS: Array<[string, string]> = [
  ['bergsee', 'Bergsee'],
  ['lakekinney', 'Lake Kinney'],
  ['herbstabend', 'Herbstabend'],
  ['apfelbluete', 'Apfelblüte'],
  ['strand', 'Abend am Strand'],
  ['heide', 'Heide'],
  ['sonnenuntergang', 'Sonnenuntergang am See'],
]

const REPO = new URL('../../../../../..', import.meta.url).pathname
const WALL = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 }

/** The dev-only example plugin adds a menu entry a real household does not have. */
async function hideExample(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Beispiel', exact: true }).evaluateAll(buttons => {
    for (const button of buttons) {
      (button as HTMLElement).style.display = 'none'
    }
  })
}

/** The demo household's expired Google sample shows a notice bar in the admin; a real one would not. */
async function hideNoticeBar(page: Page): Promise<void> {
  await page.evaluate(() => {
    const all = [...document.querySelectorAll('body *')] as HTMLElement[]
    const hit = all.filter(el => el.children.length === 0 || el.tagName === 'SPAN').find(el => el.textContent?.includes('Verbindung zu Google Kalender'))
    let node: HTMLElement | null = hit ?? null
    while (node && node.parentElement && node.parentElement !== document.body) {
      const box = node.getBoundingClientRect()
      const parent = node.parentElement.getBoundingClientRect()
      if (box.width >= window.innerWidth - 2 && parent.height > box.height + 40) break
      node = node.parentElement
    }
    if (node) node.style.display = 'none'
  })
}

test.beforeAll(borrowLocation)
test.afterAll(returnLocation)

/*
 * The phone upload answers 500 for these full-size pictures in DDEV (php-fpm
 * dies without a log line), so the rows are made the way wall.spec.ts makes
 * its own: the file goes through PhotoImage, the row carries a marker
 * fingerprint, and afterAll removes them again.
 */
const MARKER = 'e2e-site-'

function removePhotos(): void {
  tinker(
    'App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->where("fingerprint", "like", "' + MARKER + '%")'
    + '->get()->each(function ($photo) { $photo->forceDelete(); });',
  )
}

test.beforeAll(() => {
  removePhotos()

  for (const [file, caption] of PHOTOS) {
    execFileSync('ddev', ['exec', 'cat > /tmp/site-photo.jpg'], { cwd: REPO, input: readFileSync(`${PHOTO_DIR}${file}.jpg`) })
    tinker(
      '$content = file_get_contents("/tmp/site-photo.jpg");'
      + ' App\\Models\\Photo::withoutGlobalScope("household")->forceCreate(['
      + '"household_id" => App\\Models\\Household::orderBy("id")->first()->id,'
      + ' "path" => app(App\\Services\\PhotoImage::class)->store($content),'
      + ` "caption" => "${caption}", "fingerprint" => "${MARKER}${file}"]);`,
    )
  }
})
test.afterAll(removePhotos)

test.describe('wall', () => {
  test.use(WALL)

  for (const mode of ['Hell', 'Dunkel'] as const) {
    const suffix = mode === 'Hell' ? '' : '-dark'

    test(`wall ${mode}`, async ({ page }) => {
      test.setTimeout(120_000)
      await morning(page)
      await pair(page)
      await theme(page, mode)
      const shot = async (name: string): Promise<void> => {
        await settle(page)
        await hideExample(page)
        await page.screenshot({ path: `${OUT}${name}${suffix}.png` })
      }
      const area = (name: string) => page.getByRole('button', { name, exact: true }).click()

      await area('Start')
      await expect(page.getByTestId('dashboard-grid')).toBeVisible()
      await shot('wall-dashboard')

      await area('Kalender')
      await expect(page.getByTestId('event').first()).toBeVisible()
      await shot('wall-calendar-3day')

      await area('Aufgaben')
      await expect(page.getByTestId('day-column').first()).toBeVisible()
      await expect(page.getByTestId('column-open').first()).toBeVisible()
      await shot('wall-tasks')

      await area('Essen')
      await expect(page.getByTestId('meal').first()).toBeVisible()
      await shot('wall-meals')

      await area('Listen')
      await shot('wall-lists')

      await area('Schule')
      await shot('wall-timetable')

      await area('Fotos')
      await expect(page.getByTestId('photo-frame')).toBeVisible()
      await page.waitForFunction(() => [...document.querySelectorAll('[data-testid="photo-frame"] img')].some(i => (i as HTMLImageElement).complete && (i as HTMLImageElement).naturalWidth > 0), undefined, { polling: 500 })
      await page.waitForTimeout(2500)
      await shot('wall-photos')

      await area('Sterne')
      await expect(page.getByTestId('reward').first()).toBeVisible()
      await shot('wall-points')

      if (mode === 'Hell') {
        // The shared task, asked who did it. Nothing is saved: the dialog is cancelled.
        await area('Aufgaben')
        await page.getByTestId('day-column').last().getByRole('button', { name: /Geschirrspüler ausräumen/ }).click()
        await page.getByTestId('task-details-complete').click()
        await expect(page.getByTestId('completed-person').first()).toBeVisible()
        await settle(page)
        await hideExample(page)
        await page.screenshot({ path: `${OUT}wall-task-who-picker.png` })
        await page.getByRole('button', { name: 'Abbrechen' }).click()
      }
    })
  }

  test('wall screensaver', async ({ page }) => {
    test.setTimeout(120_000)
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Vienna' }).format(new Date())
    const offset = new Intl.DateTimeFormat('en-US', { timeZone: 'Europe/Vienna', timeZoneName: 'longOffset' })
      .formatToParts(new Date()).find(part => part.type === 'timeZoneName')?.value.replace('GMT', '') ?? '+00:00'
    await page.clock.install({ time: new Date(`${day}T09:30:00${offset}`) })
    await pair(page)
    await page.getByTestId('theme').click()
    const dialog = page.getByTestId('settings-sheet')
    await dialog.locator('[data-testid="settings-page"][data-value="rest"]').click()

    for (const rest of await dialog.getByTestId('rest-area').all()) {
      const photos = await rest.getAttribute('data-value') === 'photos'
      const pressed = await rest.getAttribute('aria-pressed') === 'true'

      if (photos !== pressed) {
        await Promise.all([
          page.waitForResponse(r => r.url().endsWith('/api/preferences') && r.request().method() === 'PUT'),
          rest.click(),
        ])
      }
    }

    await Promise.all([
      page.waitForResponse(r => r.url().endsWith('/api/preferences') && r.request().method() === 'PUT'),
      dialog.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
    ])
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    await page.clock.runFor(61_000)
    await expect(page.getByTestId('screensaver')).toBeVisible()
    await expect(page.getByTestId('photo-clock')).toBeVisible()
    await page.waitForFunction(() => [...document.querySelectorAll('[data-testid="photo-frame"] img')].some(i => (i as HTMLImageElement).complete && (i as HTMLImageElement).naturalWidth > 0), undefined, { polling: 500 })
    await page.waitForTimeout(2500)
    await page.waitForTimeout(1500)
    await page.screenshot({ path: `${OUT}wall-screensaver.png` })
  })
})

test.describe('wall portrait', () => {
  test.use({ viewport: { width: 800, height: 1280 }, deviceScaleFactor: 2 })

  test('portrait dashboard', async ({ page }) => {
    await morning(page)
    await pair(page)
    await page.getByRole('button', { name: 'Start', exact: true }).click()
    await expect(page.getByTestId('dashboard-grid')).toBeVisible()
    await settle(page)
    await hideExample(page)
    await page.screenshot({ path: `${OUT}wall-dashboard-portrait.png` })
  })
})

test.describe('phone', () => {
  test.use({ userAgent: devices['Pixel 7'].userAgent, isMobile: true, hasTouch: true, viewport: { width: 390, height: 794 }, deviceScaleFactor: 2.775 })

  test('phone', async ({ page }) => {
    test.setTimeout(120_000)
    await morning(page)
    await openSignIn(page)
    await login(page, createAccount())

    const visit = async (area: string, name: string, suffix = ''): Promise<void> => {
      await (await menu(page)).getByTestId('tab').filter({ hasText: area }).click()
      await settle(page)
      await page.screenshot({ path: `${OUT}${name}${suffix}.png` })
    }
    const mode = (value: 'Hell' | 'Dunkel') => theme(page, value, async (p: Page) => {
      await menu(p)
      await p.getByTestId('settings').click()
    })

    await mode('Hell')
    await visit('Start', 'phone-dashboard')
    await visit('Listen', 'phone-lists')
    await visit('Essen', 'phone-meals')
    await visit('Kalender', 'phone-calendar')
    await mode('Dunkel')
    await visit('Kalender', 'phone-calendar', '-dark')
  })
})

test.describe('admin', () => {
  test.use({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 })

  test('admin', async ({ page }) => {
    test.setTimeout(120_000)
    // The demo has no rotating task; one gets a turn order for the picture and is put back after.
    const original = tinker(
      '$t = App\\Models\\Task::withoutGlobalScope("household")->where("title", "Müll rausbringen")->firstOrFail();'
      + ' echo "id=" . $t->id . ";person=" . json_encode($t->person_id) . ";rotation=" . json_encode($t->rotation);',
    )
    const id = original.match(/id=(\d+)/)![1]
    const person = original.match(/person=(\S*?);/)![1]
    const rotation = original.match(/rotation=(.*)$/m)![1]

    try {
      tinker(
        `$t = App\\Models\\Task::withoutGlobalScope("household")->findOrFail(${id});`
        + ' $order = App\\Models\\Person::withoutGlobalScope("household")->where("household_id", $t->household_id)->where("role", "!=", "guest")->orderBy("id")->pluck("id")->all();'
        + ' $t->forceFill(["person_id" => $order[0], "rotation" => $order])->saveQuietly();',
      )
      await adminSession(page)
      await settle(page)
      await hideNoticeBar(page)
      await page.screenshot({ path: `${OUT}admin-overview.png` })

      await page.goto('/admin/tasks')
      await expect(page.locator('.fi-ta-row').first()).toBeVisible()
      await settle(page)
      await hideNoticeBar(page)
      await page.screenshot({ path: `${OUT}admin-tasks.png` })

      await page.goto(`/admin/tasks/${id}/edit`)
      await expect(page.getByText('Abwechselnd', { exact: true })).toBeVisible()
      await settle(page)
      await page.getByText('Abwechselnd', { exact: true }).first().scrollIntoViewIfNeeded()
      await page.evaluate(() => window.scrollBy(0, 200))
      await hideNoticeBar(page)
      await page.waitForTimeout(300)
      await page.screenshot({ path: `${OUT}admin-task-edit.png` })
    }
    finally {
      tinker(
        `$t = App\\Models\\Task::withoutGlobalScope("household")->findOrFail(${id});`
        + ` $t->forceFill(["person_id" => ${person}, "rotation" => json_decode("${rotation.replaceAll('"', '\\"')}", true)])->saveQuietly();`,
      )
    }
  })
})

/*
 * The tour video: a paired wall, recorded at 1280x800. What it ticks off is
 * taken out of the demo household again afterwards.
 */
test.describe('tour video', () => {
  test.use({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 })

  test('tour video', async ({ browser }) => {
    test.setTimeout(180_000)
    const target = process.env.TOUR_WEBM ?? '/home/apo/.claude/jobs/b3b442a9/tmp/tour.webm'
    const options = { baseURL: 'https://fahoba.test', ignoreHTTPSErrors: true, timezoneId: 'Europe/Vienna', locale: 'de-AT', viewport: { width: 1280, height: 800 } }

    // Pair off camera, then record with the device's cookie.
    const setup = await browser.newContext(options)
    const first = await setup.newPage()
    await morning(first)
    await pair(first)
    const state = await setup.storageState()
    await setup.close()

    const before = tinker(
      'echo "c=" . App\\Models\\TaskCompletion::withoutGlobalScope("household")->max("id") . ";p=" . App\\Models\\PointEntry::withoutGlobalScope("household")->max("id");',
    )
    const completionFloor = Number(before.match(/c=(\d*)/)?.[1] || 0)
    const pointFloor = Number(before.match(/p=(\d*)/)?.[1] || 0)

    const context = await browser.newContext({ ...options, storageState: state, recordVideo: { dir: '/home/apo/.claude/jobs/b3b442a9/tmp/rec', size: { width: 1280, height: 800 } } })
    const page = await context.newPage()

    try {
      await morning(page)
      await page.goto('/')
      await expect(page.getByTestId('dashboard-grid')).toBeVisible()
      await settle(page)
      await page.waitForTimeout(3500)

      await page.getByRole('button', { name: 'Aufgaben', exact: true }).click()
      await expect(page.getByTestId('day-column').first()).toBeVisible()
      await page.waitForTimeout(2000)

      const max = page.getByTestId('day-column').filter({ hasText: 'Max' }).first()

      for (const title of [/Tisch abräumen/, /Katze füttern/]) {
        await max.getByRole('button', { name: title }).click()
        await page.waitForTimeout(900)
        await page.getByTestId('task-details-complete').click()
        await page.waitForTimeout(2800)
      }

      const toggle = async (topic: string): Promise<ReturnType<Page['getByTestId']>> => {
        await page.getByTestId('theme').click()
        const sheet = page.getByTestId('settings-sheet')
        await sheet.locator(`[data-testid="settings-page"][data-value="${topic}"]`).click()
        await page.waitForTimeout(700)

        return sheet
      }

      let sheet = await toggle('appearance')
      await sheet.locator('[data-testid="preference"][data-cell="colored_icons"]').click()
      await page.waitForTimeout(1800)
      await page.keyboard.press('Escape')
      await page.keyboard.press('Escape')
      await page.waitForTimeout(1500)

      sheet = await toggle('start')
      await sheet.locator('[data-testid="preference"][data-cell="menu_top"][data-value="weather"]').click()
      await page.waitForTimeout(1800)
      await page.keyboard.press('Escape')
      await page.keyboard.press('Escape')
      await page.waitForTimeout(1800)

      sheet = await toggle('appearance')
      await page.getByRole('radio', { name: 'Dunkel' }).click()
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
      await page.waitForTimeout(1500)
      await page.keyboard.press('Escape')
      await page.keyboard.press('Escape')
      await page.waitForTimeout(1200)

      await page.getByRole('button', { name: 'Start', exact: true }).click()
      await expect(page.getByTestId('dashboard-grid')).toBeVisible()
      await page.waitForTimeout(3500)
    }
    finally {
      const video = page.video()
      await context.close()
      await video?.saveAs(target)
      tinker(
        `App\\Models\\PointEntry::withoutGlobalScope("household")->where("id", ">", ${pointFloor})->delete();`
        + ` App\\Models\\TaskCompletion::withoutGlobalScope("household")->where("id", ">", ${completionFloor})->delete();`,
      )
    }
  })
})
