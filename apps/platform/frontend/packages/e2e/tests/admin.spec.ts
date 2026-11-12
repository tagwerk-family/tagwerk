import { expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { adminSession, createAccount } from './helpers'

/*
 * The admin carries the wall's mark, fonts and colours. Checked on computed
 * styles instead of image comparisons: those stay stable across font
 * smoothing and browser versions.
 */

type Rgb = [number, number, number]

/**
 * Filament computes colours in oklch. A canvas pixel turns that into RGB,
 * which can be compared with the wall's hex values.
 */
async function color(location: Locator, property: 'backgroundColor' | 'color'): Promise<Rgb> {
  return location.evaluate((element, name) => {
    const drawing = document.createElement('canvas').getContext('2d')

    if (!drawing) {
      throw new Error('No canvas available.')
    }

    drawing.fillStyle = getComputedStyle(element)[name]
    drawing.fillRect(0, 0, 1, 1)

    const [red, green, blue] = drawing.getImageData(0, 0, 1, 1).data

    return [red, green, blue] as Rgb
  }, property)
}

function hex(value: string): Rgb {
  return [1, 3, 5].map(position => Number.parseInt(value.slice(position, position + 2), 16)) as Rgb
}

/** oklch with three decimals hits the hex value within a few steps. */
function expectColor(actual: Rgb, expected: string): void {
  const target = hex(expected)

  actual.forEach((channel, index) => {
    expect(Math.abs(channel - target[index]), `${actual.join(',')} statt ${expected}`).toBeLessThanOrEqual(3)
  })
}

async function loadedFonts(page: Page): Promise<string[]> {
  return page.evaluate(async () => {
    await document.fonts.ready

    return [...document.fonts].filter(font => font.status === 'loaded').map(font => font.family)
  })
}

test('login carries the logo, fonts and colours of the wall', async ({ page }) => {
  await page.goto('/admin/login')

  await expect(page.locator('img.fi-logo[src$="/logo-light.svg"]')).toBeVisible()
  await expect(page.locator('img.fi-logo[src$="/logo-dark.svg"]')).toBeHidden()

  await expect(page).toHaveTitle(/Family Home Base/)
  await expect(page.locator('link[rel=icon]')).toHaveAttribute('href', /\/favicon\.svg$/)

  const icon = await page.request.get('/favicon.svg')
  expect(icon.headers()['content-type']).toContain('image/svg+xml')

  const fonts = await loadedFonts(page)
  expect(fonts.join('|')).toContain('Plus Jakarta Sans Variable')

  expectColor(await color(page.locator('body'), 'backgroundColor'), '#f6f6f4')
  expectColor(await color(page.locator('.fi-simple-main button[type=submit]'), 'backgroundColor'), '#c2410c')
})

test.describe('in the dark theme', () => {
  test.use({ colorScheme: 'dark' })

  test('uses the deep neutral grey of the wall', async ({ page }) => {
    await page.goto('/admin/login')

    await expect(page.locator('html')).toHaveClass(/\bdark\b/)
    expectColor(await color(page.locator('body'), 'backgroundColor'), '#101012')
    await expect(page.locator('img.fi-logo[src$="/logo-dark.svg"]')).toBeVisible()
    await expect(page.locator('img.fi-logo[src$="/logo-light.svg"]')).toBeHidden()
  })
})

/** The form sign-in itself — once per run, see adminSession(). */
async function adminLogin(page: Page): Promise<void> {
  const account = createAccount()

  await page.goto('/admin/login')
  await page.locator('input[type=email]').fill(account.email)
  await page.locator('input[type=password]').fill(account.password)
  await page.locator('.fi-simple-main button[type=submit]').click()
  await expect(page.locator('.fi-header-heading')).toBeVisible()
}

test('after login the logo and heading follow the wall style', async ({ page }) => {
  await adminLogin(page)

  const heading = page.locator('.fi-header-heading')
  await expect(heading).toBeVisible()
  await expect(page.getByRole('img', { name: 'Logo von Family Home Base' }).first()).toBeVisible()

  const font = await heading.evaluate(element => getComputedStyle(element).fontFamily)
  expect(font).toContain('Plus Jakarta Sans Variable')
})

/*
 * Creating runs through a Livewire update. If the household is missing
 * there, saving fails with 500 — the page itself still loads.
 */
test('creates a device', async ({ page }) => {
  await adminSession(page)

  await page.goto('/admin/devices/create')
  await page.getByLabel('Bezeichnung').fill('E2E')
  await page.getByRole('button', { name: 'Erstellen', exact: true }).click()

  // Saving leads back to the list, where the pairing code stands too.
  await expect(page).toHaveURL(/\/admin\/devices$/)
  await expect(page.locator('.fi-ta-table').getByText('E2E', { exact: true })).toBeVisible()
})

test('recurrence is described readably', async ({ page }) => {
  await adminSession(page)

  await page.goto('/admin/tasks/create')
  // Filament draws toggle buttons as a label in front of a hidden radio or checkbox.
  await page.getByText('Wöchentlich', { exact: true }).click()
  await page.getByText('Mo', { exact: true }).click()
  await page.getByText('Do', { exact: true }).click()

  await expect(page.locator('.fi-callout').getByText('Jeden Montag und Donnerstag')).toBeVisible()
  await expect(page.locator('.fi-callout').getByText(/Nächste Termine:/)).toBeVisible()
})

test('toggle switches light and dark and remembers the choice', async ({ page }) => {
  await adminSession(page)

  const toggle = page.getByRole('radiogroup', { name: 'Erscheinungsbild' })
  const html = page.locator('html')

  await toggle.getByRole('radio', { name: 'Immer dunkel' }).click()
  await expect(html).toHaveClass(/\bdark\b/)
  await expect(toggle.getByRole('radio', { name: 'Immer dunkel' })).toHaveAttribute('aria-checked', 'true')

  await page.reload()
  await expect(html).toHaveClass(/\bdark\b/)

  await toggle.getByRole('radio', { name: 'Immer hell' }).click()
  await expect(html).not.toHaveClass(/\bdark\b/)
})

/*
 * Plugin keys carry a slash (`fahoba/bring`), and so do the wizard's field
 * paths. Livewire tests do not render the browser side; this does. Sent
 * without a password on purpose: the email must arrive and pass, only the
 * password is missing — and nothing goes out to Bring.
 */
test('connection wizard carries the fields of a plugin', async ({ page }) => {
  await adminSession(page)
  await page.goto('/admin/connections')

  await page.getByRole('button', { name: 'Dienstverknüpfung hinzufügen' }).click()

  const wizard = page.getByRole('dialog')
  await wizard.getByText('Listen', { exact: true }).click()
  await wizard.getByRole('button', { name: 'Weiter' }).click()
  await wizard.getByText('Bring!', { exact: true }).click()
  await wizard.getByRole('button', { name: 'Weiter' }).click()

  await wizard.getByLabel('E-Mail').fill('e2e@example.org')
  await wizard.getByRole('button', { name: 'Verbinden' }).click()

  const password = wizard.locator('.fi-fo-field').filter({ has: page.getByLabel('Passwort') })
  const email = wizard.locator('.fi-fo-field').filter({ has: page.getByLabel('E-Mail') })

  await expect(password.locator('.fi-fo-field-wrp-error-message, [data-validation-error]')).toBeVisible()
  await expect(email.locator('.fi-fo-field-wrp-error-message, [data-validation-error]')).toHaveCount(0)
})
