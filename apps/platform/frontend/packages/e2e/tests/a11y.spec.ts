import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { pair } from './helpers'

/*
 * Every area through axe, on the wall and on the phone. Only serious and
 * critical findings fail: a missing label, a button without a name, text
 * nobody can read. People tap the wall in passing, and some of them use a
 * screen reader on the phone.
 */
const AREAS = ['Start', 'Kalender', 'Aufgaben', 'Listen', 'Sterne', 'Essen', 'Rezepte', 'Schule', 'Fotos']

async function findings(page: Page, where: string): Promise<string[]> {
  const result = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    /*
     * A task row is named by its title; the line below it (daypart,
     * recurrence) describes, it does not label. axe counts that line as
     * part of the visible label, WCAG 2.5.3 does not.
     */
    .exclude('[data-testid="column-open-task"]')
    .analyze()

  const serious = result.violations
    .filter(violation => violation.impact === 'serious' || violation.impact === 'critical')
    .map(violation => `${where} ${violation.id}: ${violation.nodes.map(node => node.target.join(' ')).slice(0, 3).join(', ')}`)

  return serious
}

test('the start page', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Willkommen bei Fahoba' })).toBeVisible()

  expect(await findings(page, 'start page')).toEqual([])
})

test('every area on the wall', async ({ page }) => {
  await pair(page)
  const found: string[] = []

  // All areas before failing: one finding should not hide the next.
  for (const area of AREAS) {
    await page.getByTestId('mode').filter({ hasText: area }).click()
    await expect(page.locator('main [data-testid="loading"]')).toHaveCount(0)
    found.push(...await findings(page, area))
  }

  expect(found).toEqual([])
})
