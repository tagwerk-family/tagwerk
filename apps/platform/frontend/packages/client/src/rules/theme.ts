import type { Design } from './design.ts'

export type Theme = 'light' | 'dark'

export type Choice = 'auto' | Theme

/** The response of GET /api/theme. */
export interface ThemeState {
  preference: Choice
  mode: 'fixed' | 'sun'
  theme: Theme
  light_from: string
  dark_from: string
  next_change: string | null
  /** The household's gradient behind the glass look. */
  backdrop: string
  /** An installed design pack's stylesheet; null is Fahoba's own. */
  design?: Design | null
  /** Every installed design, for the settings. */
  designs?: Array<Pick<Design, 'key' | 'name'>>
}

/** Longest delay setTimeout honours — beyond it, it fires immediately. */
export const LONGEST_ALARM = 2 ** 31 - 1

/*
 * Grace period after the change: if the interface asked to the millisecond,
 * the server might still be before the boundary.
 */
const TAIL = 1000

const DAY_MS = 24 * 60 * 60 * 1000

/** Milliseconds until asking again; null when no change is scheduled. */
export function alarmIn(state: ThemeState, now: number): number | null {
  if (state.next_change === null) {
    return null
  }

  const wait = Date.parse(state.next_change) - now + TAIL

  return Math.min(Math.max(wait, 0), LONGEST_ALARM)
}

/**
 * The change when the server does not answer.
 *
 * Once the scheduled moment has passed, the theme flips, and the next change
 * is extrapolated from the opposite moment of the last known day. With the
 * sun position that shifts by a few minutes a day — accurate enough without
 * a network, and the server's next answer corrects it.
 */
export function withoutServer(state: ThemeState, now: number): ThemeState {
  if (state.next_change === null || Date.parse(state.next_change) > now) {
    return state
  }

  const theme: Theme = state.theme === 'dark' ? 'light' : 'dark'

  let next = Date.parse(theme === 'light' ? state.dark_from : state.light_from)

  while (next <= now) {
    next += DAY_MS
  }

  return { ...state, theme, next_change: new Date(next).toISOString() }
}
