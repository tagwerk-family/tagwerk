import { readFileSync } from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { dropHousehold, ownHousehold, pair, tinker } from './helpers'

/*
 * A language from a package (fahoba/example-language, development only):
 * the first text on screen is already in it. What the pack lacks shows in
 * English, so the check is that nothing German slipped in before — that
 * would be the flash of the default language this is all about.
 *
 * A household of its own: its language changes for the test.
 */

type Messages = { [key: string]: string | Messages }

function flatten(messages: Messages): string[] {
  return Object.values(messages).flatMap(value => typeof value === 'string' ? [value] : flatten(value))
}

const shared = new URL('../../shared/i18n/locales/', import.meta.url).pathname
const english = new Set(flatten(JSON.parse(readFileSync(`${shared}en.json`, 'utf8')) as Messages))

// German texts that differ from English and are long enough not to hide in other words.
const germanOnly = flatten(JSON.parse(readFileSync(`${shared}de.json`, 'utf8')) as Messages)
  .filter(text => !english.has(text) && text.length >= 8 && !/[{}]/.test(text))

/** Records every text added to the page from its first script on. */
async function recordTexts(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const seen: string[] = []
    Object.assign(window, { __texts: seen })

    new MutationObserver((records) => {
      for (const record of records) {
        record.addedNodes.forEach((node) => {
          const text = node.textContent?.trim()

          if (text) {
            seen.push(text)
          }
        })
      }
    }).observe(document, { childList: true, subtree: true, characterData: true })
  })
}

async function germanShown(page: Page): Promise<string[]> {
  const texts = await page.evaluate(() => (window as unknown as { __texts: string[] }).__texts.join('\n'))

  return germanOnly.filter(text => texts.includes(text))
}

test.describe('a language from a pack', () => {
  test.use({ locale: 'fr-FR' })

  test('the start page speaks the browser’s language from its first text', async ({ page }) => {
    await recordTexts(page)
    await page.goto('/')

    await expect(page.getByRole('heading', { name: 'Bienvenue sur Fahoba' })).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    expect(await germanShown(page)).toEqual([])
  })
})

test.describe('a wall in a French household', () => {
  let household: number

  test.beforeAll(() => {
    household = ownHousehold('Léa')
  })

  test.afterAll(() => {
    dropHousehold(household)
  })

  test('restarts in French, online and offline', async ({ page, context }) => {
    await pair(page, household)
    tinker(`App\\Models\\Household::findOrFail(${household})->update(["locale" => "fr"]);`)

    await recordTexts(page)
    await page.reload()
    await expect(page.getByTestId('dashboard-grid')).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    expect(await germanShown(page)).toEqual([])

    // A kiosk after a power cut without network: the kept answer holds.
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null)
    await context.setOffline(true)
    await page.reload()
    await expect(page.getByTestId('dashboard-grid')).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    expect(await germanShown(page)).toEqual([])
    await context.setOffline(false)
  })
})
