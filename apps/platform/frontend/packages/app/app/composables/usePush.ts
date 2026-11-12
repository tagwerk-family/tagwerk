/**
 * The browser's web push subscription.
 *
 * Converting the VAPID key lives in app/utils/push.ts so it can be tested
 * without a Nuxt environment.
 */
export function usePush() {
  const { t } = useI18n()

  const allowed = useState<boolean>('push-allowed', () => false)
  const possible = useState<boolean>('push-possible', () => false)
  // Shared: the first-start offer and the settings show the same outcome.
  const status = useState<{ ok: boolean, text: string } | null>('push-status', () => null)
  const busy = useState<boolean>('push-busy', () => false)
  const offered = useState<boolean>('push-offer', () => false)

  function supported(): boolean {
    return typeof window !== 'undefined'
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && 'Notification' in window
  }

  async function check(): Promise<void> {
    if (!supported()) {
      return
    }

    const { configured } = await $fetch<{ configured: boolean, public_key: string | null }>(
      '/api/push/key',
      { baseURL: '/', credentials: 'same-origin' },
    ).catch(() => ({ configured: false, public_key: null }))

    possible.value = configured

    const registration = await navigator.serviceWorker.ready
    const existing = await registration.pushManager.getSubscription()

    allowed.value = existing !== null
    offered.value = configured && existing === null && Notification.permission === 'default' && !askedBefore()

    /*
     * The browser keeps its subscription even when the server has dropped
     * it (an expired endpoint, a reset database): the button then said "on"
     * while nothing arrived. Telling the server again on every start is
     * harmless — it keys on the endpoint.
     */
    if (existing && configured) {
      await register(existing).catch(() => {})
    }
  }

  /** `confirm` asks the server for a sample notification to this phone. */
  async function register(subscription: PushSubscription, confirm = false): Promise<void> {
    const data = subscription.toJSON()

    await $fetch('/api/push/subscribe', {
      method: 'POST',
      body: {
        endpoint: data.endpoint,
        keys: { p256dh: data.keys?.p256dh, auth: data.keys?.auth },
        confirm,
      },
      baseURL: '/',
      credentials: 'same-origin',
    })
  }

  /*
   * Switching takes a moment — permission prompt, push service, server — so
   * both directions run behind `busy` and end in a `status` the UI shows.
   * Errors end there too, never as an unhandled rejection: a silent tap is
   * exactly what made the switch feel broken.
   */
  async function run(change: () => Promise<{ ok: boolean, text: string }>): Promise<boolean> {
    busy.value = true
    status.value = null

    try {
      status.value = await change()
    }
    catch {
      status.value = { ok: false, text: t('push.failed') }
    }
    finally {
      busy.value = false
    }

    return status.value?.ok ?? false
  }

  function enable(): Promise<boolean> {
    return run(async () => {
      if (!supported()) {
        return { ok: false, text: t('push.unsupported') }
      }

      const { configured, public_key: key } = await $fetch<{
        configured: boolean
        public_key: string | null
      }>('/api/push/key', { baseURL: '/', credentials: 'same-origin' })

      if (!configured || !key) {
        return { ok: false, text: t('push.notConfigured') }
      }

      // Asked from a tap only; once denied, the browser never asks again.
      if (await Notification.requestPermission() !== 'granted') {
        return { ok: false, text: t('push.denied') }
      }

      const registration = await navigator.serviceWorker.ready
      const signIn = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyAsBytes(key),
      })

      await register(signIn, true)
      allowed.value = true

      return { ok: true, text: t('push.enabled') }
    })
  }

  function disable(): Promise<boolean> {
    return run(async () => {
      const registration = await navigator.serviceWorker.ready
      const signIn = await registration.pushManager.getSubscription()

      if (signIn) {
        // Unsubscribe from the server first, then from the browser: the other
        // way round the endpoint would be gone before the server knows it.
        await $fetch('/api/push/subscribe', {
          method: 'DELETE',
          body: { endpoint: signIn.endpoint },
          baseURL: '/',
          credentials: 'same-origin',
        }).catch(() => {})

        await signIn.unsubscribe()
      }

      allowed.value = false

      return { ok: true, text: t('push.disabled') }
    })
  }

  /*
   * Asked once per phone after signing in, so nobody has to find the switch
   * in the settings. The browser only shows its own prompt for a tap, which
   * is why this is a sheet with a button and not a call on start. Whatever
   * the answer, the sheet does not come back — the settings keep the switch.
   */
  const ASKED_KEY = 'fahoba-push-asked'

  function askedBefore(): boolean {
    try {
      return localStorage.getItem(ASKED_KEY) !== null
    }
    catch {
      // Blocked storage: better not to ask on every start.
      return true
    }
  }

  // Remembered at the first tap, not on closing: an app killed meanwhile
  // must not ask again.
  function remember(): void {
    try {
      localStorage.setItem(ASKED_KEY, '1')
    }
    catch {
      // Without storage the sheet only stays away until the next start.
    }
  }

  function accept(): Promise<boolean> {
    remember()

    return enable()
  }

  function closeOffer(): void {
    remember()
    offered.value = false
  }

  return { allowed, possible, status, busy, offered, check, enable, disable, accept, closeOffer }
}
