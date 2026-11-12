import { expect, test } from '@playwright/test'
import { dropHousehold, ownHousehold, pair, tinker } from './helpers'

/*
 * A plugin's area: the example plugin (development only) brings a menu
 * entry, a view from its own Nuxt layer and an API route. Switched off for
 * the household, it leaves the menu. A household of its own: switching off
 * in the demo household would surprise the parallel wall tests.
 */
let household: number

test.beforeAll(() => {
  household = ownHousehold('Mia')
})

test.afterAll(() => {
  dropHousehold(household)
})

test('a plugin area appears in the menu and shows its view', async ({ page }) => {
  await pair(page, household)

  await page.getByTestId('mode').filter({ hasText: 'Beispiel' }).click()

  await expect(page.getByTestId('example-area')).toContainText('Hallo, Familie E2E')
})

test('switched off, the area leaves the menu', async ({ page }) => {
  await pair(page, household)
  await expect(page.getByTestId('mode').filter({ hasText: 'Beispiel' })).toBeVisible()

  tinker(`App\\Models\\Household::findOrFail(${household})->update(["disabled_features" => ["fahoba/example-area"]]);`)
  await page.reload()

  await expect(page.getByTestId('dashboard-grid')).toBeVisible()
  await expect(page.getByTestId('mode').filter({ hasText: 'Beispiel' })).toHaveCount(0)

  tinker(`App\\Models\\Household::findOrFail(${household})->update(["disabled_features" => []]);`)
})

/*
 * A plugin tile with settings and buttons (the example plugin's counter):
 * the form comes from the fields the plugin declares, a tap answers with
 * new content.
 */
test('a plugin tile is configured and its buttons act', async ({ page }) => {
  await pair(page, household)
  await page.getByRole('button', { name: 'Start' }).click()
  await expect(page.getByTestId('dashboard-grid')).toBeVisible()

  await page.getByTestId('dashboard-edit').click()
  const pin = page.getByRole('dialog', { name: 'Eltern-PIN' })

  for (const rank of [0, 1, 2, 3]) {
    await pin.getByTestId('pin-key').nth(rank).click()
  }

  await pin.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByTestId('dashboard-edit')).toHaveAttribute('aria-pressed', 'true')

  await page.getByTestId('widget-picker').click()
  await page.getByTestId('widget-offer').filter({ hasText: 'Zähler' }).click()

  const tile = page.locator('[data-testid="dashboard-tile"][data-type="fahoba/example-counter"]')
  await expect(tile).toHaveCount(1)

  await tile.getByTestId('tile-settings-open').click()
  const form = page.getByTestId('tile-settings')
  await form.locator('[data-field="name"]').fill('Becher')
  await form.locator('[data-field="step"]').selectOption('5')
  await form.getByTestId('tile-settings-save').click()
  await expect(form).toHaveCount(0)

  await page.getByTestId('dashboard-edit').click()
  await expect(tile).toContainText('Becher: 0')

  await tile.getByTestId('widget-action').filter({ hasText: '5 dazu' }).click()
  await expect(tile).toContainText('Becher: 5')

  await tile.getByTestId('widget-action').filter({ hasText: 'Zurücksetzen' }).click()
  await expect(tile).toContainText('Becher: 0')
})
