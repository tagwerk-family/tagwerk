/*
 * Service worker of the app, for wall and phone.
 *
 * Two jobs. The wall's ground rule from section 8 of the specification:
 * there must never be an empty page. If the server fails, the last successful
 * response is passed on — marked, so the interface shows "Stand von 14:32"
 * instead of pretending to be current. And the phone's notifications: without
 * a service worker there is no web push while the page is closed.
 *
 * Deliberately hand-written: @vite-pwa/nuxt requires @nuxt/kit ^3, and two
 * caches are not worth a dependency with a wrong version range.
 */

const CACHE = 'fahoba-wall-v1'
const AGENDA = '/api/wall/agenda'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(['/']))
      .then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names.filter((name) => name !== CACHE).map((name) => caches.delete(name)),
      ))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request

  if (request.method !== 'GET') {
    return
  }

  if (new URL(request.url).pathname.startsWith(AGENDA)) {
    event.respondWith(agenda(request))

    return
  }

  /*
   * Only the app itself. /admin lives at the same address — its pages must
   * never end up in the cache as the app's shell.
   */
  if (request.mode === 'navigate' && new URL(request.url).pathname === '/') {
    event.respondWith(shell(request))
  }

  /*
   * Everything else stays untouched. A cache over Vite's module URLs would
   * break reloading on changes in development, and in the built version the
   * file names already carry a checksum.
   */
})

async function agenda(request) {
  const cache = await caches.open(CACHE)

  try {
    const response = await fetch(request)

    if (response.ok) {
      /*
       * Key without the query: the shown range changes when paging, but
       * what should be kept is simply the last known state. After a cold
       * start without network the range may therefore differ from the one
       * requested — better than an empty page.
       */
      cache.put(AGENDA, response.clone())
    }

    return response
  }
  catch (error) {
    const stale = await cache.match(AGENDA)

    if (!stale) {
      throw error
    }

    const headers = new Headers(stale.headers)
    headers.set('X-Fahoba-Cache', 'hit')

    return new Response(await stale.blob(), { status: 200, headers })
  }
}

async function shell(request) {
  const cache = await caches.open(CACHE)

  try {
    const response = await fetch(request)

    if (response.ok) {
      cache.put('/', response.clone())
    }

    return response
  }
  catch (error) {
    const stale = await cache.match('/')

    if (!stale) {
      throw error
    }

    return stale
  }
}

self.addEventListener('push', (event) => {
  /*
   * Show something even without a payload: some services deliver
   * notifications without content, and a silent push counts as abuse in
   * Chrome and costs the permission.
   */
  let data = { title: 'Fahoba', body: 'Es gibt Neuigkeiten.' }

  if (event.data) {
    try {
      data = { ...data, ...event.data.json() }
    }
    catch {
      data.body = event.data.text()
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      /*
       * PNG, not SVG: Android ignores SVG here and draws a grey square. The
       * badge in the status bar is read only by its alpha channel — the
       * coloured, opaque icon came out as a filled square, so it is the
       * mark alone in white on transparent.
       */
      icon: '/icon-192.png',
      badge: '/badge-96.png',
      data: { url: data.url || '/' },

      /*
       * The same tag replaces the previous notification instead of stacking
       * it: five times "Einkaufsliste geändert" is no information. Per
       * household, so one family's news does not hide the other's.
       */
      tag: (data.tag || 'fahoba') + (data.household_id ? `-${data.household_id}` : ''),
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const target = new URL(event.notification.data?.url || '/', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      // An already open window is brought to the front instead of opening a
      // second one -- otherwise tabs of the same app pile up.
      for (const open of windows) {
        if (open.url === target && 'focus' in open) {
          return open.focus()
        }
      }

      return self.clients.openWindow(target)
    }),
  )
})
