import { expect, test } from '@playwright/test'

/*
 * Two applications live side by side under https://fahoba.test: the app
 * (wall and phone) at the root and Laravel for /api and /admin. These tests
 * check the split itself — that the Laravel welcome page does not answer
 * again and one does not get in the other's way.
 */

test('/ serves the wall instead of the Laravel start page', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'Willkommen bei Family Home Base' })).toBeVisible()
  await expect(page.getByText('Laravel')).toHaveCount(0)
})

test('/ offers the phone sign-in to whoever is not a tablet, and back', async ({ page }) => {
  await page.goto('/')

  await page.getByRole('button', { name: /Am Handy anmelden/ }).click()

  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByTestId('login')).toBeVisible()

  await page.getByTestId('entry-back').click()

  await expect(page.getByRole('heading', { name: 'Willkommen bei Family Home Base' })).toBeVisible()
})

test('/admin stays the admin panel although the wall registers a service worker', async ({ page }) => {
  await page.goto('/')
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready
  })

  await page.goto('/admin')

  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.locator('input[type=email]')).toBeVisible()

  /*
   * The wall's service worker stores the last loaded page as the offline
   * shell. It used to intercept every navigation — then the admin's sign-in
   * would show here, and a tablet without a network would display it.
   */
  const shell = await page.evaluate(async () => {
    const response = await caches.match('/')

    return response ? response.text() : ''
  })

  // The app's shell, recognised by its theme script (a file since the CSP).
  expect(shell).toContain('/theme.js')
  expect(shell).not.toContain('filament')
})

test('/api stays with Laravel', async ({ request }) => {
  const response = await request.get('/api/theme', { headers: { Accept: 'application/json' } })

  expect(response.status()).toBe(401)
  expect(response.headers()['content-type']).toContain('application/json')
})

test('shell and service worker apply immediately after a build', async ({ request }) => {
  for (const path of ['/', '/sw.js']) {
    const response = await request.get(path)

    expect(response.status(), path).toBe(200)
    expect(response.headers()['cache-control'], path).toBe('no-cache')
  }
})
