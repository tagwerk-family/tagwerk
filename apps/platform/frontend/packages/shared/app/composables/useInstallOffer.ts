import { installWay, isIos, snoozeOver } from '../utils/installOffer'
import type { InstallWay } from '../utils/installOffer'

/** Chromium's install event; not in the DOM typings. */
export interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/**
 * The "install as app" band on wall and phone.
 *
 * The browser's event arrives once, often before any component exists — the
 * plugin `install.client.ts` catches it and hands it over via `held`.
 * "Later" is a per-device convenience in `localStorage`, not state: after
 * seven days the band comes back as long as the app is still not installed.
 */
const SNOOZE_KEY = 'fahoba-install-snoozed'

export function useInstallOffer() {
  const held = useState<InstallPromptEvent | null>('install-prompt', () => null)
  const installed = useState<boolean>('install-done', () => runsInstalled())
  const snoozed = useState<boolean>('install-snoozed', () => !snoozeOver(snoozedAt(), Date.now()))

  const way = computed<InstallWay>(() => installWay({
    installed: installed.value,
    prompt: held.value !== null,
    ios: isIos(navigator.userAgent, navigator.maxTouchPoints),
  }))

  const shown = computed(() => way.value !== null && !snoozed.value)

  async function install(): Promise<void> {
    const event = held.value

    if (event === null) {
      return
    }

    // The event can prompt only once; a refusal waits for "later" like a tap on it.
    held.value = null
    await event.prompt()

    if ((await event.userChoice).outcome === 'dismissed') {
      later()
    }
  }

  function later(): void {
    snoozed.value = true

    try {
      localStorage.setItem(SNOOZE_KEY, String(Date.now()))
    }
    catch {
      // Without storage the band only stays away until the next start.
    }
  }

  return { way, shown, held, installed, install, later }
}

function snoozedAt(): number | null {
  try {
    const stored = localStorage.getItem(SNOOZE_KEY)

    return stored === null ? null : Number(stored)
  }
  catch {
    // Blocked storage: better not to nag on every start.
    return Date.now()
  }
}

/**
 * Started from the home screen, or in a kiosk browser — Fully on the wall
 * has nothing to install.
 */
function runsInstalled(): boolean {
  return ['standalone', 'fullscreen', 'minimal-ui'].some(mode => window.matchMedia(`(display-mode: ${mode})`).matches)
    || Reflect.get(navigator, 'standalone') === true
    || Reflect.get(globalThis, 'fully') !== undefined
}
