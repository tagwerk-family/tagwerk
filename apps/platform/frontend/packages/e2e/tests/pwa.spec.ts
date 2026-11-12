import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { pair } from './helpers'

/*
 * The app as an installed PWA: what the service worker (`app/public/sw.js`)
 * promises beyond a page that is open. Manifest, icons and the install band
 * are covered in mobile.spec.ts, the scope against /admin in
 * addresses.spec.ts, a server lost while the page runs in wall.spec.ts.
 */

/** Pairs and waits until the service worker controls the page and holds both caches. */
async function pairWithCache(page: Page): Promise<void> {
  await pair(page)

  /*
   * The first agenda request may run before the worker has claimed the page
   * and so bypass it. A reload under control caches the shell and the
   * agenda the way a tablet does after its first restart.
   */
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready
  })
  await page.reload()
  await expect(page.getByTestId('dashboard-grid')).toBeVisible()

  await expect.poll(() => page.evaluate(async () => {
    const shell = await caches.match('/')
    const agenda = await caches.match('/api/wall/agenda')

    return shell !== undefined && agenda !== undefined
  })).toBe(true)
}

test('a wall that starts without network comes up as a kiosk with its last state', async ({ page }) => {
  await pairWithCache(page)

  /*
   * A power cut with the router still down: the page loads anew, with
   * neither server nor network. Shell and agenda come from the worker, the
   * entry from `fahoba-entry` — otherwise the tablet would start on the
   * pairing screen and look unpaired.
   */
  await page.context().setOffline(true)
  await page.reload()

  await expect(page.getByRole('heading', { name: 'Willkommen bei Family Home Base' })).toBeHidden()
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()

  await expect(page.getByTestId('event').first()).toBeVisible()
  await expect(page.getByText(/Stand von \d{2}:\d{2} Uhr/).first()).toBeVisible()

  // Back online, the next fetch replaces the cached state and its marker.
  await page.context().setOffline(false)
  await page.getByRole('button', { name: 'Woche' }).click()
  await expect(page.getByText(/Stand von \d{2}:\d{2} Uhr/)).toHaveCount(0)
})

/**
 * Delivers push messages through the DevTools protocol, like the "Push"
 * button there: no push service and no VAPID keys involved, only the
 * worker's own `push` handler — which is what decides how the notification
 * looks.
 */
async function pushChannel(page: Page): Promise<(data: string) => Promise<void>> {
  const context = page.context()
  await context.grantPermissions(['notifications'], { origin: 'https://fahoba.test' })
  await pairWithCache(page)

  const cdp = await context.newCDPSession(page)
  const registration = new Promise<string>((resolve) => {
    cdp.on('ServiceWorker.workerRegistrationUpdated', ({ registrations }) => {
      const ours = registrations.find(entry => entry.scopeURL === 'https://fahoba.test/' && !entry.isDeleted)

      if (ours) {
        resolve(ours.registrationId)
      }
    })
  })
  await cdp.send('ServiceWorker.enable')
  const registrationId = await registration

  return async (data: string) => {
    await cdp.send('ServiceWorker.deliverPushMessage', { origin: 'https://fahoba.test', registrationId, data })
  }
}

/** The notifications the worker currently shows. */
function shown(page: Page) {
  return page.evaluate(async () => {
    const notifications = await (await navigator.serviceWorker.ready).getNotifications()

    // `badge` is missing from TypeScript's DOM types, Chromium has it.
    return (notifications as Array<Notification & { badge: string }>).map(note => ({
      title: note.title,
      body: note.body,
      tag: note.tag,
      icon: new URL(note.icon).pathname,
      badge: new URL(note.badge).pathname,
      url: (note.data as { url: string }).url,
    }))
  })
}

test('a push message becomes a notification with the app icon and badge', async ({ page }) => {
  const push = await pushChannel(page)

  await push(JSON.stringify({ title: 'Einkaufsliste', body: 'Milch dazugekommen', tag: 'lists', url: '/?area=lists' }))

  await expect.poll(() => shown(page)).toEqual([{
    title: 'Einkaufsliste',
    body: 'Milch dazugekommen',
    tag: 'lists',
    icon: '/icon-192.png',
    badge: '/badge-96.png',
    url: '/?area=lists',
  }])

  // The same tag replaces instead of stacking.
  await push(JSON.stringify({ title: 'Einkaufsliste', body: 'Brot dazugekommen', tag: 'lists' }))

  await expect.poll(async () => (await shown(page)).map(note => note.body)).toEqual(['Brot dazugekommen'])
})

test('a push message without JSON still shows a notification', async ({ page }) => {
  const push = await pushChannel(page)

  /*
   * Some push services deliver plain text, and a push that shows nothing
   * counts as silent in Chrome — which costs the permission.
   */
  await push('Es gibt Neuigkeiten in der Einkaufsliste.')

  await expect.poll(() => shown(page)).toEqual([{
    title: 'Family Home Base',
    body: 'Es gibt Neuigkeiten in der Einkaufsliste.',
    tag: 'fahoba',
    icon: '/icon-192.png',
    badge: '/badge-96.png',
    url: '/',
  }])
})
