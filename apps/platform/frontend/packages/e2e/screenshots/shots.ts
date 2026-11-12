import { expect } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { tinker } from '../tests/helpers'

export const OUT = new URL('../../../../../../docs/screenshots/', import.meta.url).pathname

/** Lets loads, fades and pictures settle before the shutter. */
export async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(800)
  // The demo household's Google sample is expired on purpose (an e2e test
  // needs the banner); a real household shows it only when it is true. The
  // household notice says the same since notices exist.
  await page.getByRole('status').filter({ hasText: 'Zugang abgelaufen' })
    .or(page.getByTestId('household-notice'))
    .evaluateAll(banners => {
      for (const banner of banners) {
        (banner as HTMLElement).style.display = 'none'
      }
    })
}

/** Light or dark for this device or account, stored like a tap in the settings would. */
export async function theme(page: Page, value: 'Hell' | 'Dunkel', open = (p: Page) => p.getByTestId('theme').click()): Promise<void> {
  await open(page)
  const sheet = page.getByTestId('settings-sheet')
  await sheet.locator('[data-testid="settings-page"][data-value="appearance"]').click()
  await Promise.all([
    page.waitForResponse(r => r.url().endsWith('/api/theme') && r.request().method() === 'PUT'),
    page.getByRole('radio', { name: value }).click(),
  ])
  await expect(page.locator('html')).toHaveAttribute('data-theme', value === 'Hell' ? 'light' : 'dark')
  while (await sheet.count() > 0) {
    await page.keyboard.press('Escape')
  }
}

/** The phone's menu slides in; taps mid-slide land on moving elements. */
export async function menu(page: Page): Promise<Locator> {
  const drawer = page.getByTestId('menu')

  await slid(page)

  if (!(await drawer.isVisible())) {
    await page.getByTestId('menu-open').click()
  }

  await expect(drawer).toBeVisible()
  await slid(page)

  return drawer
}

async function slid(page: Page): Promise<void> {
  await page.waitForFunction(() => [...document.querySelectorAll('nav, [data-testid="menu-scrim"]')]
    .every(element => element.getAnimations().length === 0))
}

const DEMO = 'App\\Models\\Household::orderBy("id")->first()'

/**
 * The demo household has no location, so its weather tile shows a hint.
 * For the pictures it borrows Vienna's, and gives it back afterwards.
 */
export function borrowLocation(): void {
  tinker(`${DEMO}->forceFill(["latitude" => 48.21, "longitude" => 16.37])->saveQuietly();`)
}

export function returnLocation(): void {
  tinker(`${DEMO}->forceFill(["latitude" => null, "longitude" => null])->saveQuietly();`)
}

/**
 * Mid-morning of the household's today, whatever time the run starts: the
 * demo events sit around the day the seeder ran, and in the evening the
 * calendar shows mostly what is over.
 */
export async function morning(page: Page): Promise<void> {
  const zone = 'Europe/Vienna'
  const day = new Intl.DateTimeFormat('en-CA', { timeZone: zone }).format(new Date())
  const offset = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'longOffset' })
    .formatToParts(new Date()).find(part => part.type === 'timeZoneName')?.value.replace('GMT', '') ?? ''
  await page.clock.setFixedTime(new Date(`${day}T09:30:00${offset || '+00:00'}`))
}
