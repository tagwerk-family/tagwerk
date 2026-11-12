import { type Theme, type ThemeState, type Choice, withoutServer, alarmIn } from '../utils/theme'
import { type Design, DESIGN_STORAGE } from '../utils/design'

/** The same key as in the layer's head script (nuxt.config.ts). */
const STORAGE = 'fahoba-theme'

/*
 * One alarm for the whole application, not one per call: if header and
 * settings both call useTheme(), two alarms must not ask side by side.
 */
let alarm: ReturnType<typeof setTimeout> | null = null

/**
 * Light and dark theme.
 *
 * When to switch is computed by the server (clock time or sun position in
 * the household's time zone). The interface only sets an alarm for the next
 * change and then asks again. If there is no network then, it switches by
 * itself — the wall should not stay light at night just because the Wi-Fi
 * hangs.
 */
export function useTheme() {
  const state = useState<ThemeState | null>('theme', () => null)

  function apply(theme: Theme, backdrop: string): void {
    const root = document.documentElement

    root.dataset.theme = theme
    root.dataset.backdrop = backdrop

    // The phone's status bar follows the background, otherwise a light bar
    // would sit above a dark page.
    const canvas = getComputedStyle(root).getPropertyValue('--canvas').trim()

    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', canvas)

    try {
      localStorage.setItem(STORAGE, theme)
    }
    catch (error: unknown) {
      // Without storage (private mode) the system theme only flashes briefly on
      // reload; switching itself is not affected.
      console.info('Theme not saved:', error)
    }
  }

  /**
   * A design pack is one stylesheet beside the core's; `data-design` lets
   * its rules apply. Remembered, so `theme.js` loads it before the first
   * paint on the next start.
   */
  function applyDesign(design: Design | null): void {
    const root = document.documentElement
    let link = document.getElementById('fahoba-design') as HTMLLinkElement | null

    if (design === null) {
      delete root.dataset.design
      link?.remove()
    }
    else {
      if (link === null) {
        link = document.createElement('link')
        link.id = 'fahoba-design'
        link.rel = 'stylesheet'
        document.head.appendChild(link)
      }

      if (link.getAttribute('href') !== design.url) {
        link.setAttribute('href', design.url)
      }

      root.dataset.design = design.key
    }

    try {
      if (design === null) {
        localStorage.removeItem(DESIGN_STORAGE)
      }
      else {
        localStorage.setItem(DESIGN_STORAGE, JSON.stringify(design))
      }
    }
    catch (error: unknown) {
      console.info('Design not saved:', error)
    }
  }

  function plan(): void {
    if (alarm !== null) {
      clearTimeout(alarm)
      alarm = null
    }

    const wait = state.value ? alarmIn(state.value, Date.now()) : null

    if (wait !== null) {
      alarm = setTimeout(load, wait)
    }
  }

  function adopt(fresh: ThemeState): void {
    state.value = fresh
    apply(fresh.theme, fresh.backdrop)
    applyDesign(fresh.design ?? null)
    plan()
  }

  async function load(): Promise<void> {
    try {
      adopt(await $fetch<ThemeState>('/api/theme', { baseURL: '/', credentials: 'same-origin' }))
    }
    catch {
      if (state.value) {
        adopt(withoutServer(state.value, Date.now()))
      }
    }
  }

  /** Returns whether the choice was stored. */
  async function choose(choice: Choice): Promise<boolean> {
    try {
      adopt(await $fetch<ThemeState>('/api/theme', {
        method: 'PUT',
        body: { preference: choice },
        baseURL: '/', credentials: 'same-origin',
      }))

      return true
    }
    catch {
      return false
    }
  }

  function end(): void {
    if (alarm !== null) {
      clearTimeout(alarm)
      alarm = null
    }
  }

  return { state, load, choose, end }
}
